<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Refund;

use Khipu\Payment\Model\Refund\ErrorTranslator;
use Khipu\Payment\Model\Refund\RefundException;
use PHPUnit\Framework\TestCase;

class ErrorTranslatorTest extends TestCase
{
    private ErrorTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ErrorTranslator();
    }

    public function testFlagApagadoDaMensajeDeBilleteraNoHabilitada(): void
    {
        $e = new RefundException(
            'La cuenta de cobro con ID 486463 no está habilitada para hacer reversas.',
            400
        );

        $this->assertSame(
            'La billetera de reversas no está habilitada en tu cuenta Khipu.',
            $this->translator->toAdminMessage($e)
        );
    }

    public function testNoEsReembolsableNombraLasCausasSinApostarPorUna(): void
    {
        $e = new RefundException('El pago con ID bdrxk4mn9wqz no es reembolsable', 400);

        $mensaje = $this->translator->toAdminMessage($e);

        // No debe AFIRMAR que la billetera está deshabilitada: es solo una de
        // cuatro causas posibles, y la menos probable si el admin ya vio su
        // saldo en la configuración.
        $this->assertStringContainsString('Khipu no permite reversar este pago', $mensaje);
        $this->assertStringContainsString('180 días', $mensaje);
        $this->assertStringContainsString('var/log/khipu.log', $mensaje);
        $this->assertStringNotContainsString('no está habilitada en tu cuenta', $mensaje);
    }

    public function testExcedeElSaldoSeMuestraTalCual(): void
    {
        $mensaje = 'El monto 500 excede el saldo reembolsable del pago';
        $e = new RefundException($mensaje, 400);

        $this->assertSame($mensaje, $this->translator->toAdminMessage($e));
    }

    /**
     * Ante httpCode 0 el código NO sabe si el POST se procesó: CURLOPT_TIMEOUT es
     * el timeout total, así que la reversa pudo haberse encolado igual. El mensaje
     * debe advertir la incertidumbre y frenar el reintento a ciegas, no afirmar
     * que no pasó nada.
     */
    public function testErrorDeRedAdvierteLaIncertidumbreYFrenaElReintento(): void
    {
        $e = new RefundException('Connection timed out', 0);

        $mensaje = $this->translator->toAdminMessage($e);

        $this->assertStringNotContainsString('La reversa no se realizó', $mensaje);
        $this->assertStringContainsString('PUEDE haberse realizado', $mensaje);
        $this->assertStringContainsString('var/log/khipu.log', $mensaje);
        $this->assertStringContainsString('antes de reintentar', strtolower($mensaje));
        $this->assertStringContainsString('duplicar', $mensaje);
    }

    public function testErrorDesconocidoSeMuestraTalCual(): void
    {
        $e = new RefundException('Error interno del servidor', 500);

        $this->assertSame('Error interno del servidor', $this->translator->toAdminMessage($e));
    }

    /**
     * N-2: un 200 al que `exigirCampos()` le rechaza un campo es el caso
     * PELIGROSO, no un "no pasó nada". El POST se completó — Khipu recibió la
     * orden y la plata pudo haberse movido — solo no se pudo interpretar la
     * respuesta. Debe llevar la misma advertencia de incertidumbre que el
     * httpCode 0, no el mensaje crudo de "falta el campo obligatorio".
     */
    public function testHttpCode200InvalidoAdvierteLaIncertidumbreYFrenaElReintento(): void
    {
        $e = new RefundException(
            'Respuesta inválida de Khipu (POST /v3/refunds): falta el campo obligatorio "id".',
            200
        );

        $mensaje = $this->translator->toAdminMessage($e);

        $this->assertStringNotContainsString('La reversa no se realizó', $mensaje);
        $this->assertStringContainsString('PUEDE haberse realizado', $mensaje);
        $this->assertStringContainsString('var/log/khipu.log', $mensaje);
        $this->assertStringContainsString('antes de reintentar', strtolower($mensaje));
        $this->assertStringContainsString('duplicar', $mensaje);
        $this->assertStringNotContainsString('falta el campo obligatorio', $mensaje);
    }

    public function testDetectaElFlagApagadoAunqueKhipuCambieElVocabulario(): void
    {
        // Khipu pasó de "reversa" a "devolución" en sus textos (2026-08-26).
        // El gate debe seguir calzando aunque cambie la cola de la frase.
        $e = new RefundException(
            'La cuenta de cobro con ID 486463 no está habilitada para hacer devoluciones.',
            400
        );

        $this->assertSame(
            'La billetera de reversas no está habilitada en tu cuenta Khipu.',
            $this->translator->toAdminMessage($e)
        );
    }

    /**
     * Decisión de producto heredada de WooCommerce: la UI dice "reversa" y
     * "reversar", nunca "devolución" ni "reembolso". Este test existe para que
     * nadie reintroduzca el vocabulario mezclado sin darse cuenta.
     */
    public function testLosMensajesUsanSiempreElVocabularioDeReversa(): void
    {
        $casos = [
            new RefundException('Connection timed out', 0),
            new RefundException('cuerpo inválido', 200),
            new RefundException('El pago con ID x no es reembolsable', 400),
            new RefundException('La cuenta no está habilitada para hacer reversas.', 400),
        ];

        foreach ($casos as $e) {
            $mensaje = $this->translator->toAdminMessage($e);
            $this->assertStringNotContainsStringIgnoringCase('devolución', $mensaje);
            $this->assertStringNotContainsStringIgnoringCase('devoluciones', $mensaje);
        }
    }
}
