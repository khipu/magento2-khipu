<?php
namespace Khipu\Payment\Plugin\Checkout\Controller\Onepage;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\QuoteFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\Controller\Result\RedirectFactory;

class FailurePlugin
{
    protected $checkoutSession;
    protected $quoteFactory;
    protected $resultRedirectFactory;
    protected $url;

    public function __construct(
        CheckoutSession $checkoutSession,
        QuoteFactory $quoteFactory,
        UrlInterface $url,
        RedirectFactory $resultRedirectFactory
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteFactory = $quoteFactory;
        $this->url = $url;
        $this->resultRedirectFactory = $resultRedirectFactory;
    }

    public function aroundExecute(
        \Magento\Checkout\Controller\Onepage\Failure $subject,
        \Closure $proceed
    ) {

        $order = $this->checkoutSession->getLastRealOrder();
        //error_log('Order #' . $order->getIncrementId() . ' - Status: ' . $order->getStatus());

        if ($order && $order->getStatus() == 'pending') {
            $quote = $this->quoteFactory->create()->loadByIdWithoutStore($order->getQuoteId());
            $order->cancel()->save();

            if ($quote->getId()) {
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $this->checkoutSession->replaceQuote($quote);

                $resultRedirect = $this->resultRedirectFactory->create();
                $resultRedirect->setPath('checkout/cart');
                return $resultRedirect;
            }
            
        }
        //error_log('Failed to restore cart. Order not found.');

        return $proceed();
    }
}
