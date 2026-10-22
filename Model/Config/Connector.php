<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Config;

use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Acción del botón "Conectar": consulta a Khipu los medios de pago de la cuenta
 * con las credenciales del formulario y, solo si responde bien, guarda las
 * credenciales y la tabla fusionada con lo que el comercio ya tenía.
 */
class Connector
{
    private const TIMEOUT = 10;
    private const EMBEDDED_PATH = 'payment/simplified/embedded_payment';

    public function __construct(
        private readonly Service $service,
        private readonly MethodsTable $methodsTable,
        private readonly MethodsTableMerger $merger,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $khipuLogger
    ) {
    }

    /**
     * @return array tabla guardada
     * @throws LocalizedException
     */
    public function connect(string $apiKey, string $merchantId, string $secret): array
    {
        $apiKey = trim($apiKey);
        $merchantId = trim($merchantId);
        $secret = trim($secret);

        if ($apiKey === '' || $merchantId === '' || $secret === '') {
            throw new LocalizedException(__('Ingresa la API Key, el Merchant_ID y el Secret antes de conectar.'));
        }

        try {
            $deKhipu = $this->service->getPaymentMethods($merchantId, self::TIMEOUT, $apiKey);
        } catch (RefundException $e) {
            $this->khipuLogger->warning(
                'Conectar: no se pudo consultar los medios de pago',
                ['merchant_id' => $merchantId, 'http_code' => $e->getHttpCode(), 'error' => $e->getMessage()]
            );
            throw new LocalizedException(
                __('No pudimos consultar los medios de pago en Khipu: %1', $e->getMessage())
            );
        }

        // Con el campo embebido ya retirado del admin, solo un '0' guardado antes
        // significa que el comercio tenía el embebido apagado.
        $embebido = $this->scopeConfig->getValue(self::EMBEDDED_PATH);
        $modoInicial = $embebido !== null && (string)$embebido === '0' ? 'redirect' : 'embedded';

        $tabla = $this->merger->merge(
            $this->methodsTable->get(),
            $deKhipu,
            $merchantId,
            gmdate(\DATE_ATOM),
            $modoInicial
        );

        $this->configWriter->save('payment/simplified/api_key', $apiKey, 'default', 0);
        $this->configWriter->save('payment/simplified/merchant_id', $merchantId, 'default', 0);
        $this->configWriter->save('payment/simplified/merchant_secret', $secret, 'default', 0);
        $this->methodsTable->save($tabla);
        $this->cacheTypeList->cleanType('config');

        return $tabla;
    }
}
