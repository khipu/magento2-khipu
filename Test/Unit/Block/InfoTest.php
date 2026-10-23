<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Block;

use Khipu\Payment\Block\Info;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;

class InfoTest extends TestCase
{
    private function bloqueCon(?string $titulo): Info
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn($key = null) => $key === 'khipu_payment_method_title' ? $titulo : null
        );

        // Sin constructor: Template pide el contexto completo de la vista y el
        // bloque solo necesita la info del pago.
        $bloque = (new \ReflectionClass(Info::class))->newInstanceWithoutConstructor();
        $bloque->setData('info', $payment);

        return $bloque;
    }

    public function testMuestraElMedioDeKhipuElegido(): void
    {
        $this->assertSame(
            ['Medio de pago' => 'LigoPay'],
            $this->bloqueCon('LigoPay')->getSpecificInformation()
        );
    }

    public function testSinMedioNoAgregaNada(): void
    {
        $this->assertSame([], $this->bloqueCon(null)->getSpecificInformation());
    }
}
