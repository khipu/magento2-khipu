<?php
namespace Khipu\Payment\Controller\Payment;

use Khipu\Payment\Model\Simplified;
use Khipu\Payment\Model\Refund\CaptureRegistrar;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Callback extends Action implements CsrfAwareActionInterface
{
    protected $order;
    protected $khipuPayment;
    protected $resultJsonFactory;
    protected $scopeConfig;
    protected $orderSender;
    protected $captureRegistrar;

    public function __construct(
        Context $context,
        Order $order,
        Simplified $khipuPayment,
        JsonFactory $resultJsonFactory,
        ScopeConfigInterface $scopeConfig,
        OrderSender $orderSender,
        CaptureRegistrar $captureRegistrar
    )
    {
        parent::__construct($context);
        $this->order = $order;
        $this->khipuPayment = $khipuPayment;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->orderSender = $orderSender;
        $this->captureRegistrar = $captureRegistrar;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $secret = $this->scopeConfig->getValue('payment/simplified/merchant_secret');

        if (!$secret) {
            throw new \Exception('Missing secret in configuration');
        }
        $raw_post = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_KHIPU_SIGNATURE'] ?? '';

        if (!$signature) {
            return $this->resultJsonFactory->create()->setData(['error' => 'Missing signature header'])->setStatusHeader(400);
        }

        // Sin esto, cualquiera que conozca la URL podría marcar un pedido como pagado.
        if (!$this->isValidSignature((string) $raw_post, (string) $signature, (string) $secret)) {
            return $this->resultJsonFactory->create()->setData(['error' => 'Invalid signature'])->setStatusHeader(401);
        }

        $notificationData = json_decode($raw_post, true);

        if (isset($notificationData['payment_id'])) {
            $order = $this->order->loadByIncrementId($notificationData['transaction_id']);
            if ($order->getId()) {
                try {
                    $this->validateKhipuCallback($order, $notificationData, '3.0');
                } catch (\Exception $e) {
                    return $this->resultJsonFactory->create()->setData(['error' => $e->getMessage()])->setStatusHeader(400);
                }
                return $this->resultJsonFactory->create()->setData(['success' => true])->setStatusHeader(200);
            } else {
                return $this->resultJsonFactory->create()->setData(['error' => 'Order not found'])->setStatusHeader(404);
            }
        } else {
            return $this->resultJsonFactory->create()->setData(['error' => 'Invalid notification data'])->setStatusHeader(400);
        }
    }

    /**
     * Cabecera X-Khipu-Signature: "t=<timestamp>,s=<firma>", con
     * firma = base64(hmac_sha256(t + "." + cuerpo, secret)). Una cabecera mal
     * formada es inválida (antes daba un warning y el webhook respondía 500).
     */
    public function isValidSignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        $t_value = '';
        $s_value = '';
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = $kv;
            if ($key === 't') {
                $t_value = $value;
            } elseif ($key === 's') {
                $s_value = $value;
            }
        }

        if ($t_value === '' || $s_value === '' || $secret === '') {
            return false;
        }

        $hmac_base64 = base64_encode(hash_hmac('sha256', $t_value . '.' . $rawBody, $secret, true));

        return hash_equals($hmac_base64, $s_value);
    }


    public function validateKhipuCallback(Order $order, $notificationData, $apiVersion)
    {
        if (!$order || !$order->getIncrementId()) {
            throw new \Exception('Order #' . $_REQUEST['order_id'] . ' does not exist');
        }

        if ($apiVersion != '3.0') {
            throw new \Exception('Invalid notification API version.');
        }

        if ($notificationData['receiver_id'] != $this->scopeConfig->getValue('payment/simplified/merchant_id')) {
            throw new \Exception('Invalid receiver ID');
        }

        if ($notificationData['custom'] != $order->getPayment()->getAdditionalInformation('khipu_order_token')) {
            throw new \Exception('Invalid transaction ID');
        }

        if ($notificationData['amount'] != number_format($order->getGrandTotal(),
                $this->khipuPayment->getDecimalPlaces($order->getOrderCurrencyCode()), '.', '')
        ) {
            throw new \Exception('Amount mismatch');
        }

        if ($notificationData['currency'] != $order->getOrderCurrencyCode()) {
            throw new \Exception('Currency mismatch');
        }

        // Khipu reintenta la notificación si no recibe el 200 a tiempo. Si este
        // cobro ya quedó registrado (CaptureRegistrar guarda su id como
        // last_trans_id), se responde OK sin facturar ni avisar al cliente otra vez.
        if ((string) $order->getPayment()->getLastTransId() === (string) $notificationData['payment_id']) {
            return;
        }

        $responseTxt = 'Pago Khipu Aceptado<br>';
        $responseTxt .= 'TransactionId: ' . $notificationData['transaction_id'] . '<br>';
        $responseTxt .= 'PaymentId: ' . $notificationData['payment_id'] . '<br>';
        // Los datos del pagador y del banco dependen del medio: un pago LigoPay no trae `bank`.
        $responseTxt .= 'Subject: ' . ($notificationData['subject'] ?? '') . '<br>';
        $responseTxt .= 'Amount: ' . $notificationData['amount'] .' '.$notificationData['currency'] .'<br>';
        $responseTxt .= 'Body: ' . ($notificationData['body'] ?? '') . '<br>';
        $responseTxt .= 'Payment Method: ' . ($notificationData['payment_method'] ?? '') . '<br>';
        $responseTxt .= 'Bank: ' . ($notificationData['bank'] ?? '') . '<br>';
        $responseTxt .= 'Bank Account Number: ' . ($notificationData['bank_account_number'] ?? '') . '<br>';
        $responseTxt .= 'Payer Name: ' . ($notificationData['payer_name'] ?? '') . '<br>';
        $responseTxt .= 'Payer Email: ' . ($notificationData['payer_email'] ?? '') . '<br>';
        $responseTxt .= 'Personal Identifier: ' . ($notificationData['personal_identifier'] ?? '') . '<br>';

        $payment = $order->getPayment();
        $invoice = $order->prepareInvoice();

        $this->captureRegistrar->register(
            $payment,
            $invoice,
            $notificationData['payment_id'],
            $notificationData
        );

        $invoice->register();
        $invoice->save();
        $payment->save();

        $paymentCompleteStatus = $this->scopeConfig->getValue('payment/simplified/payment_complete_status');

        $order->setState($paymentCompleteStatus, false, "Pago Realizado con Khipu", true);
        $order->setStatus($order->getConfig()->getStateDefaultStatus($paymentCompleteStatus));
        $order->setIsCustomerNotified(true);
        $order->addStatusToHistory($paymentCompleteStatus, $responseTxt);
        $order->save();

        $this->orderSender->send($order);
    }
}
