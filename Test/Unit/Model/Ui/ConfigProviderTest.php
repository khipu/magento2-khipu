<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Ui;

use Khipu\Payment\Model\Config\MethodsTable;
use Khipu\Payment\Model\Ui\ConfigProvider;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    private function providerCon(array $activos): ConfigProvider
    {
        $tabla = $this->createMock(MethodsTable::class);
        $tabla->method('activeMethods')->willReturn($activos);

        return new ConfigProvider($tabla);
    }

    public function testExponeSoloIdYTituloDeLosActivosEnOrden(): void
    {
        $provider = $this->providerCon([
            [
                'id' => 'SIMPLIFIED_TRANSFER',
                'name' => 'Simplified transfer',
                'logo_url' => 'https://example.test/s.png',
                'active' => true,
                'title' => 'Transferencia simplificada',
                'mode' => 'embedded',
            ],
            [
                'id' => 'LIGOPAY',
                'name' => 'LigoPay',
                'logo_url' => 'https://example.test/l.png',
                'active' => true,
                'title' => 'QR',
                'mode' => 'redirect',
            ],
        ]);

        $this->assertSame(
            ['payment' => ['khipu' => ['methods' => [
                ['id' => 'SIMPLIFIED_TRANSFER', 'title' => 'Transferencia simplificada'],
                ['id' => 'LIGOPAY', 'title' => 'QR'],
            ]]]],
            $provider->getConfig()
        );
    }

    public function testSinMediosEntregaListaVacia(): void
    {
        $this->assertSame(
            ['payment' => ['khipu' => ['methods' => []]]],
            $this->providerCon([])->getConfig()
        );
    }
}
