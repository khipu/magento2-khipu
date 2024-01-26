<?php

namespace Khipu\Payment\Plugin\Block\Adminhtml;

use Magento\Sales\Api\OrderRepositoryInterface;
 
class SalesOrderViewInfo
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;


    /**
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository
    ) {
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param \Magento\Sales\Block\Adminhtml\Order\View\Info $subject
     * @param string $result
     * @return string
     */    
    public function afterToHtml(
        \Magento\Sales\Block\Adminhtml\Order\View\Info $subject,
        $result
    )
    {
        $orderId = $subject->getOrder()->getId();
        $paymentInformation = $this->getOrderStatusHistoryComment($orderId);

        $customBlock = $subject->getLayout()->getBlock('custom_block');
        if ($customBlock !== false && $subject->getNameInLayout() == 'order_info') {
            $customBlock->setData('payment_information', $paymentInformation);
            $result = $result;
        }
        return $result;
    }
    
    /**
     * Retrieve order status history comment
     *
     * @param int $orderId
     * @return string
     */
    protected function getOrderStatusHistoryComment($orderId)
    {
        try {
            $order = $this->orderRepository->get($orderId);
            $orderStatusHistory = $order->getStatusHistories();
    
            // Reverse the array to get the last status history comment
            $reversedOrderStatusHistory = array_reverse($orderStatusHistory);
            $lastStatusHistory = array_pop($reversedOrderStatusHistory);
    
            return $lastStatusHistory ? $lastStatusHistory->getComment() : '';
        } catch (\Exception $e) {
            // Handle exception if needed
            return '';
        }
    }
}