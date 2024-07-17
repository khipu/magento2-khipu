<?php
namespace Khipu\Payment\Controller\Payment;

use Khipu\Payment\Model\Simplified;
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

    public function __construct(
        Context $context,
        Order $order,
        Simplified $khipuPayment,
        JsonFactory $resultJsonFactory,
        ScopeConfigInterface $scopeConfig,
        OrderSender $orderSender
    )
    {
        parent::__construct($context);
        $this->order = $order;
        $this->khipuPayment = $khipuPayment;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->orderSender = $orderSender;
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

        $notificationData = json_decode($raw_post, true);

        try {
            $this->verifyNotification($raw_post, $signature, $secret);
        } catch (\Exception $e) {
            return $this->resultJsonFactory->create()->setData(['error' => $e->getMessage()])->setStatusHeader(400);
        }

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

    private function verifyNotification($notificationData, $signatureHeader, $secret)
    {
        $signature_parts = explode(',', $signatureHeader);
        $t_value = '';
        $s_value = '';
        foreach ($signature_parts as $part) {
            [$key, $value] = explode('=', $part);
            if ($key === 't') {
                $t_value = $value;
            } elseif ($key === 's') {
                $s_value = $value;
            }
        }

        $to_hash = $t_value . '.' . $notificationData;
        $hmac_signature = hash_hmac('sha256', $to_hash, $secret, true);
        $hmac_base64 = base64_encode($hmac_signature);

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

        $responseTxt = 'Pago Khipu Aceptado<br>';
        $responseTxt .= 'TransactionId: ' . $notificationData['transaction_id'] . '<br>';
        $responseTxt .= 'PaymentId: ' . $notificationData['payment_id'] . '<br>';
        $responseTxt .= 'Subject: ' . $notificationData['subject'] . '<br>';
        $responseTxt .= 'Amount: ' . $notificationData['amount'] .' '.$notificationData['currency'] .'<br>';
        $responseTxt .= 'Body: ' . $notificationData['body'] . '<br>';
        $responseTxt .= 'Bank: ' . $notificationData['bank'] . '<br>';
        $responseTxt .= 'Bank Account Number: ' . $notificationData['bank_account_number'] . '<br>';
        $responseTxt .= 'Payer Name: ' . $notificationData['payer_name'] . '<br>';
        $responseTxt .= 'Payer Email: ' . $notificationData['payer_email'] . '<br>';
        $responseTxt .= 'Personal Identifier: ' . $notificationData['personal_identifier'] . '<br>';

        $invoice = $order->prepareInvoice();
        $invoice->register();
        $invoice->save();

        $paymentCompleteStatus = $this->scopeConfig->getValue('payment/simplified/payment_complete_status');

        $order->setState($paymentCompleteStatus, false, "Pago Realizado con Khipu", true);
        $order->setStatus($order->getConfig()->getStateDefaultStatus($paymentCompleteStatus));
        $order->setIsCustomerNotified(true);
        $order->addStatusToHistory($paymentCompleteStatus, $responseTxt);
        $order->save();

        $this->orderSender->send($order);
    }
}
