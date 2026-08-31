<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Block\Adminhtml\Order\Creditmemo;

use Khipu\Payment\Block\Adminhtml\Order\Creditmemo\KhipuNotice;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class KhipuNoticeTest extends TestCase
{
    private Registry&MockObject $registry;
    private KhipuNotice $block;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(Registry::class);

        $this->block = $this->getMockBuilder(KhipuNotice::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $p = (new \ReflectionClass(KhipuNotice::class))->getProperty('registry');
        $p->setAccessible(true);
        $p->setValue($this->block, $this->registry);
    }

    private function creditmemo(string $metodo, ?string $invoiceTxn, bool $canRefund = true): Creditmemo&MockObject
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()->onlyMethods(['getMethod'])->getMock();
        $payment->method('getMethod')->willReturn($metodo);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()->onlyMethods(['getPayment', 'getId'])->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getId')->willReturn(8);

        $invoice = null;
        if ($invoiceTxn !== null) {
            $invoice = $this->getMockBuilder(Invoice::class)
                ->disableOriginalConstructor()->onlyMethods(['getTransactionId'])->getMock();
            $invoice->method('getTransactionId')->willReturn($invoiceTxn);
        }

        $cm = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getInvoice', 'canRefund'])->getMock();
        $cm->method('getOrder')->willReturn($order);
        $cm->method('getInvoice')->willReturn($invoice);
        $cm->method('canRefund')->willReturn($canRefund);

        return $cm;
    }

    public function testNoAplicaAPedidosDeOtraPasarela(): void
    {
        $this->registry->method('registry')->willReturn($this->creditmemo('checkmo', 'abc123456789'));

        $this->assertFalse($this->block->isKhipuOrder());
    }

    public function testNoAplicaSiNoHayCreditMemoEnElRegistro(): void
    {
        $this->registry->method('registry')->willReturn(null);

        $this->assertFalse($this->block->isKhipuOrder());
        $this->assertFalse($this->block->hasOnlineRefund());
    }

    public function testDetectaPedidoDeKhipu(): void
    {
        $this->registry->method('registry')->willReturn($this->creditmemo('simplified', 'abc123456789'));

        $this->assertTrue($this->block->isKhipuOrder());
    }

    public function testConFacturaConTransaccionElBotonOnlineEstaDisponible(): void
    {
        $this->registry->method('registry')->willReturn($this->creditmemo('simplified', 'abc123456789'));

        $this->assertTrue($this->block->hasOnlineRefund());
    }

    public function testSinFacturaAsociadaNoHayBotonOnline(): void
    {
        // Es el caso de entrar por el pedido en vez de por la factura: el
        // credit memo no trae factura, así que el core solo pinta el offline.
        $this->registry->method('registry')->willReturn($this->creditmemo('simplified', null));

        $this->assertFalse($this->block->hasOnlineRefund());
    }

    public function testFacturaSinTransaccionNoHabilitaElBotonOnline(): void
    {
        // Pedido anterior al fix del callback: la factura existe pero sin txn.
        $this->registry->method('registry')->willReturn($this->creditmemo('simplified', ''));

        $this->assertFalse($this->block->hasOnlineRefund());
    }
}
