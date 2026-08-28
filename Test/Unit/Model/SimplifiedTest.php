<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model;

use Khipu\Payment\Model\Refund\ErrorTranslator;
use Khipu\Payment\Model\Refund\NoticeBuilder;
use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service as RefundService;
use Khipu\Payment\Model\Simplified;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SimplifiedTest extends TestCase
{
    private Simplified $method;

    private RefundService&MockObject $refundService;
    private LoggerInterface&MockObject $khipuLogger;
    private ManagerInterface&MockObject $messageManager;

    protected function setUp(): void
    {
        $this->refundService = $this->createMock(RefundService::class);
        $this->khipuLogger = $this->createMock(LoggerInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        // Sin constructor: AbstractMethod pide media docena de colaboradores que
        // refund() no toca. Se inyectan por reflexión solo los que usa.
        $this->method = (new \ReflectionClass(Simplified::class))->newInstanceWithoutConstructor();

        $this->inyectar('refundService', $this->refundService);
        $this->inyectar('errorTranslator', new ErrorTranslator());
        $this->inyectar('noticeBuilder', new NoticeBuilder());
        $this->inyectar('khipuLogger', $this->khipuLogger);
        $this->inyectar('messageManager', $this->messageManager);
    }

    private function inyectar(string $propiedad, $valor): void
    {
        $prop = new \ReflectionProperty(Simplified::class, $propiedad);
        $prop->setAccessible(true);
        $prop->setValue($this->method, $valor);
    }

    // ------------------------------------------------------------------
    // getDecimalPlaces / flags
    // ------------------------------------------------------------------

    public function testMonedasSinDecimales(): void
    {
        $this->assertSame(0, $this->method->getDecimalPlaces('CLP'));
        $this->assertSame(0, $this->method->getDecimalPlaces('COP'));
    }

    public function testUnidadDeFomentoUsaCuatroDecimales(): void
    {
        $this->assertSame(4, $this->method->getDecimalPlaces('CLF'));
    }

    public function testElRestoUsaDosDecimales(): void
    {
        $this->assertSame(2, $this->method->getDecimalPlaces('USD'));
        $this->assertSame(2, $this->method->getDecimalPlaces('EUR'));
    }

    public function testDeclaraQuePuedeReembolsarTotalYParcialmente(): void
    {
        $this->assertTrue($this->method->canRefund());
        $this->assertTrue($this->method->canRefundPartialPerInvoice());
    }

    // ------------------------------------------------------------------
    // Andamiaje de refund()
    // ------------------------------------------------------------------

    private function respuestaDeKhipu(array $override = []): \stdClass
    {
        return (object) ($override + [
            'id' => 'b31bd9aa-273b-4aed-9516-85866d47a931',
            'payment_id' => 'bdrxk4mn9wqz',
            'refunded_amount' => '205.0000',
            'total_refunded' => '205.0000',
            'remaining' => '0.0000',
            'currency' => 'CLP',
            'message' => 'La reversa está en proceso.',
        ]);
    }

    /**
     * @return array{0: Payment&MockObject, 1: Order&MockObject, 2: Creditmemo&MockObject}
     */
    private function pago(
        ?string $khipuPaymentId = 'bdrxk4mn9wqz',
        float $grandTotal = 205.0,
        string $currency = 'CLP'
    ): array {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getGrandTotal')->willReturn($grandTotal);

        $order = $this->createMock(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        $order->method('getIncrementId')->willReturn('000000123');

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCreditmemo')->willReturn($creditmemo);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn($key = null) => $key === 'khipu_payment_id' ? $khipuPaymentId : null
        );
        $payment->method('getLastTransId')->willReturn(null);

        return [$payment, $order, $creditmemo];
    }

    private function billeteraDevuelve(?float $saldo, ?string $url = null): void
    {
        if ($saldo === null) {
            $this->refundService->method('getWalletBalance')
                ->willThrowException(new RefundException('Connection timed out', 0));
            return;
        }

        $this->refundService->method('getWalletBalance')->willReturn((object) [
            'balance' => (string) $saldo,
            'currency' => 'CLP',
            'add_funds_url' => $url,
        ]);
    }

    // ------------------------------------------------------------------
    // refund() — camino feliz
    // ------------------------------------------------------------------

    public function testReversaPendienteDejaTransaccionNotaYAvisoVerde(): void
    {
        [$payment, $order] = $this->pago();
        $this->refundService->method('refund')->willReturn($this->respuestaDeKhipu());
        $this->billeteraDevuelve(12000.0);

        $payment->expects($this->once())
            ->method('setTransactionId')
            ->with('b31bd9aa-273b-4aed-9516-85866d47a931');
        $payment->expects($this->once())
            ->method('setTransactionAdditionalInfo')
            ->with('khipu_refund', $this->arrayHasKey('remaining'));

        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->stringContains('queda pendiente'));

        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with($this->callback(
                static fn($m) => str_contains((string) $m, 'se concretará en el próximo ciclo')
            ));
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $this->assertSame($this->method, $this->method->refund($payment, 999.0));
    }

    public function testReversaConcretadaSinMessageDaElAvisoDeExitoInmediato(): void
    {
        [$payment, $order] = $this->pago();
        $respuesta = $this->respuestaDeKhipu();
        unset($respuesta->message);
        $this->refundService->method('refund')->willReturn($respuesta);
        $this->billeteraDevuelve(12000.0);

        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->stringContains('Reversa Khipu concretada'));

        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with($this->callback(
                static fn($m) => (string) $m === 'Reversa realizada con éxito.'
            ));

        $this->method->refund($payment, 999.0);
    }

    /**
     * El punto entero de la §7 del spec: Magento pasa el monto en moneda BASE y
     * hay que ignorarlo, porque el cobro se creó con la moneda del pedido.
     * Además siempre se manda `partial` con el monto exacto, nunca `full`.
     */
    public function testUsaElGrandTotalDelCreditMemoYNoElAmountQueMagentoPasa(): void
    {
        [$payment] = $this->pago('bdrxk4mn9wqz', 205.0, 'CLP');
        $this->billeteraDevuelve(12000.0);

        $this->refundService->expects($this->once())
            ->method('refund')
            ->with('bdrxk4mn9wqz', 'partial', '205')
            ->willReturn($this->respuestaDeKhipu());

        $this->method->refund($payment, 187654.0);
    }

    public function testElMontoSeFormateaConLosDecimalesDeLaMonedaDelPedido(): void
    {
        [$payment] = $this->pago('bdrxk4mn9wqz', 205.5, 'USD');
        $this->billeteraDevuelve(12000.0);

        $this->refundService->expects($this->once())
            ->method('refund')
            ->with('bdrxk4mn9wqz', 'partial', '205.50')
            ->willReturn($this->respuestaDeKhipu(['refunded_amount' => '205.5000', 'currency' => 'USD']));

        $this->method->refund($payment, 1.0);
    }

    // ------------------------------------------------------------------
    // C-1 — evidencia de la reversa fuera de la transacción de BD
    // ------------------------------------------------------------------

    public function testLogueaLaReversaExitosaApenasKhipuResponde(): void
    {
        [$payment] = $this->pago();
        $this->refundService->method('refund')->willReturn($this->respuestaDeKhipu());
        $this->billeteraDevuelve(12000.0);

        $capturado = null;
        $this->khipuLogger->expects($this->once())
            ->method('info')
            ->willReturnCallback(function ($mensaje, $contexto) use (&$capturado) {
                $capturado = $contexto;
                return null;
            });

        $this->method->refund($payment, 205.0);

        // Si Magento hace rollback después, esto es lo único que queda.
        $this->assertSame('bdrxk4mn9wqz', $capturado['payment_id']);
        $this->assertSame('b31bd9aa-273b-4aed-9516-85866d47a931', $capturado['refund_id']);
        $this->assertSame('205', $capturado['amount']);
        $this->assertSame('CLP', $capturado['currency']);
        $this->assertSame('000000123', $capturado['order']);
        $this->assertSame('0.0000', $capturado['remaining']);
    }

    /** El log tiene que salir ANTES de mutar el pago o el pedido. */
    public function testElLogSaleAntesDeTocarNadaDeMagento(): void
    {
        [$payment, $order] = $this->pago();
        $this->refundService->method('refund')->willReturn($this->respuestaDeKhipu());
        $this->billeteraDevuelve(12000.0);

        $orden = [];
        $this->khipuLogger->method('info')->willReturnCallback(function () use (&$orden) {
            $orden[] = 'log';
            return null;
        });
        $payment->method('setTransactionId')->willReturnCallback(function () use (&$orden, $payment) {
            $orden[] = 'setTransactionId';
            return $payment;
        });
        $order->method('addCommentToStatusHistory')->willReturnCallback(function () use (&$orden) {
            $orden[] = 'nota';
            return null;
        });

        $this->method->refund($payment, 205.0);

        $this->assertSame(['log', 'setTransactionId', 'nota'], $orden);
    }

    // ------------------------------------------------------------------
    // refund() — errores
    // ------------------------------------------------------------------

    public function testUnErrorDeKhipuNoMutaNadaYDaElMensajeTraducido(): void
    {
        [$payment, $order] = $this->pago();
        $this->refundService->method('refund')->willThrowException(
            new RefundException('El pago con ID bdrxk4mn9wqz no es reembolsable', 400, '{"errors":[]}')
        );

        $payment->expects($this->never())->method('setTransactionId');
        $payment->expects($this->never())->method('setTransactionAdditionalInfo');
        $order->expects($this->never())->method('addCommentToStatusHistory');
        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->khipuLogger->expects($this->never())->method('info');
        $this->khipuLogger->expects($this->once())->method('error');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Khipu no permite reversar este pago');

        $this->method->refund($payment, 205.0);
    }

    public function testTimeoutDaUnMensajeQueNoAfirmaQueLaReversaNoOcurrio(): void
    {
        [$payment] = $this->pago();
        $this->refundService->method('refund')->willThrowException(
            new RefundException('Connection timed out', 0)
        );

        try {
            $this->method->refund($payment, 205.0);
            $this->fail('Se esperaba una LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertStringNotContainsString('La reversa no se realizó', $e->getMessage());
            $this->assertStringContainsString('PUEDE haberse realizado', $e->getMessage());
            $this->assertStringContainsString('var/log/khipu.log', $e->getMessage());
        }
    }

    public function testPedidoSinPaymentIdDeKhipuNiSiquieraLlamaAKhipu(): void
    {
        [$payment] = $this->pago(null);

        $this->refundService->expects($this->never())->method('refund');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no tiene un pago de Khipu asociado');

        $this->method->refund($payment, 205.0);
    }

    public function testSinPaymentIdEnAdditionalInformationCaeAlLastTransId(): void
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getGrandTotal')->willReturn(205.0);

        $order = $this->createMock(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn('CLP');
        $order->method('getIncrementId')->willReturn('000000123');

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCreditmemo')->willReturn($creditmemo);
        $payment->method('getAdditionalInformation')->willReturn(null);
        $payment->method('getLastTransId')->willReturn('bdrxk4mn9wqz');

        $this->billeteraDevuelve(12000.0);
        $this->refundService->expects($this->once())
            ->method('refund')
            ->with('bdrxk4mn9wqz', 'partial', '205')
            ->willReturn($this->respuestaDeKhipu());

        $this->method->refund($payment, 205.0);
    }

    // ------------------------------------------------------------------
    // I-6 — contrastar la respuesta contra lo que se pidió
    // ------------------------------------------------------------------

    public function testRespuestaDeOtroPagoAbortaLaReversa(): void
    {
        [$payment, $order] = $this->pago();
        $this->refundService->method('refund')->willReturn(
            $this->respuestaDeKhipu(['payment_id' => 'OTRO12345678'])
        );

        // La evidencia de que la plata pudo haberse movido igual queda escrita.
        $this->khipuLogger->expects($this->once())->method('info');
        $this->khipuLogger->expects($this->once())->method('error');

        $payment->expects($this->never())->method('setTransactionId');
        $order->expects($this->never())->method('addCommentToStatusHistory');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('OTRO12345678');

        $this->method->refund($payment, 205.0);
    }

    public function testMontoReversadoDistintoDelPedidoAbortaLaReversa(): void
    {
        [$payment, $order] = $this->pago();
        $this->refundService->method('refund')->willReturn(
            $this->respuestaDeKhipu(['refunded_amount' => '100.0000'])
        );

        $this->khipuLogger->expects($this->once())->method('error');
        $payment->expects($this->never())->method('setTransactionId');
        $order->expects($this->never())->method('addCommentToStatusHistory');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('se pidió reversar 205 CLP y Khipu informa 100.0000');

        $this->method->refund($payment, 205.0);
    }

    /** Khipu devuelve 4 decimales fijos: "205.0000" y "205" son el mismo monto. */
    public function testLosCuatroDecimalesFijosDeKhipuNoCuentanComoDesajuste(): void
    {
        [$payment] = $this->pago();
        $this->refundService->method('refund')->willReturn($this->respuestaDeKhipu());
        $this->billeteraDevuelve(12000.0);

        $this->messageManager->expects($this->once())->method('addSuccessMessage');

        $this->method->refund($payment, 205.0);
    }

    // ------------------------------------------------------------------
    // I-5 / §8 — avisos
    // ------------------------------------------------------------------

    public function testSaldoInsuficienteDaAvisoAmarilloConLaUrlEnTextoPlano(): void
    {
        [$payment] = $this->pago();
        $this->refundService->method('refund')->willReturn($this->respuestaDeKhipu());
        $this->billeteraDevuelve(10.0, 'https://khipu.com/dashboard/bills');

        $this->messageManager->expects($this->never())->method('addSuccessMessage');
        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->callback(static function ($m) {
                $texto = (string) $m;
                // Si saliera como <a href>, el renderer por defecto lo escaparía
                // y el admin vería el markup literal.
                return str_contains($texto, 'podría no alcanzar')
                    && str_contains($texto, 'https://khipu.com/dashboard/bills')
                    && !str_contains($texto, '<a ');
            }));

        $this->method->refund($payment, 205.0);
    }

    /** La plata ya se movió: un fallo consultando el saldo no puede revertirla. */
    public function testFalloConsultandoElSaldoNoPropagaYSeAsumeElAvisoVerde(): void
    {
        [$payment] = $this->pago();
        $this->refundService->method('refund')->willReturn($this->respuestaDeKhipu());
        $this->billeteraDevuelve(null);

        $this->khipuLogger->expects($this->once())->method('warning');
        $this->messageManager->expects($this->once())->method('addSuccessMessage');

        $this->method->refund($payment, 205.0);
    }
}
