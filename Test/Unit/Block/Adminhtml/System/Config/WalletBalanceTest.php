<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Block\Adminhtml\System\Config;

use Khipu\Payment\Block\Adminhtml\System\Config\WalletBalance;
use Khipu\Payment\Model\Refund\RefundException;
use Khipu\Payment\Model\Refund\Service;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WalletBalanceTest extends TestCase
{
    private Service&MockObject $service;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private WalletBalance $block;

    protected function setUp(): void
    {
        $this->service = $this->createMock(Service::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $this->block = $this->getMockBuilder(WalletBalance::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Inyección manual: el constructor del bloque de Magento pide un Context
        // que no aporta nada al comportamiento bajo prueba.
        $reflection = new \ReflectionClass(WalletBalance::class);
        foreach (['refundService' => $this->service, '_scopeConfig' => $this->scopeConfig] as $prop => $valor) {
            $p = $reflection->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($this->block, $valor);
        }
    }

    public function testSinApiKeyPideCompletarLosDatos(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->service->expects($this->never())->method('getWalletBalance');

        $html = $this->block->getWalletHtml();

        $this->assertStringContainsString('Complete los datos para poder visualizar la billetera.', $html);
    }

    public function testCuentaSinFlagAvisaQueNoEstaHabilitada(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('LA-API-KEY');
        $this->service->method('getWalletBalance')->willThrowException(
            new RefundException(
                'La cuenta de cobro con ID 486463 no está habilitada para hacer reversas.',
                400
            )
        );

        $html = $this->block->getWalletHtml();

        $this->assertStringContainsString(
            'La billetera de reversas no está habilitada en tu cuenta Khipu.',
            $html
        );
        $this->assertStringNotContainsString('Recargar', $html);
    }

    public function testErrorDeKhipuMandaARevisarCredencialesYHabilitacion(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('LA-API-KEY');
        $this->service->method('getWalletBalance')->willThrowException(
            new RefundException('Unauthorized', 401)
        );

        $html = $this->block->getWalletHtml();

        $this->assertStringContainsString('Revisa que la API Key sea correcta', $html);
        $this->assertStringContainsString('esté habilitada en tu cuenta Khipu', $html);
    }

    public function testSinRespuestaDeKhipuNoCulpaALasCredenciales(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('LA-API-KEY');
        $this->service->method('getWalletBalance')->willThrowException(
            new RefundException('Connection timed out', 0)
        );

        $html = $this->block->getWalletHtml();

        $this->assertStringContainsString('No se pudo comunicar con Khipu', $html);
        // Mandar a revisar credenciales cuando Khipu no respondió es mandar
        // al comercio a buscar donde no es.
        $this->assertStringNotContainsString('API Key', $html);
    }

    public function testSaldoOkMuestraElMontoYElLinkDeRecarga(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('LA-API-KEY');
        $this->service->method('getWalletBalance')->willReturn((object) [
            'balance' => '12000.0000',
            'currency' => 'CLP',
            'add_funds_url' => 'https://khipu.com/dashboard/bills',
        ]);

        $html = $this->block->getWalletHtml();

        $this->assertStringContainsString('$12.000', $html);
        $this->assertStringContainsString('CLP', $html);
        $this->assertStringContainsString('https://khipu.com/dashboard/bills', $html);
        $this->assertStringContainsString('Recargar', $html);
    }
}
