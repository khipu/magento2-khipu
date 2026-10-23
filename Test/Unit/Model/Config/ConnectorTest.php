<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Config;

use Khipu\Payment\Model\Config\Connector;
use Khipu\Payment\Model\Config\MethodsTable;
use Khipu\Payment\Model\Config\MethodsTableMerger;
use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConnectorTest extends TestCase
{
    private Service&MockObject $service;
    private MethodsTable&MockObject $tabla;
    private MethodsTableMerger&MockObject $merger;
    private WriterInterface&MockObject $writer;
    private TypeListInterface&MockObject $cacheTypes;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->service = $this->createMock(Service::class);
        $this->tabla = $this->createMock(MethodsTable::class);
        $this->merger = $this->createMock(MethodsTableMerger::class);
        $this->writer = $this->createMock(WriterInterface::class);
        $this->cacheTypes = $this->createMock(TypeListInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function crear(): Connector
    {
        return new Connector(
            $this->service,
            $this->tabla,
            $this->merger,
            $this->writer,
            $this->cacheTypes,
            $this->scopeConfig,
            $this->logger
        );
    }

    /** @dataProvider credencialesIncompletas */
    public function testFaltanCredencialesNoLlamaAKhipuNiGuarda(string $apiKey, string $merchantId, string $secret): void
    {
        $this->service->expects($this->never())->method('getPaymentMethods');
        $this->writer->expects($this->never())->method('save');
        $this->tabla->expects($this->never())->method('save');

        try {
            $this->crear()->connect($apiKey, $merchantId, $secret);
            $this->fail('Se esperaba LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertSame('Ingresa la API Key, el Merchant_ID y el Secret antes de conectar.', $e->getMessage());
        }
    }

    public static function credencialesIncompletas(): array
    {
        return [
            'api key vacía' => ['', '505127', 'sec'],
            'merchant en blanco' => ['key', '  ', 'sec'],
            'secret vacío' => ['key', '505127', ''],
            'secret en blanco' => ['key', '505127', '   '],
        ];
    }

    public function testConsultaConLasCredencialesDelFormulario(): void
    {
        $this->service->expects($this->once())
            ->method('getPaymentMethods')
            ->with('505127', 10, 'key-form')
            ->willReturn([]);
        $this->merger->method('merge')->willReturn(['methods' => []]);

        $this->crear()->connect('  key-form ', ' 505127 ', 'sec');
    }

    public function testExitoGuardaCredencialesYTablaYLimpiaCacheDeConfig(): void
    {
        $this->service->method('getPaymentMethods')->willReturn([['id' => 'LIGOPAY', 'name' => 'L', 'logo_url' => '']]);
        $fusionada = ['merchant_id' => '505127', 'methods' => [['id' => 'LIGOPAY']]];
        $this->merger->method('merge')->willReturn($fusionada);

        $guardados = [];
        $this->writer->expects($this->exactly(3))->method('save')->willReturnCallback(
            function ($path, $value, $scope, $scopeId) use (&$guardados) {
                $guardados[$path] = [$value, $scope, $scopeId];
            }
        );
        $this->tabla->expects($this->once())->method('save')->with($fusionada);
        $this->cacheTypes->expects($this->once())->method('cleanType')->with('config');

        $resultado = $this->crear()->connect('key', '505127', 'sec');

        $this->assertSame($fusionada, $resultado);
        $this->assertSame(['key', 'default', 0], $guardados['payment/simplified/api_key']);
        $this->assertSame(['505127', 'default', 0], $guardados['payment/simplified/merchant_id']);
        $this->assertSame(['sec', 'default', 0], $guardados['payment/simplified/merchant_secret']);
    }

    public function testSiKhipuFallaNoGuardaNadaYDaMensaje(): void
    {
        $this->service->method('getPaymentMethods')->willThrowException(new RefundException('Unauthorized', 401));
        $this->writer->expects($this->never())->method('save');
        $this->tabla->expects($this->never())->method('save');
        $this->cacheTypes->expects($this->never())->method('cleanType');
        $this->logger->expects($this->once())->method('warning');

        try {
            $this->crear()->connect('bad', '505127', 'sec');
            $this->fail('Se esperaba LocalizedException');
        } catch (LocalizedException $e) {
            $this->assertSame('No pudimos consultar los medios de pago en Khipu: Unauthorized', $e->getMessage());
        }
    }

    /** @dataProvider modosIniciales */
    public function testModoInicialSegunEmbebidoGuardado(?string $guardado, string $esperado): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn($path) => $path === 'payment/simplified/embedded_payment' ? $guardado : null
        );
        $this->tabla->method('get')->willReturn(null);
        $this->service->method('getPaymentMethods')->willReturn([]);
        $this->merger->expects($this->once())->method('merge')
            ->with(null, [], '505127', $this->anything(), $esperado)
            ->willReturn(['methods' => []]);

        $this->crear()->connect('key', '505127', 'sec');
    }

    public static function modosIniciales(): array
    {
        return [
            'embebido apagado guardado' => ['0', 'redirect'],
            'sin valor guardado' => [null, 'embedded'],
        ];
    }
}
