<?php
declare(strict_types=1);

namespace Khipu\Payment\Block\Adminhtml\System\Config;

use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Muestra el saldo de la billetera de reversas al final de la configuración de
 * Khipu, con el link de recarga.
 *
 * Existe porque el saldo, si no, solo se consultaría DESPUÉS de reversar (para
 * la heurística del aviso), y el admin necesita verlo ANTES: si la billetera
 * está vacía, la reversa se encola y no se concreta.
 *
 * Cuando la cuenta no tiene el flag habilitado, este bloque es además el único
 * lugar donde el admin lo descubre sin tener que intentar una reversa.
 */
class WalletBalance extends Field
{
    private const CACHE_TTL = 30;

    private Service $refundService;

    public function __construct(Context $context, Service $refundService, array $data = [])
    {
        parent::__construct($context, $data);
        $this->refundService = $refundService;
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->getWalletHtml();
    }

    /** Sin etiqueta ni scope: es informativo, no un campo editable. */
    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="3">' . $this->getWalletHtml() . '</td></tr>';
    }

    public function getWalletHtml(): string
    {
        $titulo = '<h3>' . __('Billetera de reversas') . '</h3>';

        $apiKey = (string) $this->_scopeConfig->getValue('payment/simplified/api_key');
        if ($apiKey === '') {
            return $titulo . '<p>' . __('Complete los datos para poder visualizar la billetera.') . '</p>';
        }

        $cacheKey = 'khipu_wallet_balance_' . md5($apiKey);
        $cached = $this->_cache ? $this->_cache->load($cacheKey) : false;
        if ($cached) {
            return $titulo . $cached;
        }

        try {
            $billetera = $this->refundService->getWalletBalance();
        } catch (RefundException $e) {
            // Solo 'no está habilitada': Khipu cambia el resto de la frase.
            if (stripos($e->getMessage(), 'no está habilitada') !== false) {
                // No se cachea: es un estado que el comercio puede resolver
                // pidiéndole el flag a Khipu, y queremos que se refleje al toque.
                return $titulo . '<p>'
                    . __('La billetera de reversas no está habilitada en tu cuenta Khipu.')
                    . '</p>';
            }
            // Sin respuesta HTTP (red, DNS, timeout): las credenciales no son el
            // problema, y mandar al comercio a revisarlas es mandarlo donde no es.
            if ($e->getHttpCode() === 0) {
                return $titulo . '<p>'
                    . __('No se pudo comunicar con Khipu para consultar el saldo. '
                        . 'Reintenta en unos minutos; si persiste, revisa la conectividad del servidor.')
                    . '</p>';
            }

            // Khipu respondió, pero rechazó la consulta. Las dos causas que el
            // comercio puede resolver por su cuenta son la credencial y la
            // habilitación de la billetera.
            return $titulo . '<p>'
                . __('No se pudo consultar el saldo de la billetera de reversas. '
                    . 'Revisa que la API Key sea correcta y que la billetera de reversas '
                    . 'esté habilitada en tu cuenta Khipu.')
                . '</p>';
        }

        $moneda = (string) ($billetera->currency ?? '');
        $decimales = in_array($moneda, ['CLP', 'COP'], true) ? 0 : ($moneda === 'CLF' ? 4 : 2);

        $html = sprintf(
            '<p><strong>%s</strong> $%s %s &mdash; <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            __('Saldo:'),
            number_format((float) ($billetera->balance ?? 0), $decimales, ',', '.'),
            $this->safeEscapeHtml($moneda),
            $this->safeEscapeUrl((string) ($billetera->add_funds_url ?? '')),
            __('Recargar')
        );

        if ($this->_cache) {
            $this->_cache->save($html, $cacheKey, [], self::CACHE_TTL);
        }

        return $titulo . $html;
    }

    /**
     * Igual que escapeHtml() heredado de AbstractBlock, pero sin explotar si
     * $_escaper no llegó a inicializarse (por ejemplo, en tests unitarios que
     * instancian el bloque con el constructor deshabilitado).
     */
    private function safeEscapeHtml(string $value): string
    {
        return $this->_escaper !== null
            ? $this->escapeHtml($value)
            : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** Análogo a safeEscapeHtml() pero para escapeUrl(). */
    private function safeEscapeUrl(string $value): string
    {
        return $this->_escaper !== null
            ? $this->escapeUrl($value)
            : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
