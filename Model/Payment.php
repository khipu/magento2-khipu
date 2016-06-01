<?php
namespace Khipu\Merchant\Model;

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

class Payment extends AbstractMethod
{

    const KHIPU_MAGENTO_VERSION = '2.0.0';
    const CODE = 'khipu_merchant';

    protected $_code = 'khipu_merchant';

    protected $_isInitializeNeeded = true;

    protected $urlBuilder;
    protected $storeManager;

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
        $configuration->setPlatform('magento2-khipu', Payment::KHIPU_MAGENTO_VERSION);

        $client = new \Khipu\ApiClient($configuration);
        $payments = new \Khipu\Client\PaymentsApi($client);

        try {
            $createPaymentResponse = $payments->paymentsPost(
                $this->storeManager->getWebsite()->getName() . ' Carro #' . $order->getIncrementId()
                , $order->getOrderCurrencyCode()
                ,
                number_format($order->getGrandTotal(), $this->getDecimalPlaces($order->getOrderCurrencyCode()), '.', '')
                , $payment->getAdditionalInformation('khipu_order_token')
                , null
                , join($description, ', ')
                , null //Tools::getValue('bank-id')
                , $this->urlBuilder->getUrl('checkout/onepage/success')
                , $this->urlBuilder->getUrl('checkout/onepage/failure')
                , null
                , ($this->urlBuilder->getUrl('khipu/payment/callback') . '?order_id=' . $order->getIncrementId())
                , '1.3'
                , null
                , null
                , null
                , null //$customer->email
                , null
                , null
                , null
                , null
            );
        } catch (\Khipu\ApiException $exception) {
            $error = $exception->getResponseObject();
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
            'payment_url' => $createPaymentResponse->getPaymentUrl()
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
    public function validateKhipuCallback(Order $order)
    {
        try {
            if (!$order || !$order->getIncrementId()) {
                throw new \Exception('Order #' . $_REQUEST['order_id'] . ' does not exists');
            }

            $payment = $order->getPayment();
            $notificationToken = isset($_POST['notification_token']) ? $_POST['notification_token'] : '';

            if ($notificationToken == '') {
                throw new \Exception('Invalid notification token.');
            }
            $configuration = new \Khipu\Configuration();
            $configuration->setSecret($this->getConfigData('merchant_secret'));
            $configuration->setReceiverId($this->getConfigData('merchant_id'));
            $configuration->setPlatform('magento2-khipu', Payment::KHIPU_MAGENTO_VERSION);

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

            if ($paymentResponse->getTransactionId() != $payment->getAdditionalInformation('khipu_order_token')) {
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
            $order
                ->setState(Order::STATE_PROCESSING, TRUE)
                ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING))
                ->save();

        } catch (\Exception $e) {
            exit('Error occurred: ' . $e);
        }
    }
}
