<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Refund;

use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\ClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    private ClientInterface&MockObject $http;
    private Service $service;

    protected function setUp(): void
    {
        $this->http = $this->createMock(ClientInterface::class);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('LA-API-KEY');

        $this->service = new Service($this->http, $scopeConfig);
    }

    /** Respuesta 200 completa del endpoint de reversas, tal como la manda Khipu. */
    private function bodyDeReversa(array $override = []): string
    {
        return (string) json_encode($override + [
            'id' => 'b31bd9aa-273b-4aed-9516-85866d47a931',
            'payment_id' => 'bdrxk4mn9wqz',
            'refunded_amount' => '205.0000',
            'total_refunded' => '205.0000',
            'remaining' => '0.0000',
            'currency' => 'CLP',
        ]);
    }

    public function testReversaExitosaDevuelveElObjetoDeLaRespuesta(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn(
            $this->bodyDeReversa(['message' => 'La reversa está en proceso.'])
        );

        $r = $this->service->refund('bdrxk4mn9wqz', 'partial', '205');

        $this->assertSame('b31bd9aa-273b-4aed-9516-85866d47a931', $r->id);
        $this->assertSame('0.0000', $r->remaining);
        $this->assertSame('La reversa está en proceso.', $r->message);
    }

    public function testMandaElBodyEsperadoAlEndpointDeReversas(): void
    {
        $this->http->expects($this->once())
            ->method('post')
            ->with(
                'https://payment-api.khipu.com/v3/refunds',
                json_encode(['type' => 'partial', 'payment_id' => 'bdrxk4mn9wqz', 'amount' => '205'])
            );
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn($this->bodyDeReversa());

        $this->service->refund('bdrxk4mn9wqz', 'partial', '205');
    }

    public function testTypeFullNoIncluyeElCampoAmount(): void
    {
        $this->http->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                json_encode(['type' => 'full', 'payment_id' => 'bdrxk4mn9wqz'])
            );
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn($this->bodyDeReversa());

        $this->service->refund('bdrxk4mn9wqz', 'full');
    }

    public function testError400NormalizaLosMensajesDeErrors(): void
    {
        $body = json_encode([
            'status' => 400,
            'message' => 'Error de validación',
            'errors' => [
                ['field' => 'payment_id', 'message' => 'El pago con ID bdrxk4mn9wqz no es reembolsable'],
                ['field' => 'amount', 'message' => 'El monto excede el saldo reembolsable del pago'],
            ],
        ]);
        $this->http->method('getStatus')->willReturn(400);
        $this->http->method('getBody')->willReturn($body);

        try {
            $this->service->refund('bdrxk4mn9wqz', 'partial', '999');
            $this->fail('Se esperaba una RefundException');
        } catch (RefundException $e) {
            $this->assertSame(
                'El pago con ID bdrxk4mn9wqz no es reembolsable El monto excede el saldo reembolsable del pago',
                $e->getMessage()
            );
            $this->assertSame(400, $e->getHttpCode());
            $this->assertSame($body, $e->getRawBody());
        }
    }

    public function testError400SinBodyUsaElCodigoHttp(): void
    {
        $this->http->method('getStatus')->willReturn(400);
        $this->http->method('getBody')->willReturn('');

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('Error HTTP 400');

        $this->service->refund('bdrxk4mn9wqz', 'partial', '205');
    }

    public function testRespuesta200ConBodyNoJsonEsError(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('<html>gateway timeout</html>');

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('Respuesta inválida de Khipu');

        $this->service->refund('bdrxk4mn9wqz', 'partial', '205');
    }

    // --- N-1: json_encode() puede devolver `false` (bytes no-UTF-8), no `null` ---

    /**
     * Antes de N-1, un `false` de json_encode() se colaba a request(), que bajo
     * strict_types=1 declara `?string $body`: eso era un TypeError fatal, no una
     * RefundException. El http mock ni debería llegar a invocarse.
     */
    public function testCuerpoConBytesNoUtf8EnRefundEsErrorManejadoNoFatal(): void
    {
        $this->http->expects($this->never())->method('post');

        try {
            $this->service->refund("bdrxk4mn9wqz\xB1", 'partial', '205');
            $this->fail('Se esperaba una RefundException');
        } catch (RefundException $e) {
            $this->assertStringContainsString('codificación inválida', $e->getMessage());
        }
    }

    public function testExcepcionDeRedSeTraduceAHttpCodeCero(): void
    {
        $this->http->method('post')->willThrowException(new \Exception('Connection timed out'));

        try {
            $this->service->refund('bdrxk4mn9wqz', 'partial', '205');
            $this->fail('Se esperaba una RefundException');
        } catch (RefundException $e) {
            $this->assertSame(0, $e->getHttpCode());
        }
    }

    public function testSaldoDeBilleteraDevuelveElObjeto(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn(json_encode([
            'balance' => '12000.0000',
            'currency' => 'CLP',
            'add_funds_url' => 'https://khipu.com/dashboard/bills',
        ]));

        $b = $this->service->getWalletBalance();

        $this->assertSame('12000.0000', $b->balance);
        $this->assertSame('https://khipu.com/dashboard/bills', $b->add_funds_url);
    }

    // --- C-3: un 200 con JSON válido pero sin los campos del endpoint NO es éxito ---

    /**
     * El caso del brief: un proxy/WAF/portal cautivo responde 200 con
     * {"message":"..."}. Sin esta validación, Simplified marcaba el pedido como
     * reembolsado con cero pesos devueltos.
     */
    public function testReversaCon200DeUnProxyNoPasaComoExito(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('{"message":"Service temporarily unavailable"}');

        try {
            $this->service->refund('bdrxk4mn9wqz', 'partial', '205');
            $this->fail('Se esperaba una RefundException');
        } catch (RefundException $e) {
            $this->assertStringContainsString('POST /v3/refunds', $e->getMessage());
            // No basta con buscar "id": esa subcadena ya aparece dentro de
            // "inválida", así que el assert pasaría aunque el campo faltante no
            // fuera "id". Se exige la frase completa que produce exigirCampos().
            $this->assertStringContainsString('falta el campo obligatorio "id"', $e->getMessage());
            $this->assertStringContainsString('Service temporarily unavailable', (string) $e->getRawBody());
        }
    }

    /**
     * @dataProvider camposObligatoriosDeLaReversa
     */
    public function testReversaSinUnCampoObligatorioEsError(string $campo): void
    {
        $body = json_decode($this->bodyDeReversa(), true);
        unset($body[$campo]);

        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn((string) json_encode($body));

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage(sprintf('falta el campo obligatorio "%s"', $campo));

        $this->service->refund('bdrxk4mn9wqz', 'partial', '205');
    }

    public static function camposObligatoriosDeLaReversa(): array
    {
        return [
            ['id'],
            ['payment_id'],
            ['refunded_amount'],
            ['total_refunded'],
            ['remaining'],
        ];
    }

    public function testReversaConIdVacioEsError(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn($this->bodyDeReversa(['id' => '   ']));

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('falta el campo obligatorio "id"');

        $this->service->refund('bdrxk4mn9wqz', 'partial', '205');
    }

    public function testSaldoSinElCampoBalanceEsError(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('{"currency":"CLP"}');

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('falta el campo obligatorio "balance"');

        $this->service->getWalletBalance();
    }

    // ------------------------------------------------------------------
    // deletePayment()
    // ------------------------------------------------------------------

    public function testDeletePaymentMandaUnDeleteAlEndpointDelPago(): void
    {
        $opciones = [];
        $this->http->method('setOption')->willReturnCallback(
            static function ($nombre, $valor) use (&$opciones): void {
                $opciones[$nombre] = $valor;
            }
        );
        $this->http->expects($this->once())
            ->method('get')
            ->with('https://payment-api.khipu.com/v3/payments/bdrxk4mn9wqz')
            ->willReturnCallback(function () use (&$opciones): void {
                $this->assertSame('DELETE', $opciones[CURLOPT_CUSTOMREQUEST] ?? null);
            });
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('{"message":"Pago eliminado"}');

        $this->service->deletePayment('bdrxk4mn9wqz');
    }

    public function testDeletePaymentLimpiaLaOpcionDeMetodoAunqueFalle(): void
    {
        $this->http->method('get')->willThrowException(new \Exception('Connection timed out'));
        $this->http->expects($this->once())->method('setOptions')->with([]);

        $this->expectException(RefundException::class);

        $this->service->deletePayment('bdrxk4mn9wqz');
    }

    public function testDeletePaymentAceptaRespuesta204SinCuerpo(): void
    {
        $this->http->method('getStatus')->willReturn(204);
        $this->http->method('getBody')->willReturn('');

        $this->service->deletePayment('bdrxk4mn9wqz');
        $this->addToAssertionCount(1);
    }

    public function testDeletePaymentAcepta200SinCuerpo(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('');

        $this->service->deletePayment('bdrxk4mn9wqz');
        $this->addToAssertionCount(1);
    }

    public function testDeletePaymentConPagoInexistenteLanzaConCodigo404(): void
    {
        $this->http->method('getStatus')->willReturn(404);
        $this->http->method('getBody')->willReturn('{"message":"Not found"}');

        try {
            $this->service->deletePayment('bdrxk4mn9wqz');
            $this->fail('Se esperaba una RefundException');
        } catch (RefundException $e) {
            $this->assertSame(404, $e->getHttpCode());
        }
    }

    public function testDeletePaymentConPagoYaPagadoLanzaConElCodigoDeKhipu(): void
    {
        $this->http->method('getStatus')->willReturn(400);
        $this->http->method('getBody')->willReturn('{"message":"El pago no se puede eliminar"}');

        try {
            $this->service->deletePayment('bdrxk4mn9wqz');
            $this->fail('Se esperaba una RefundException');
        } catch (RefundException $e) {
            $this->assertSame(400, $e->getHttpCode());
        }
    }

    // ------------------------------------------------------------------
    // getPaymentMethodIds()
    // ------------------------------------------------------------------

    public function testGetPaymentMethodIdsDevuelveLosIdsDeLaCuenta(): void
    {
        $this->http->expects($this->once())
            ->method('get')
            ->with('https://payment-api.khipu.com/v3/merchants/505127/paymentMethods');
        $this->http->expects($this->once())->method('setTimeout')->with(5);
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn((string) json_encode([
            'paymentMethods' => [
                ['id' => 'LIGOPAY', 'name' => 'LIGOPAY', 'logo_url' => ''],
                ['id' => 'SIMPLIFIED_TRANSFER', 'name' => 'SIMPLIFIED_TRANSFER', 'logo_url' => ''],
            ],
        ]));

        $this->assertSame(
            ['LIGOPAY', 'SIMPLIFIED_TRANSFER'],
            $this->service->getPaymentMethodIds('505127', 5)
        );
    }

    public function testGetPaymentMethodIdsSinElCampoEsError(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('{"message":"ok"}');

        $this->expectException(RefundException::class);
        $this->expectExceptionMessage('paymentMethods');

        $this->service->getPaymentMethodIds('505127', 5);
    }

    // ------------------------------------------------------------------
    // getPaymentMethods()
    // ------------------------------------------------------------------

    public function testGetPaymentMethodsDevuelveIdNombreYLogoEnOrden(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn(
            '{"paymentMethods":[{"id":"LIGOPAY","name":"LigoPay","logo_url":"https://x/l.png"},'
            . '{"id":"REGULAR","name":"REGULAR","logo_url":""}]}'
        );

        $this->assertSame(
            [
                ['id' => 'LIGOPAY', 'name' => 'LigoPay', 'logo_url' => 'https://x/l.png'],
                ['id' => 'REGULAR', 'name' => 'REGULAR', 'logo_url' => ''],
            ],
            $this->service->getPaymentMethods('505127', 5)
        );
    }

    public function testGetPaymentMethodsOmiteElementosSinId(): void
    {
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn((string) json_encode([
            'paymentMethods' => [
                ['name' => 'Sin id', 'logo_url' => ''],
                ['id' => 'REGULAR', 'name' => 'REGULAR', 'logo_url' => ''],
            ],
        ]));

        $this->assertSame(
            [['id' => 'REGULAR', 'name' => 'REGULAR', 'logo_url' => '']],
            $this->service->getPaymentMethods('505127', 5)
        );
    }

    public function testApiKeyExplicitaReemplazaLaDeConfiguracion(): void
    {
        $this->http->expects($this->once())
            ->method('setHeaders')
            ->with($this->callback(
                fn(array $h) => ($h['x-api-key'] ?? null) === 'key-del-form'
            ));
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('{"paymentMethods":[]}');

        $this->service->getPaymentMethods('505127', 10, 'key-del-form');
    }

    public function testSinApiKeyExplicitaUsaLaDeConfiguracion(): void
    {
        $this->http->expects($this->once())
            ->method('setHeaders')
            ->with($this->callback(
                fn(array $h) => ($h['x-api-key'] ?? null) === 'LA-API-KEY'
            ));
        $this->http->method('getStatus')->willReturn(200);
        $this->http->method('getBody')->willReturn('{"paymentMethods":[]}');

        $this->service->getPaymentMethods('505127', 10);
    }
}
