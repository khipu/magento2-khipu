<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Refund;

use Khipu\Payment\Model\Refund\CaptureRegistrar;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;

class CaptureRegistrarTest extends TestCase
{
    public function testRegistraElPaymentIdEnLosTresLugaresYCreaLaTransaccion(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setTransactionId', 'setLastTransId', 'setAdditionalInformation',
                'setIsTransactionClosed', 'setTransactionAdditionalInfo', 'addTransaction',
            ])
            ->getMock();

        $payment->expects($this->once())->method('setTransactionId')->with('bdrxk4mn9wqz');
        $payment->expects($this->once())->method('setLastTransId')->with('bdrxk4mn9wqz');
        $payment->expects($this->once())->method('setAdditionalInformation')
            ->with('khipu_payment_id', 'bdrxk4mn9wqz');
        // Debe quedar ABIERTA para poder reembolsar contra ella.
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(false);
        $payment->expects($this->once())->method('addTransaction')
            ->with(TransactionInterface::TYPE_CAPTURE, $invoice, true);

        $invoice->expects($this->once())->method('setTransactionId')->with('bdrxk4mn9wqz');

        (new CaptureRegistrar())->register($payment, $invoice, 'bdrxk4mn9wqz');
    }

    public function testGuardaLaNotificacionCrudaCuandoSeLePasa(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setTransactionId', 'setLastTransId', 'setAdditionalInformation',
                'setIsTransactionClosed', 'setTransactionAdditionalInfo', 'addTransaction',
            ])
            ->getMock();

        $notificacion = ['payment_id' => 'bdrxk4mn9wqz', 'amount' => '205'];

        $payment->expects($this->once())->method('setTransactionAdditionalInfo')
            ->with('khipu_raw_notification', $notificacion);

        (new CaptureRegistrar())->register($payment, $invoice, 'bdrxk4mn9wqz', $notificacion);
    }

    public function testNoGuardaNotificacionCrudaSiVieneVacia(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'setTransactionId', 'setLastTransId', 'setAdditionalInformation',
                'setIsTransactionClosed', 'setTransactionAdditionalInfo', 'addTransaction',
            ])
            ->getMock();

        $payment->expects($this->never())->method('setTransactionAdditionalInfo');

        (new CaptureRegistrar())->register($payment, $invoice, 'bdrxk4mn9wqz');
    }
}
