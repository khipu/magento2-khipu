<?php
namespace Khipu\Payment\Plugin\Checkout\Controller\Onepage;

use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service as RefundService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class FailurePlugin
{
    protected $checkoutSession;
    protected $quoteFactory;
    protected $resultRedirectFactory;
    protected $url;
    protected $refundService;
    protected $khipuLogger;
    protected $messageManager;

    public function __construct(
        CheckoutSession $checkoutSession,
        QuoteFactory $quoteFactory,
        UrlInterface $url,
        RedirectFactory $resultRedirectFactory,
        RefundService $refundService,
        LoggerInterface $khipuLogger,
        ManagerInterface $messageManager
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteFactory = $quoteFactory;
        $this->url = $url;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->refundService = $refundService;
        $this->khipuLogger = $khipuLogger;
        $this->messageManager = $messageManager;
    }

    public function aroundExecute(
        \Magento\Checkout\Controller\Onepage\Failure $subject,
        \Closure $proceed
    ) {

        $order = $this->checkoutSession->getLastRealOrder();

        if ($order && $order->getStatus() == 'pending') {
            if (!$this->borrarCobroEnKhipu($order)) {
                // Sin cancelar ni restaurar el carro: el cobro puede estar pagado y
                // restaurar el carro permitiría pagar dos veces. El webhook resuelve.
                $this->messageManager->addWarningMessage(
                    __('No pudimos cancelar tu pago en Khipu. Si ya pagaste, recibirás la confirmación por correo.')
                );
                $resultRedirect = $this->resultRedirectFactory->create();
                $resultRedirect->setPath('checkout/cart');
                return $resultRedirect;
            }

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

        return $proceed();
    }

    /**
     * Borra el cobro en Khipu antes de cancelar la orden. Devuelve si la orden se
     * puede cancelar.
     *
     * Mismo criterio que el conector de VTEX (abandonPayment): primero se borra
     * en Khipu, y si Khipu no lo permite (el cobro ya está pagado o en
     * verificación) no se cancela, para no perder un pago real. Un 404 significa
     * que el cobro ya no existe, así que no hay nada que pueda pagarse.
     */
    private function borrarCobroEnKhipu(Order $order): bool
    {
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== 'simplified') {
            return true;
        }

        $khipuPaymentId = (string) $payment->getAdditionalInformation('khipu_payment_id');
        if ($khipuPaymentId === '') {
            return true;
        }

        try {
            $this->refundService->deletePayment($khipuPaymentId);
            return true;
        } catch (\Throwable $e) {
            $httpCode = $e instanceof RefundException ? $e->getHttpCode() : 0;
            if ($httpCode === 404) {
                return true;
            }

            // Cualquier otro error (no solo RefundException) también deja la orden
            // pendiente: nunca se cancela si no se pudo confirmar el borrado.
            $this->khipuLogger->error('No se pudo borrar el cobro en Khipu; la orden queda pendiente', [
                'order' => $order->getIncrementId(),
                'payment_id' => $khipuPaymentId,
                'http_code' => $httpCode,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
