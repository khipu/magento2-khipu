<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Config\Backend;

use Khipu\Payment\Model\Config\Backend\PaymentMethodsTable;
use Khipu\Payment\Model\Config\MethodsTable;
use Khipu\Payment\Model\Config\MethodsTableMerger;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PaymentMethodsTableTest extends TestCase
{
    private MethodsTable&MockObject $methodsTable;

    protected function setUp(): void
    {
        $this->methodsTable = $this->createMock(MethodsTable::class);
    }

    private function crear(): PaymentMethodsTable
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        return new PaymentMethodsTable(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->methodsTable,
            new MethodsTableMerger()
        );
    }

    private function tabla(): array
    {
        return ['merchant_id' => '505127', 'fetched_at' => 'x', 'methods' => [
            ['id' => 'LIGOPAY', 'name' => 'L', 'logo_url' => 'l', 'active' => true,
                'title' => 'LigoPay', 'mode' => 'redirect'],
            ['id' => 'REGULAR', 'name' => 'R', 'logo_url' => 'l', 'active' => true,
                'title' => 'Transferencia manual', 'mode' => 'redirect'],
        ]];
    }

    public function testBeforeSaveAplicaLasEdicionesSobreLaTablaGuardada(): void
    {
        $this->methodsTable->method('get')->willReturn($this->tabla());
        $b = $this->crear();
        $b->setValue(['rows' => ['LIGOPAY' => ['active' => '0', 'title' => 'QR']]]);
        $b->beforeSave();

        $res = json_decode((string)$b->getValue(), true);
        $this->assertFalse($res['methods'][0]['active']);
        $this->assertSame('QR', $res['methods'][0]['title']);
        $this->assertSame('505127', $res['merchant_id']);
        $this->assertCount(2, $res['methods']);
    }

    public function testFilaApagadaConCamposBloqueadosConservaTituloYModo(): void
    {
        // Con el switch en No, el navegador no envía el título ni el modo
        // (inputs deshabilitados); solo llega el hidden active=0.
        $tabla = $this->tabla();
        $tabla['methods'][] = ['id' => 'SIMPLIFIED_TRANSFER', 'name' => 'S', 'logo_url' => 'l',
            'active' => true, 'title' => 'Simplificada', 'mode' => 'embedded'];
        $this->methodsTable->method('get')->willReturn($tabla);
        $b = $this->crear();
        $b->setValue(['rows' => ['SIMPLIFIED_TRANSFER' => ['active' => '0']]]);
        $b->beforeSave();

        $res = json_decode((string)$b->getValue(), true);
        $this->assertFalse($res['methods'][2]['active']);
        $this->assertSame('Simplificada', $res['methods'][2]['title']);
        $this->assertSame('embedded', $res['methods'][2]['mode']);
    }

    public function testSinTablaGuardadaNoGuardaNada(): void
    {
        $this->methodsTable->method('get')->willReturn(null);
        $b = $this->crear();
        $b->setValue(['rows' => ['X' => ['active' => '1', 'title' => 'inventada']]]);
        $b->beforeSave();

        $this->assertSame('', (string)$b->getValue());
    }

    public function testValorNoArrayNoTocaLaTabla(): void
    {
        $this->methodsTable->method('get')->willReturn($this->tabla());
        $b = $this->crear();
        $b->setValue('basura');
        $b->beforeSave();

        $this->assertSame($this->tabla(), json_decode((string)$b->getValue(), true));
    }
}
