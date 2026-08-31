<?php

namespace Khipu\Payment\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Khipu\Payment\Model\Refund\ErrorTranslator;
use Khipu\Payment\Model\Refund\NoticeBuilder;
use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service as RefundService;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

class Simplified extends AbstractMethod
{
    const KHIPU_MAGENTO_VERSION = "2.6.0";
    const API_VERSION = "3.0";

    /**
     * Tolerancia al comparar el monto que Khipu dice haber reversado contra el
     * que se pidió. Media unidad de la moneda con más decimales que se maneja
     * (CLF, 4), para absorber la representación decimal y nada más.
     */
    const TOLERANCIA_MONTO = 0.00005;

    protected $_code = 'simplified';
    protected $_isInitializeNeeded = true;
    protected $urlBuilder;
    protected $storeManager;
    protected $orderSender;
    protected $refundService;
    protected $errorTranslator;
    protected $khipuLogger;
    protected $noticeBuilder;
    protected $messageManager;
    protected $_canOrder = true;
    protected $_canAuthorize = true;
    protected $_canUseCheckout = true;
    protected $_canFetchTransactionInfo = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        UrlInterface $urlBuilder,
        StoreManagerInterface $storeManager,
        OrderSender $orderSender,
        RefundService $refundService,
        ErrorTranslator $errorTranslator,
        LoggerInterface $khipuLogger,
        NoticeBuilder $noticeBuilder,
        ManagerInterface $messageManager,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = array()
    )
    {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->urlBuilder = $urlBuilder;
        $this->storeManager = $storeManager;
        $this->orderSender = $orderSender;
        $this->refundService = $refundService;
        $this->errorTranslator = $errorTranslator;
        $this->khipuLogger = $khipuLogger;
        $this->noticeBuilder = $noticeBuilder;
        $this->messageManager = $messageManager;
    }

    public function getKhipuRequest(Order $order)
    {
        $token = substr(md5(rand()), 0, 32);

        $payment = $order->getPayment();
        $payment->setAdditionalInformation('khipu_order_token', $token);
        $payment->save();

        $description = array();
        foreach ($order->getAllItems() as $item) {
            $description[] = number_format($item->getQtyOrdered(), 0) . ' × ' . $item->getName();
        }

        $apiKey = $this->getConfigData('api_key');
        $notifyUrl = $this->urlBuilder->getUrl('khipupayment/payment/callback', array("order_id" => $order->getIncrementId()));
        $payerEmail = $order->getCustomerEmail();

        $paymentData = [
            'amount' => (float)number_format($order->getGrandTotal(), $this->getDecimalPlaces($order->getOrderCurrencyCode()), '.', ''),
            'currency' => $order->getOrderCurrencyCode(),
            'subject' => $this->storeManager->getWebsite()->getName() . ' Carro #' . $order->getIncrementId(),
            'transaction_id' => $order->getIncrementId(),
            'body' => join(', ', $description),
            'custom' => $payment->getAdditionalInformation('khipu_order_token'),
            'return_url' => $this->urlBuilder->getUrl('checkout/onepage/success'),
            'cancel_url' => $this->urlBuilder->getUrl('checkout/onepage/failure'),
            'notify_url' => $notifyUrl,
            'notify_api_version' => '3.0',
            'payer_email' => $payerEmail
        ];

        $ch = curl_init('https://payment-api.khipu.com/v3/payments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, "khipu-api-php-client/" . self::API_VERSION . "|magento2-khipu/" . self::KHIPU_MAGENTO_VERSION);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout in seconds
        curl_setopt($ch, CURLOPT_FAILONERROR, true); // Fail on HTTP error
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL peer verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $msg = "Error de comunicación con Khipu: " . $curlError;
            return ['reason' => $msg, 'status' => false];
        }

        $responseData = json_decode($response, true);
        if (isset($responseData['payment_id'])) {
            $order->setKhipuPaymentId($responseData['payment_id']);
            $order->save();
            return ['status' => true, 'payment_url' => $responseData['simplified_transfer_url']];
        } else {
            $msg = "Error de comunicación con Khipu.\n";
            if (isset($responseData['message'])) {
                $msg .= "Mensaje: " . $responseData['message'] . "\n";
            }

            return ['reason' => $msg, 'status' => false];
        }
    }

    /**
     * Decimales según la moneda. Khipu devuelve los montos con 4 decimales fijos,
     * pero el monto que se le manda debe respetar los de la moneda.
     */
    public function getDecimalPlaces($currencyCode)
    {
        if (in_array($currencyCode, ['CLP', 'COP'], true)) {
            return 0;
        }
        if ($currencyCode === 'CLF') {
            return 4;
        }
        return 2;
    }

    /**
     * Reversa el monto del credit memo contra Khipu.
     *
     * Si lanza LocalizedException, Magento aborta la transacción completa del
     * credit memo: no se crea el asiento, no cambia el estado del pedido, no se
     * muta nada. Ese rollback es justamente lo que queremos ante una reversa
     * fallida — y también es la razón de que el error crudo vaya al log y no a una
     * nota del pedido: la nota se perdería con el rollback.
     *
     * Contrapartida: ese mismo rollback NO puede deshacer lo que ya pasó en
     * Khipu. Khipu no participa de la transacción de BD, y las tres operaciones
     * que Magento hace después de nuestro refund() (guardar el credit memo,
     * guardar el pedido, commit) pueden fallar. Si fallan, el rollback se lleva
     * el credit memo, la nota y la fila de sales_payment_transaction — los
     * cuatro únicos lugares donde quedaba constancia de la reversa. Por eso, en
     * cuanto Khipu responde éxito, se escribe una línea en var/log/khipu.log:
     * Monolog escribe a disco, fuera de la transacción de BD, así que sobrevive
     * al rollback. No hace la operación atómica — es imposible — pero garantiza
     * que nunca se reversen $X sin que quede registro de que se reversaron $X.
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();
        $creditmemo = $payment->getCreditmemo();

        $khipuPaymentId = $payment->getAdditionalInformation('khipu_payment_id')
            ?: $payment->getLastTransId();

        if (!$khipuPaymentId) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Este pedido no tiene un pago de Khipu asociado, así que no se puede '
                . 'reversar desde Magento. Los pedidos pagados antes de esta versión del '
                . 'plugin no registraron ese dato.')
            );
        }

        if ($creditmemo === null) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('No se pudo determinar el monto a reversar.')
            );
        }

        // Magento pasa $amount en moneda BASE, pero el pago se creó en Khipu con la
        // moneda del pedido. Si difieren, el monto base reversaría de más o de menos.
        $currency = $order->getOrderCurrencyCode();
        $montoStr = number_format(
            (float) $creditmemo->getGrandTotal(),
            $this->getDecimalPlaces($currency),
            '.',
            ''
        );

        try {
            $resultado = $this->refundService->refund($khipuPaymentId, 'partial', $montoStr);
        } catch (RefundException $e) {
            $this->khipuLogger->error('Reversa Khipu falló', [
                'payment_id' => $khipuPaymentId,
                'order' => $order->getIncrementId(),
                'amount' => $montoStr,
                'currency' => $currency,
                'http_code' => $e->getHttpCode(),
                'raw_body' => $e->getRawBody(),
            ]);

            throw new \Magento\Framework\Exception\LocalizedException(
                __($this->errorTranslator->toAdminMessage($e))
            );
        }

        // PRIMERO el log, ANTES de tocar nada de Magento: ver el docblock. Este
        // registro es lo único que sobrevive a un rollback posterior.
        $this->khipuLogger->info('Reversa Khipu aceptada por Khipu', [
            'payment_id' => $khipuPaymentId,
            'refund_id' => $resultado->id,
            'order' => $order->getIncrementId(),
            'amount' => $montoStr,
            'currency' => $currency,
            'remaining' => $resultado->remaining,
        ]);

        $this->verificarRespuesta($resultado, $khipuPaymentId, $montoStr, $order->getIncrementId(), $currency);

        // La reversa de Khipu queda como la transacción de refund de Magento: su
        // txn_id es el UUID de la reversa. Como la API no permite consultar
        // reversas después, esta fila es el registro permanente.
        $payment->setTransactionId($resultado->id);
        $payment->setTransactionAdditionalInfo('khipu_refund', (array) $resultado);

        $order->addCommentToStatusHistory(
            $this->noticeBuilder->orderNote($resultado, $currency)
        );

        // La plata ya se movió: si no se puede consultar el saldo, no se hace fallar
        // la reversa ni se revierte el credit memo. Se asume el aviso verde.
        $saldo = null;
        $urlRecarga = null;
        try {
            $billetera = $this->refundService->getWalletBalance();
            $saldo = isset($billetera->balance) ? (float) $billetera->balance : null;
            $urlRecarga = $billetera->add_funds_url ?? null;
        } catch (RefundException $e) {
            $this->khipuLogger->warning('No se pudo consultar el saldo de la billetera', [
                'error' => $e->getMessage(),
            ]);
        }

        $aviso = $this->noticeBuilder->successMessage($resultado, $saldo);

        if ($aviso['tipo'] === 'warning') {
            // addWarningMessage() escapa el HTML del mensaje (el renderer por
            // defecto de Magento es EscapeRenderer, sin allowed tags), así que un
            // <a href> saldría como texto literal y rompería justo el camino
            // amarillo. La URL va en texto plano, y como argumento de la Phrase
            // para que un '%' en el querystring no se interprete como placeholder.
            if ($urlRecarga) {
                $this->messageManager->addWarningMessage(
                    __('%1 Recárgala en: %2', $aviso['texto'], (string) $urlRecarga)
                );
            } else {
                $this->messageManager->addWarningMessage(__($aviso['texto']));
            }
        } else {
            $this->messageManager->addSuccessMessage(__($aviso['texto']));
        }

        return $this;
    }

    /**
     * Contrasta lo que Khipu dice haber hecho contra lo que se le pidió.
     *
     * Un 200 bien formado todavía puede corresponder a otro pago, o reversar un
     * monto distinto del solicitado. Magento crea el credit memo por el monto
     * completo sin mirar la respuesta, así que el pedido quedaría diciendo que
     * devolvió más de lo que devolvió. Ante un desajuste se lanza: el rollback
     * deja el pedido sin credit memo, que es la conducta correcta para un estado
     * que no se entiende. La plata que se haya movido ya quedó en el log.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function verificarRespuesta(
        \stdClass $resultado,
        string $khipuPaymentId,
        string $montoStr,
        $incrementId,
        string $currency
    ) {
        $desajuste = null;

        if ((string) $resultado->payment_id !== (string) $khipuPaymentId) {
            $desajuste = sprintf(
                'la respuesta corresponde al pago %s y se pidió reversar el pago %s',
                (string) $resultado->payment_id,
                $khipuPaymentId
            );
        } elseif (abs((float) $resultado->refunded_amount - (float) $montoStr) > self::TOLERANCIA_MONTO) {
            $desajuste = sprintf(
                'se pidió reversar %s %s y Khipu informa %s',
                $montoStr,
                $currency,
                (string) $resultado->refunded_amount
            );
        }

        if ($desajuste === null) {
            return;
        }

        $this->khipuLogger->error('La respuesta de reversa de Khipu no calza con lo solicitado', [
            'payment_id' => $khipuPaymentId,
            'refund_id' => $resultado->id,
            'order' => $incrementId,
            'amount' => $montoStr,
            'currency' => $currency,
            'respuesta' => (array) $resultado,
            'desajuste' => $desajuste,
        ]);

        throw new \Magento\Framework\Exception\LocalizedException(
            __(
                'Khipu respondió algo distinto de lo que se le pidió (%1). No se creó el '
                . 'credit memo. Revisa var/log/khipu.log y el estado del pago en Khipu '
                . 'antes de reintentar.',
                $desajuste
            )
        );
    }
}
