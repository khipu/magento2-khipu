<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Plugin\Checkout\Controller\Onepage;

use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Khipu\Payment\Plugin\Checkout\Controller\Onepage\FailurePlugin;
use Magento\Checkout\Controller\Onepage\Failure;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FailurePluginTest extends TestCase
{
    private CheckoutSession&MockObject $session;
    private QuoteFactory&MockObject $quoteFactory;
    private Quote&MockObject $quote;
    private Redirect&MockObject $redirect;
    private Service&MockObject $service;
    private LoggerInterface&MockObject $logger;
    private ManagerInterface&MockObject $messages;
    private FailurePlugin $plugin;

    protected function setUp(): void
    {
        $this->session = $this->createMock(CheckoutSession::class);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('loadByIdWithoutStore')->willReturnSelf();
        $this->quote->method('getId')->willReturn(7);
        $this->quote->method('setIsActive')->willReturnSelf();
        $this->quote->method('setReservedOrderId')->willReturnSelf();

        $this->quoteFactory = $this->getMockBuilder(QuoteFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->quoteFactory->method('create')->willReturn($this->quote);

        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->service = $this->createMock(Service::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->messages = $this->createMock(ManagerInterface::class);

        $this->plugin = new FailurePlugin(
            $this->session,
            $this->quoteFactory,
            $this->createMock(UrlInterface::class),
            $redirectFactory,
            $this->service,
            $this->logger,
            $this->messages
        );
    }

    private function orden(string $status, string $metodo, ?string $khipuPaymentId): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($metodo);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn($key = null) => $key === 'khipu_payment_id' ? $khipuPaymentId : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getStatus')->willReturn($status);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getQuoteId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000050');
        $order->method('cancel')->willReturnSelf();

        $this->session->method('getLastRealOrder')->willReturn($order);

        return $order;
    }

    private function ejecutar()
    {
        return $this->plugin->aroundExecute(
            $this->createMock(Failure::class),
            static fn() => 'proceed'
        );
    }

    public function testCobroBorradoCancelaYRestauraElCarro(): void
    {
        $order = $this->orden('pending', 'simplified', 'cqnt0m9cj1io');
        $this->service->expects($this->once())->method('deletePayment')->with('cqnt0m9cj1io');
        $order->expects($this->once())->method('cancel');
        $this->quote->expects($this->once())->method('save');
        $this->redirect->expects($this->once())->method('setPath')->with('checkout/cart');

        $this->assertSame($this->redirect, $this->ejecutar());
    }

    public function testCobroInexistenteCancelaIgual(): void
    {
        $order = $this->orden('pending', 'simplified', 'cqnt0m9cj1io');
        $this->service->method('deletePayment')
            ->willThrowException(new RefundException('Not found', 404));
        $order->expects($this->once())->method('cancel');

        $this->assertSame($this->redirect, $this->ejecutar());
    }

    public function testSiKhipuNoPermiteBorrarNoCancelaYAvisa(): void
    {
        $order = $this->orden('pending', 'simplified', 'cqnt0m9cj1io');
        $this->service->method('deletePayment')
            ->willThrowException(new RefundException('El pago no se puede eliminar', 400));
        $order->expects($this->never())->method('cancel');
        $this->quoteFactory->expects($this->never())->method('create');
        $this->logger->expects($this->once())->method('error');
        $this->messages->expects($this->once())
            ->method('addWarningMessage')
            ->with(
                'No pudimos cancelar tu pago en Khipu. Si ya pagaste, recibirás la confirmación por correo.'
            );
        $this->redirect->expects($this->once())->method('setPath')->with('checkout/cart');

        $this->assertSame($this->redirect, $this->ejecutar());
    }

    public function testErrorDeRedAlBorrarNoCancela(): void
    {
        $order = $this->orden('pending', 'simplified', 'cqnt0m9cj1io');
        $this->service->method('deletePayment')
            ->willThrowException(new RefundException('Connection timed out', 0));
        $order->expects($this->never())->method('cancel');
        $this->messages->expects($this->once())->method('addWarningMessage');

        $this->ejecutar();
    }

    public function testErrorInesperadoAlBorrarNoCancelaYAvisa(): void
    {
        $order = $this->orden('pending', 'simplified', 'cqnt0m9cj1io');
        $this->service->method('deletePayment')
            ->willThrowException(new \TypeError('inesperado'));
        $order->expects($this->never())->method('cancel');
        $this->quoteFactory->expects($this->never())->method('create');
        $this->logger->expects($this->once())->method('error');
        $this->messages->expects($this->once())->method('addWarningMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('checkout/cart');

        $this->assertSame($this->redirect, $this->ejecutar());
    }

    public function testOrdenDeOtroMedioNoLlamaAKhipu(): void
    {
        $order = $this->orden('pending', 'checkmo', null);
        $this->service->expects($this->never())->method('deletePayment');
        $order->expects($this->once())->method('cancel');

        $this->ejecutar();
    }

    public function testOrdenSinIdDeKhipuCancelaSinLlamarAKhipu(): void
    {
        $order = $this->orden('pending', 'simplified', null);
        $this->service->expects($this->never())->method('deletePayment');
        $order->expects($this->once())->method('cancel');

        $this->ejecutar();
    }

    public function testOrdenNoPendienteNoHaceNada(): void
    {
        $order = $this->orden('canceled', 'simplified', 'cqnt0m9cj1io');
        $this->service->expects($this->never())->method('deletePayment');
        $order->expects($this->never())->method('cancel');

        $this->assertSame('proceed', $this->ejecutar());
    }
}
