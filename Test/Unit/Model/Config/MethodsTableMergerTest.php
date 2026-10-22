<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Model\Config;

use Khipu\Payment\Model\Config\MethodsTableMerger;
use PHPUnit\Framework\TestCase;

class MethodsTableMergerTest extends TestCase
{
    private const FECHA = '2026-10-21T15:47:00+00:00';

    private function deKhipu(array $ids): array
    {
        return array_map(
            static fn(string $id) => ['id' => $id, 'name' => 'Nombre ' . $id, 'logo_url' => 'https://logo/' . $id],
            $ids
        );
    }

    private function fila(string $id, bool $active = true, ?string $title = null, string $mode = 'redirect'): array
    {
        return ['id' => $id, 'name' => 'Viejo', 'logo_url' => 'viejo', 'active' => $active,
            'title' => $title ?? $id, 'mode' => $mode];
    }

    private function porId(array $tabla): array
    {
        $res = [];
        foreach ($tabla['methods'] as $m) {
            $res[$m['id']] = $m;
        }
        return $res;
    }

    public function testSinTablaPreviaTodosEntranActivosConTituloPorDefecto(): void
    {
        $tabla = (new MethodsTableMerger())->merge(
            null,
            $this->deKhipu(['SIMPLIFIED_TRANSFER', 'LIGOPAY', 'REGULAR']),
            '505127',
            self::FECHA,
            'embedded'
        );

        $this->assertSame('505127', $tabla['merchant_id']);
        $this->assertSame(self::FECHA, $tabla['fetched_at']);
        $m = $this->porId($tabla);
        $this->assertTrue($m['SIMPLIFIED_TRANSFER']['active']);
        $this->assertTrue($m['LIGOPAY']['active']);
        $this->assertTrue($m['REGULAR']['active']);
        $this->assertSame('Transferencia simplificada', $m['SIMPLIFIED_TRANSFER']['title']);
        $this->assertSame('LigoPay', $m['LIGOPAY']['title']);
        $this->assertSame('Transferencia normal', $m['REGULAR']['title']);
        $this->assertSame('embedded', $m['SIMPLIFIED_TRANSFER']['mode']);
        $this->assertSame('redirect', $m['LIGOPAY']['mode']);
        $this->assertSame('redirect', $m['REGULAR']['mode']);
    }

    public function testLaSimplificadaVaPrimeroYElRestoEnElOrdenDeKhipu(): void
    {
        $tabla = (new MethodsTableMerger())->merge(
            null,
            $this->deKhipu(['REGULAR_TRANSFER', 'LIGOPAY', 'SIMPLIFIED_TRANSFER']),
            '501986',
            self::FECHA,
            'embedded'
        );

        $this->assertSame(
            ['SIMPLIFIED_TRANSFER', 'REGULAR_TRANSFER', 'LIGOPAY'],
            array_column($tabla['methods'], 'id')
        );
        $this->assertSame('Transferencia normal', $tabla['methods'][1]['title']);
    }

    public function testDebitoDirectoNoEntraALaTabla(): void
    {
        $tabla = (new MethodsTableMerger())->merge(
            null,
            $this->deKhipu(['REGULAR_TRANSFER', 'DIRECT_DEBIT', 'SIMPLIFIED_TRANSFER']),
            '501986',
            self::FECHA,
            'embedded'
        );

        $this->assertSame(['SIMPLIFIED_TRANSFER', 'REGULAR_TRANSFER'], array_column($tabla['methods'], 'id'));
    }

    public function testModoInicialRedireccionSeAplicaSoloALaSimplificada(): void
    {
        $tabla = (new MethodsTableMerger())->merge(
            null,
            $this->deKhipu(['SIMPLIFIED_TRANSFER', 'LIGOPAY']),
            '505127',
            self::FECHA,
            'redirect'
        );

        $this->assertSame('redirect', $this->porId($tabla)['SIMPLIFIED_TRANSFER']['mode']);
    }

    public function testReconectarConservaLoQueElComercioConfiguro(): void
    {
        $previa = ['merchant_id' => '505127', 'fetched_at' => 'antes', 'methods' => [
            $this->fila('SIMPLIFIED_TRANSFER', true, 'Transferencia simplificada', 'redirect'),
            $this->fila('LIGOPAY', false, 'Paga con QR'),
        ]];

        $tabla = (new MethodsTableMerger())->merge(
            $previa,
            $this->deKhipu(['SIMPLIFIED_TRANSFER', 'LIGOPAY']),
            '505127',
            self::FECHA,
            'embedded'
        );

        $m = $this->porId($tabla);
        $this->assertSame('redirect', $m['SIMPLIFIED_TRANSFER']['mode']);
        $this->assertFalse($m['LIGOPAY']['active']);
        $this->assertSame('Paga con QR', $m['LIGOPAY']['title']);
        $this->assertSame('Nombre LIGOPAY', $m['LIGOPAY']['name']);
        $this->assertSame('https://logo/LIGOPAY', $m['LIGOPAY']['logo_url']);
        $this->assertSame(self::FECHA, $tabla['fetched_at']);
    }

    public function testMedioNuevoEntraActivoYMedioQuitadoDesaparece(): void
    {
        $previa = ['merchant_id' => '505127', 'fetched_at' => 'antes', 'methods' => [
            $this->fila('SIMPLIFIED_TRANSFER'),
            $this->fila('REGULAR'),
        ]];

        $tabla = (new MethodsTableMerger())->merge(
            $previa,
            $this->deKhipu(['SIMPLIFIED_TRANSFER', 'LIGOPAY']),
            '505127',
            self::FECHA,
            'embedded'
        );

        $this->assertSame(['SIMPLIFIED_TRANSFER', 'LIGOPAY'], array_column($tabla['methods'], 'id'));
        $this->assertTrue($this->porId($tabla)['LIGOPAY']['active']);
    }

    public function testOtraCuentaEmpiezaDeCero(): void
    {
        $previa = ['merchant_id' => '111', 'fetched_at' => 'antes', 'methods' => [
            $this->fila('LIGOPAY', false, 'Paga con QR'),
        ]];

        $tabla = (new MethodsTableMerger())->merge(
            $previa,
            $this->deKhipu(['LIGOPAY']),
            '505127',
            self::FECHA,
            'embedded'
        );

        $m = $this->porId($tabla);
        $this->assertSame('505127', $tabla['merchant_id']);
        $this->assertTrue($m['LIGOPAY']['active']);
        $this->assertSame('LigoPay', $m['LIGOPAY']['title']);
    }

    public function testTituloPorDefectoDeIdDesconocidoUsaNameYSiNoElId(): void
    {
        $merger = new MethodsTableMerger();
        $this->assertSame('Yape', $merger->defaultTitle('YAPE', 'Yape'));
        $this->assertSame('YAPE', $merger->defaultTitle('YAPE', ''));
    }

    public function testApplyEditsCambiaActivoTituloYModo(): void
    {
        $tabla = ['merchant_id' => '505127', 'fetched_at' => self::FECHA, 'methods' => [
            $this->fila('LIGOPAY', true, 'LigoPay'),
        ]];

        $res = (new MethodsTableMerger())->applyEdits($tabla, [
            'LIGOPAY' => ['active' => '0', 'title' => ' QR ', 'mode' => 'embedded'],
        ]);

        $fila = $res['methods'][0];
        $this->assertFalse($fila['active']);
        $this->assertSame('QR', $fila['title']);
        $this->assertSame('redirect', $fila['mode']);
    }

    public function testApplyEditsCambiaModoDeLaSimplificada(): void
    {
        $tabla = ['merchant_id' => '505127', 'fetched_at' => self::FECHA, 'methods' => [
            $this->fila('SIMPLIFIED_TRANSFER', true, 'T', 'embedded'),
        ]];

        $res = (new MethodsTableMerger())->applyEdits($tabla, [
            'SIMPLIFIED_TRANSFER' => ['active' => '1', 'title' => 'T', 'mode' => 'redirect'],
        ]);

        $this->assertSame('redirect', $res['methods'][0]['mode']);
    }

    public function testApplyEditsIgnoraIdsDesconocidosYTituloVacio(): void
    {
        $tabla = ['merchant_id' => '505127', 'fetched_at' => self::FECHA, 'methods' => [
            $this->fila('LIGOPAY', true, 'LigoPay'),
        ]];

        $res = (new MethodsTableMerger())->applyEdits($tabla, [
            'LIGOPAY' => ['active' => '1', 'title' => '', 'mode' => 'redirect'],
            'OTRO' => ['active' => '1', 'title' => 'X', 'mode' => 'redirect'],
        ]);

        $this->assertCount(1, $res['methods']);
        $this->assertSame('LigoPay', $res['methods'][0]['title']);
    }

    public function testApplyEditsRechazaModoInvalido(): void
    {
        $tabla = ['merchant_id' => '505127', 'fetched_at' => self::FECHA, 'methods' => [
            $this->fila('SIMPLIFIED_TRANSFER', true, 'T', 'embedded'),
        ]];

        $res = (new MethodsTableMerger())->applyEdits($tabla, [
            'SIMPLIFIED_TRANSFER' => ['active' => '1', 'title' => 'T', 'mode' => 'otro'],
        ]);

        $this->assertSame('embedded', $res['methods'][0]['mode']);
    }
}
