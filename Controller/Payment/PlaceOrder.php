<?php
namespace Khipu\Merchant\Controller\Payment;

use Khipu\Merchant\Model\Payment as KhipuPayment;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;

class PlaceOrder extends Action
{
    protected $orderFactory;
    protected $khipuPayment;
    protected $checkoutSession;

    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        Session $checkoutSession,
        KhipuPayment $khipuPayment
    )
    {
        parent::__construct($context);

        $this->orderFactory = $orderFactory;
        $this->khipuPayment = $khipuPayment;
        $this->checkoutSession = $checkoutSession;
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
