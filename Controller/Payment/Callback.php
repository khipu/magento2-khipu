<?php
namespace Khipu\Payment\Controller\Payment;

use Khipu\Payment\Model\Payment\Simplified as KhipuPayment;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;

class Callback extends Action
{
    protected $order;
    protected $khipuPayment;

    public function __construct(
        Context $context,
        Order $order,
        KhipuPayment $khipuPayment
    )
    {
        parent::__construct($context);

        $this->order = $order;
        $this->khipuPayment = $khipuPayment;
    }

    /**
     * Default customer account page
     *
     * @return void
     */
    public function execute()
    {
        $order = $this->order->loadByIncrementId($_GET['order_id']);
        $this->khipuPayment->validateKhipuCallback($order);

        $this->getResponse()->setBody('OK');
    }
}
