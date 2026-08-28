<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Refund;

use Khipu\Payment\Model\Refund\NoticeBuilder;
use PHPUnit\Framework\TestCase;

class NoticeBuilderTest extends TestCase
{
    private NoticeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new NoticeBuilder();
    }

    private function respuestaPendiente(): \stdClass
    {
        return (object) [
            'id' => 'b31bd9aa',
            'refunded_amount' => '205.0000',
            'total_refunded' => '205.0000',
            'remaining' => '0.0000',
            'currency' => 'CLP',
            'message' => 'La reversa está en proceso.',
        ];
    }

    private function respuestaConcretada(): \stdClass
    {
        $r = $this->respuestaPendiente();
        unset($r->message);
        return $r;
    }

    public function testLaNotaPendienteIncluyeElMensajeDeKhipuYLosMontos(): void
    {
        $nota = $this->builder->orderNote($this->respuestaPendiente(), 'CLP');

        $this->assertStringContainsString('queda pendiente', $nota);
        $this->assertStringContainsString('La reversa está en proceso.', $nota);
        $this->assertStringContainsString('$205', $nota);
        $this->assertStringContainsString('$0', $nota);
    }

    public function testLaNotaConcretadaNoDiceQuedaPendiente(): void
    {
        $nota = $this->builder->orderNote($this->respuestaConcretada(), 'CLP');

        $this->assertStringContainsString('concretada', $nota);
        $this->assertStringNotContainsString('queda pendiente', $nota);
    }

    public function testPendienteConSaldoSuficienteEsAvisoVerde(): void
    {
        $aviso = $this->builder->successMessage($this->respuestaPendiente(), 12000.0);

        $this->assertSame('success', $aviso['tipo']);
        $this->assertStringContainsString('se concretará en el próximo ciclo', $aviso['texto']);
    }

    public function testPendienteConSaldoInsuficienteEsAvisoAmarillo(): void
    {
        $aviso = $this->builder->successMessage($this->respuestaPendiente(), 10.0);

        $this->assertSame('warning', $aviso['tipo']);
        $this->assertStringContainsString('podría no alcanzar', $aviso['texto']);
    }

    public function testSiNoSePudoConsultarElSaldoSeAsumeVerde(): void
    {
        // La plata ya se movió: un fallo consultando el saldo no debe alarmar.
        $aviso = $this->builder->successMessage($this->respuestaPendiente(), null);

        $this->assertSame('success', $aviso['tipo']);
    }

    public function testReversaConcretadaEsAvisoVerdeSinMencionarElCiclo(): void
    {
        $aviso = $this->builder->successMessage($this->respuestaConcretada(), 12000.0);

        $this->assertSame('success', $aviso['tipo']);
        $this->assertSame('Reversa realizada con éxito.', $aviso['texto']);
    }

    // ------------------------------------------------------------------
    // I-7: campo() explota en vez de inventar cero. Sin estos tests el
    // comportamiento nuevo de la clase no tenía ni un solo test.
    // ------------------------------------------------------------------

    public function testOrderNotePendienteSinRefundedAmountExplotaEnVezDeInventarCero(): void
    {
        $r = $this->respuestaPendiente();
        unset($r->refunded_amount);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refunded_amount');

        $this->builder->orderNote($r, 'CLP');
    }

    public function testOrderNoteConcretadaSinTotalRefundedExplota(): void
    {
        $r = $this->respuestaConcretada();
        unset($r->total_refunded);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('total_refunded');

        $this->builder->orderNote($r, 'CLP');
    }

    public function testOrderNoteSinRemainingExplota(): void
    {
        $r = $this->respuestaPendiente();
        unset($r->remaining);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('remaining');

        $this->builder->orderNote($r, 'CLP');
    }

    public function testSuccessMessageSinRefundedAmountExplotaEnVezDeApagarLaHeuristicaDeSaldo(): void
    {
        $r = $this->respuestaPendiente();
        unset($r->refunded_amount);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refunded_amount');

        $this->builder->successMessage($r, 12000.0);
    }
}
