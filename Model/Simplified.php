<?php

namespace Khipu\Payment\Model;

use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Simplified extends \Magento\Payment\Model\Method\AbstractMethod
{
    const KHIPU_MAGENTO_VERSION = "2.5.1";
    const API_VERSION = "3.0";

    protected $_code = 'simplified';
    protected $_isInitializeNeeded = true;
    protected $urlBuilder;
    protected $storeManager;
    protected $orderSender;
    protected $_canOrder = true;
    protected $_canAuthorize = true;
    protected $_canUseCheckout = true;
    protected $_canFetchTransactionInfo = true;

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
        curl_setopt($ch, CURLOPT_USERAGENT, "khipu-api-php-client/" . self::API_VERSION . "|prestashop-khipu/" . self::KHIPU_MAGENTO_VERSION);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout in seconds
        curl_setopt($ch, CURLOPT_FAILONERROR, true); // Fail on HTTP error
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL peer verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

    public function getDecimalPlaces($currencyCode)
    {
        if ($currencyCode == 'CLP') {
            return 0;
        }
        return 2;
    }
}
