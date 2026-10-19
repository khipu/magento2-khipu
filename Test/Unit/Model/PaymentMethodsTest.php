<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model;

use Khipu\Payment\Model\PaymentMethods;
use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PaymentMethodsTest extends TestCase
{
    private Service&MockObject $service;
    private CacheInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->service = $this->createMock(Service::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function crear(string $merchantId = '505127'): PaymentMethods
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn($path) => $path === 'payment/simplified/merchant_id' ? $merchantId : null
        );

        return new PaymentMethods($this->service, $this->cache, $scopeConfig, $this->logger);
    }

    public function testConTransferenciaSimplificadaDevuelveTrue(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->service->method('getPaymentMethodIds')
            ->with('505127', 5)
            ->willReturn(['LIGOPAY', 'SIMPLIFIED_TRANSFER']);

        $this->assertTrue($this->crear()->hasSimplifiedTransfer());
    }

    public function testSinTransferenciaSimplificadaDevuelveFalse(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->service->method('getPaymentMethodIds')->willReturn(['LIGOPAY', 'REGULAR_TRANSFER']);

        $this->assertFalse($this->crear()->hasSimplifiedTransfer());
    }

    public function testGuardaLaRespuestaEnCachePorTreintaSegundosConEtiquetaDeConfiguracion(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->service->method('getPaymentMethodIds')->willReturn(['LIGOPAY']);
        $this->cache->expects($this->once())
            ->method('save')
            ->with('["LIGOPAY"]', 'khipu_payment_methods_505127', ['CONFIG'], 30);

        $this->crear()->hasSimplifiedTransfer();
    }

    public function testConCacheNoLlamaAKhipu(): void
    {
        $this->cache->method('load')
            ->with('khipu_payment_methods_505127')
            ->willReturn('["SIMPLIFIED_TRANSFER"]');
        $this->service->expects($this->never())->method('getPaymentMethodIds');

        $this->assertTrue($this->crear()->hasSimplifiedTransfer());
    }

    public function testCacheCorruptaVuelveAConsultar(): void
    {
        $this->cache->method('load')->willReturn('esto no es json');
        $this->service->expects($this->once())
            ->method('getPaymentMethodIds')
            ->willReturn(['SIMPLIFIED_TRANSFER']);

        $this->assertTrue($this->crear()->hasSimplifiedTransfer());
    }

    public function testSiKhipuFallaDevuelveFalseDejaLogYNoGuardaCache(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->service->method('getPaymentMethodIds')
            ->willThrowException(new RefundException('Connection timed out', 0));
        $this->logger->expects($this->once())->method('warning');
        $this->cache->expects($this->never())->method('save');

        $this->assertFalse($this->crear()->hasSimplifiedTransfer());
    }

    public function testSiLaCacheFallaDevuelveFalseYDejaLog(): void
    {
        $this->cache->method('load')->willThrowException(new \RuntimeException('redis caído'));
        $this->service->expects($this->never())->method('getPaymentMethodIds');
        $this->logger->expects($this->once())->method('warning');

        $this->assertFalse($this->crear()->hasSimplifiedTransfer());
    }

    public function testSiServiceLanzaOtroErrorDevuelveFalse(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->service->method('getPaymentMethodIds')
            ->willThrowException(new \TypeError('respuesta inesperada'));
        $this->logger->expects($this->once())->method('warning');
        $this->cache->expects($this->never())->method('save');

        $this->assertFalse($this->crear()->hasSimplifiedTransfer());
    }

    public function testSinMerchantIdNoLlamaAKhipu(): void
    {
        $this->service->expects($this->never())->method('getPaymentMethodIds');
        $this->logger->expects($this->once())->method('warning');

        $this->assertFalse($this->crear('')->hasSimplifiedTransfer());
    }
}
