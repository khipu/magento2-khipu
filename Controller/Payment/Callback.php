<?php
namespace Khipu\Payment\Controller\Payment;

use Khipu\Payment\Model\Simplified;
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
        Simplified $khipuPayment
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
        $order = $this->order->loadByIncrementId($this->getRequest()->getParam('order_id'));
        try {
            $this->khipuPayment->validateKhipuCallback($order, $this->getRequest()->getPost()['notification_token'],
                $this->getRequest()->getPost()['api_version']);
        } catch (\Exception $e) {
            $this->getResponse()->setStatusCode(\Magento\Framework\App\Response\Http::STATUS_CODE_400);
            $this->getResponse()->setContent($e->getMessage());
            return;
        }
        $this->getResponse()->setBody('OK');
    }
}
