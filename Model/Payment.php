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
    const KHIPU_MAGENTO_VERSION = '1.0.0';
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

        $configuration = new Khipu\Configuration();
        $configuration->setSecret($this->getConfigData('merchant_secret'));
        $configuration->setReceiverId($this->getConfigData('merchant_id'));
        $configuration->setPlatform('magento2-khipu', '1.0.0');


        $client = new Khipu\ApiClient($configuration);
        $payments = new Khipu\Client\PaymentsApi($client);

        try {
            $createPaymentResponse = $payments->paymentsPost(
                $this->storeManager->getWebsite()->getName() . ' Carro #' . $order->getIncrementId()
                , 'CLP'
                , number_format($order->getGrandTotal(), 0, '.', '')
                , $payment->getAdditionalInformation('khipu_order_token')
                , null
                , join($description, ', ')
                , null //Tools::getValue('bank-id')
                , $this->urlBuilder->getUrl('checkout/onepage/success')
                , $this->urlBuilder->getUrl('checkout/onepage/failure')
                , null
                , ($this->urlBuilder->getUrl('khipu/payment/callback') . '?token=' . $payment->getAdditionalInformation('coingate_order_token'))
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
            $this->context->smarty->assign(
                array(
                    'error' => $exception->getResponseObject()
                )
            );
            //$this->setTemplate('khipu_error.tpl');
            return array(
                'status' => false
            );
        }
        return array(
            'status' => true,
            'payment_url' => $createPaymentResponse->getPaymentUrl()
        );

    }

    /**
     * @param Order $order
     */
    public function validateKhipuCallback(Order $order)
    {
        /*try {
            if (!$order || !$order->getIncrementId()) {
                throw new \Exception('Order #' . $_REQUEST['order_id'] . ' does not exists');
            }

            $payment = $order->getPayment();
            $token1 = isset($_POST['notification_token']) ? $_POST['notification_token'] : '';
            $token2 = $payment->getAdditionalInformation('khipu_order_token');

            if ($token1 == '' || $token1 != $token2) {
                throw new \Exception('Tokens do match.');
            }

            $this->coingate->getOrder($_REQUEST['notification_token']);

            if (!$this->coingate->success) {
                throw new \Exception('CoinGate Order #' . $_REQUEST['id'] . ' does not exist');
            }

            if (!is_array($this->coingate->response)) {
                throw new \Exception('Something wrong with callback');
            }

            if ($this->coingate->response['status'] == 'paid') {
                $order
                    ->setState(Order::STATE_PROCESSING, TRUE)
                    ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING))
                    ->save();
            } elseif (in_array($this->coingate->response['status'], array('invalid', 'expired', 'canceled'))) {
                $order
                    ->setState(Order::STATE_CANCELED, TRUE)
                    ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_CANCELED))
                    ->save();
            }
        } catch (\Exception $e) {
            exit('Error occurred: ' . $e);
        }*/
    }
}
