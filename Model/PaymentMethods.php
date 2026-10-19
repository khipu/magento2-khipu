<?php
declare(strict_types=1);

namespace Khipu\Payment\Model;

use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Responde si la cuenta de cobro configurada tiene transferencia simplificada.
 *
 * El pago embebido (KWS) solo sabe hacer transferencia simplificada y no revisa
 * los medios habilitados de la cuenta: si se abre en una cuenta sin ese medio,
 * muestra bancos que la cuenta tiene desactivados. Esta clase es la protección.
 *
 * Nunca lanza: cualquier falla (HTTP, caché, errores inesperados) se registra
 * y devuelve false, y el checkout usa la redirección, que funciona con cualquier
 * cuenta. Llega a ejecutarse después de que el cobro y el pedido ya existen,
 * por eso ningún error puede escapar de aquí.
 */
class PaymentMethods
{
    public const SIMPLIFIED_TRANSFER = 'SIMPLIFIED_TRANSFER';

    private const MERCHANT_ID_PATH = 'payment/simplified/merchant_id';
    private const CACHE_PREFIX = 'khipu_payment_methods_';
    // Corta a propósito: si el comercio cambia sus medios en Khipu, el checkout lo
    // refleja en a lo más 30 segundos. Sin caché cada pedido sumaría ~1 s de consulta.
    private const CACHE_TTL = 30;
    private const TIMEOUT = 5;

    private Service $service;
    private CacheInterface $cache;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $khipuLogger;

    public function __construct(
        Service $service,
        CacheInterface $cache,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $khipuLogger
    ) {
        $this->service = $service;
        $this->cache = $cache;
        $this->scopeConfig = $scopeConfig;
        $this->khipuLogger = $khipuLogger;
    }

    public function hasSimplifiedTransfer(): bool
    {
        $merchantId = trim((string) $this->scopeConfig->getValue(self::MERCHANT_ID_PATH));
        if ($merchantId === '') {
            $this->khipuLogger->warning('Pago embebido activo sin Merchant_ID configurado; se usará la redirección');
            return false;
        }

        try {
            return $this->consultar($merchantId);
        } catch (\Throwable $e) {
            // Sin caché: el próximo pedido vuelve a intentar.
            $this->khipuLogger->warning('No se pudieron consultar los medios de pago de Khipu; se usará la redirección', [
                'merchant_id' => $merchantId,
                'http_code' => $e instanceof RefundException ? $e->getHttpCode() : 0,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Lee de caché o consulta a Khipu. Puede lanzar; quien la llama debe capturar.
     *
     * @throws \Throwable
     */
    private function consultar(string $merchantId): bool
    {
        $clave = self::CACHE_PREFIX . $merchantId;

        $enCache = $this->cache->load($clave);
        if ($enCache !== false) {
            $ids = json_decode((string) $enCache, true);
            if (is_array($ids)) {
                return in_array(self::SIMPLIFIED_TRANSFER, $ids, true);
            }
        }

        $ids = $this->service->getPaymentMethodIds($merchantId, self::TIMEOUT);

        // Con la etiqueta de configuración, "Flush Magento Cache" también la borra.
        $this->cache->save((string) json_encode($ids), $clave, [ConfigCache::CACHE_TAG], self::CACHE_TTL);

        return in_array(self::SIMPLIFIED_TRANSFER, $ids, true);
    }
}
