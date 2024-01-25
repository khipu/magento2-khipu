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

class Simplified extends \Magento\Payment\Model\Method\AbstractMethod
{
    const KHIPU_MAGENTO_VERSION = "2.4.8";
    protected $_code = 'simplified';
    protected $_isInitializeNeeded = true;
    protected $urlBuilder;
    protected $storeManager;
    protected $_canOrder = true;
    protected $_canAuthorize = true;
    protected $_canUseCheckout = true;
    protected $_canFetchTransactionInfo = true;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param UrlInterface $urlBuilder
     * @param StoreManagerInterface $storeManager
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @internal param ModuleListInterface $moduleList
     * @internal param TimezoneInterface $localeDate
     * @internal param CountryFactory $countryFactory
     * @internal param Http $response
     */
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

    }

    /**
     * @param Order $order
     * @return array
     */
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

        $configuration = new \Khipu\Configuration();
        $configuration->setSecret($this->getConfigData('merchant_secret'));
        $configuration->setReceiverId($this->getConfigData('merchant_id'));
        $configuration->setPlatform('magento2-khipu', Simplified::KHIPU_MAGENTO_VERSION);

        $client = new \Khipu\ApiClient($configuration);
        $payments = new \Khipu\Client\PaymentsApi($client);

        error_log($this->getConfigData('merchant_secret'));

        error_log($this->getConfigData('merchant_id'));

        try {

            $opts = array(
                "transaction_id" => $order->getIncrementId(),
                "body" => join(', ',$description),
                "custom" => $payment->getAdditionalInformation('khipu_order_token'),
                "return_url" => $this->urlBuilder->getUrl('checkout/onepage/success'),
                "cancel_url" => $this->urlBuilder->getUrl('checkout/onepage/failure'),
                "notify_url" => ($this->urlBuilder->getUrl('khipupayment/payment/callback', array("order_id" => $order->getIncrementId()))),
                "notify_api_version" => "1.3",
                "payer_email" => $order->getCustomerEmail()
            );

            $createPaymentResponse = $payments->paymentsPost(
                $this->storeManager->getWebsite()->getName() . ' Carro #' . $order->getIncrementId()
                , $order->getOrderCurrencyCode()
                , number_format($order->getGrandTotal(), $this->getDecimalPlaces($order->getOrderCurrencyCode()), '.', '')
                , $opts
            );
        } catch (\Khipu\ApiException $e) {
            $error = $e->getResponseObject();
            $msg = "Error de comunicación con khipu.\n";
            $msg .= "Código: " . $error->getStatus() . "\n";
            $msg .= "Mensaje: " . $error->getMessage() . "\n";
            if (method_exists($error, 'getErrors')) {
                $msg .= "Errores:";
                foreach ($error->getErrors() as $errorItem) {
                    $msg .= "\n" . $errorItem->getField() . ": " . $errorItem->getMessage();
                }
            }
            return array(
                'reason' => $msg,
                'status' => false
            );
        }

        return array(
            'status' => true,
            'payment_url' => $createPaymentResponse->getSimplifiedTransferUrl()
        );
    }

    public function getDecimalPlaces($currencyCode)
    {
        if ($currencyCode == 'CLP') {
            return 0;
        }
        return 2;
    }

    /**
     * @param Order $order
     */
    public function validateKhipuCallback(Order $order, $notificationToken, $apiVersion)
    {
        if (!$order || !$order->getIncrementId()) {
            throw new \Exception('Order #' . $_REQUEST['order_id'] . ' does not exists');
        }

        $payment = $order->getPayment();

        if ($apiVersion != '1.3') {
            throw new \Exception('Invalid notification api version.');
        }
        $configuration = new \Khipu\Configuration();
        $configuration->setSecret($this->getConfigData('merchant_secret'));
        $configuration->setReceiverId($this->getConfigData('merchant_id'));
        $configuration->setPlatform('magento2-khipu', Simplified::KHIPU_MAGENTO_VERSION);

        $client = new \Khipu\ApiClient($configuration);
        $payments = new \Khipu\Client\PaymentsApi($client);

        try {
            $paymentResponse = $payments->paymentsGet($notificationToken);
        } catch (\Khipu\ApiException $exception) {
            throw new \Exception(print_r($exception->getResponseObject(), TRUE));
        }

        if ($paymentResponse->getReceiverId() != $this->getConfigData('merchant_id')) {
            throw new \Exception('Invalid receiver id');
        }

        if ($paymentResponse->getCustom() != $payment->getAdditionalInformation('khipu_order_token')) {
            throw new \Exception('Invalid transaction id');
        }

        if ($paymentResponse->getStatus() != 'done') {
            throw new \Exception('Payment not done');
        }

        if ($paymentResponse->getAmount() != number_format($order->getGrandTotal(),
                $this->getDecimalPlaces($order->getOrderCurrencyCode()), '.', '')
        ) {
            throw new \Exception('Amount mismatch');
        }

        if ($paymentResponse->getCurrency() != $order->getOrderCurrencyCode()) {
            throw new \Exception('Currency mismatch');
        }

        $responseTxt = 'Pago Khipu Aceptado<br>';
        $responseTxt .= 'TransactionId: ' . $paymentResponse->getTransactionId() . '<br>';
        $responseTxt .= 'PaymentId: ' . $paymentResponse->getPaymentId() . '<br>';
        $responseTxt .= 'Subject: ' . $paymentResponse->getSubject() . '<br>';
        $responseTxt .= 'Amount: ' . $paymentResponse->getAmount() .' '.$paymentResponse->getCurrency() .'<br>';
        $responseTxt .= 'Status: ' . $paymentResponse->getStatus() .' - ' . $paymentResponse->getStatusDetail() .'<br>';
        $responseTxt .= 'Body: ' . $paymentResponse->getBody() . '<br>';
        $responseTxt .= 'Bank: ' . $paymentResponse->getBank() . '<br>';
        $responseTxt .= 'Bank Account Number: ' . $paymentResponse->getBankAccountNumber() . '<br>';
        $responseTxt .= 'Payer Name: ' . $paymentResponse->getPayerName() . '<br>';
        $responseTxt .= 'Payer Email: ' . $paymentResponse->getPayerEmail() . '<br>';
        $responseTxt .= 'Personal Identifier: ' . $paymentResponse->getPersonalIdentifier() . '<br>';
   
        $invoice = $order->prepareInvoice();
        $invoice->register();
        $invoice->save();

        $paymentCompleteStatus = $this->getConfigData('payment_complete_status');

        $order->setState($paymentCompleteStatus, true);
        $order->setStatus($order->getConfig()->getStateDefaultStatus($paymentCompleteStatus));
        $order->addStatusToHistory($paymentCompleteStatus, $responseTxt);
        $order->save();
    }
}
