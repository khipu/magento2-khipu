<?php
namespace Khipu\Payment\Controller\Payment;

use Khipu\Payment\Model\Simplified;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Callback extends Action implements CsrfAwareActionInterface
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

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
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
