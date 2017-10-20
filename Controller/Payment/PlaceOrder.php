<?php
namespace Khipu\Payment\Controller\Payment;

use Khipu\Payment\Model\Simplified;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class PlaceOrder extends Action
{
    protected $orderFactory;
    protected $khipuPayment;
    protected $checkoutSession;
    protected $logger;

    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        Session $checkoutSession,
        Simplified $khipuPayment,
        LoggerInterface $logger
    )
    {
        parent::__construct($context);

        $this->orderFactory = $orderFactory;
        $this->khipuPayment = $khipuPayment;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
    }

    public function execute()
    {

        $id = $this->checkoutSession->getLastOrderId();


        $order = $this->orderFactory->create()->load($id);


        if (!$order->getIncrementId()) {
            $this->getResponse()->setBody(json_encode(array(
                'status' => false,
                'reason' => 'Order Not Found',
            )));

            return;
        }

        $this->getResponse()->setBody(json_encode($this->khipuPayment->getKhipuRequest($order)));

        return;
    }
}
