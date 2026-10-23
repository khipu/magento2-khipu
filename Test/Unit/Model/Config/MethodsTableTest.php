<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Config;

use Khipu\Payment\Model\Config\MethodsTable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MethodsTableTest extends TestCase
{
    private WriterInterface&MockObject $writer;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(WriterInterface::class);
    }

    private function crear(?string $json, string $merchantId = '505127'): MethodsTable
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn($path) => match ($path) {
                'payment/simplified/merchant_id' => $merchantId,
                MethodsTable::PATH => $json,
                default => null,
            }
        );

        return new MethodsTable($scopeConfig, $this->writer);
    }

    private function tabla(string $merchantId = '505127'): array
    {
        return ['merchant_id' => $merchantId, 'fetched_at' => '2026-10-21T15:47:00+00:00', 'methods' => [
            ['id' => 'SIMPLIFIED_TRANSFER', 'name' => 'S', 'logo_url' => 'l', 'active' => true,
                'title' => 'Transferencia simplificada', 'mode' => 'embedded'],
            ['id' => 'LIGOPAY', 'name' => 'L', 'logo_url' => 'l', 'active' => false,
                'title' => 'LigoPay', 'mode' => 'redirect'],
            ['id' => 'REGULAR', 'name' => 'R', 'logo_url' => 'l', 'active' => true,
                'title' => 'Transferencia manual', 'mode' => 'redirect'],
        ]];
    }

    public function testSinValorDevuelveNullYNoHayMedios(): void
    {
        $t = $this->crear(null);
        $this->assertNull($t->get());
        $this->assertSame([], $t->activeMethods());
        $this->assertNull($t->findActive('LIGOPAY'));
    }

    public function testJsonCorruptoEsComoNoTenerTabla(): void
    {
        $t = $this->crear('{no json');
        $this->assertNull($t->get());
        $this->assertSame([], $t->activeMethods());
    }

    public function testActiveMethodsDevuelveSoloActivosEnOrden(): void
    {
        $t = $this->crear(json_encode($this->tabla()));
        $this->assertSame(['SIMPLIFIED_TRANSFER', 'REGULAR'], array_column($t->activeMethods(), 'id'));
        $this->assertSame('REGULAR', $t->findActive('REGULAR')['id']);
        $this->assertNull($t->findActive('LIGOPAY'));
    }

    public function testUnaTablaGuardadaConLaSimplificadaAlFinalSeLeeConEllaPrimero(): void
    {
        $tabla = $this->tabla();
        $tabla['methods'] = [$tabla['methods'][2], $tabla['methods'][1], $tabla['methods'][0]];
        $t = $this->crear(json_encode($tabla));

        $this->assertSame(['SIMPLIFIED_TRANSFER', 'REGULAR', 'LIGOPAY'], array_column($t->get()['methods'], 'id'));
        $this->assertSame(['SIMPLIFIED_TRANSFER', 'REGULAR'], array_column($t->activeMethods(), 'id'));
    }

    public function testDebitoDirectoYaGuardadoNoSeMuestraNiSeAcepta(): void
    {
        $tabla = $this->tabla();
        $tabla['methods'][] = ['id' => 'DIRECT_DEBIT', 'name' => 'DIRECT_DEBIT', 'logo_url' => '',
            'active' => true, 'title' => 'DIRECT_DEBIT', 'mode' => 'redirect'];
        $t = $this->crear(json_encode($tabla));

        $this->assertNotContains('DIRECT_DEBIT', array_column($t->get()['methods'], 'id'));
        $this->assertSame(['SIMPLIFIED_TRANSFER', 'REGULAR'], array_column($t->activeMethods(), 'id'));
        $this->assertNull($t->findActive('DIRECT_DEBIT'));
    }

    public function testSoloEstaConectadaSiHayTablaDelMerchantActual(): void
    {
        $this->assertFalse($this->crear(null)->isConfigured());
        $this->assertFalse($this->crear('{no json')->isConfigured());
        $this->assertFalse($this->crear(json_encode($this->tabla('111')))->isConfigured());
        $this->assertTrue($this->crear(json_encode($this->tabla()))->isConfigured());
    }

    public function testMerchantDistintoNoTieneMediosActivos(): void
    {
        $t = $this->crear(json_encode($this->tabla('111')));
        $this->assertSame([], $t->activeMethods());
        $this->assertNull($t->findActive('LIGOPAY'));
    }

    public function testMerchantConEspaciosSeComparaRecortado(): void
    {
        $t = $this->crear(json_encode($this->tabla()), ' 505127 ');
        $this->assertCount(2, $t->activeMethods());
    }

    public function testSaveEscribeJsonEnScopeDefault(): void
    {
        $tabla = $this->tabla();
        $this->writer->expects($this->once())->method('save')
            ->with(MethodsTable::PATH, json_encode($tabla), 'default', 0);

        $this->crear(null)->save($tabla);
    }
}
