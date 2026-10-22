<?php
declare(strict_types=1);

namespace Khipu\Payment\Test\Unit\Controller\Payment;

use Khipu\Payment\Controller\Payment\Callback;
use Khipu\Payment\Model\Refund\CaptureRegistrar;
use Khipu\Payment\Model\Simplified;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;

class CallbackTest extends TestCase
{
    private Callback $callback;

    protected function setUp(): void
    {
        // En modo developer Magento convierte los warnings en excepciones: el test hace lo mismo.
        set_error_handler(static function (int $nivel, string $mensaje): bool {
            throw new \ErrorException($mensaje, 0, $nivel);
        }, E_WARNING | E_NOTICE);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn($path) => match ($path) {
            'payment/simplified/merchant_id' => '505127',
            'payment/simplified/payment_complete_status' => 'complete',
            default => null,
        });

        $khipuPayment = $this->createMock(Simplified::class);
        $khipuPayment->method('getDecimalPlaces')->willReturn(2);

        $this->callback = (new \ReflectionClass(Callback::class))->newInstanceWithoutConstructor();
        $this->inyectar('scopeConfig', $scopeConfig);
        $this->inyectar('khipuPayment', $khipuPayment);
        $this->inyectar('captureRegistrar', $this->createMock(CaptureRegistrar::class));
        $this->inyectar('orderSender', $this->createMock(OrderSender::class));
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    private function inyectar(string $propiedad, $valor): void
    {
        $prop = new \ReflectionProperty(Callback::class, $propiedad);
        $prop->setAccessible(true);
        $prop->setValue($this->callback, $valor);
    }

    private function orden(): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->with('khipu_order_token')->willReturn('token-1');

        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateDefaultStatus')->willReturn('complete');

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000000088');
        $order->method('getGrandTotal')->willReturn(1.0);
        $order->method('getOrderCurrencyCode')->willReturn('PEN');
        $order->method('getPayment')->willReturn($payment);
        $order->method('prepareInvoice')->willReturn($this->createMock(Invoice::class));
        $order->method('getConfig')->willReturn($config);

        return $order;
    }

    /** Notificación real de un pago LigoPay (sin los campos de banco que trae una transferencia). */
    private function notificacionLigoPay(): array
    {
        return [
            'payment_id' => 'q3prvfhnazc9',
            'receiver_id' => 505127,
            'subject' => 'Main Website Carro #000000088',
            'amount' => '1.00',
            'discount' => '0.00',
            'currency' => 'PEN',
            'body' => '1 × Producto',
            'receipt_url' => 'https://khipu.com/receipt',
            'payer_name' => '',
            'payer_email' => 'cliente@example.com',
            'personal_identifier' => '',
            'bank_account_number' => '',
            'out_of_date_conciliation' => false,
            'transaction_id' => '000000088',
            'custom' => 'token-1',
            'responsible_user_email' => '',
            'payment_method' => 'ligopay',
            'conciliation_date' => '2026-10-22T19:18:00Z',
        ];
    }

    public function testUnPagoLigoPaySinDatosDeBancoMarcaElPedidoComoPagado(): void
    {
        $order = $this->orden();
        $order->expects($this->once())->method('addStatusToHistory')
            ->with('complete', $this->stringContains('Pago Khipu Aceptado'));
        $order->expects($this->once())->method('save');

        $this->callback->validateKhipuCallback($order, $this->notificacionLigoPay(), '3.0');
    }

    public function testElComentarioIncluyeElMedioDePago(): void
    {
        $order = $this->orden();
        $order->expects($this->once())->method('addStatusToHistory')
            ->with('complete', $this->stringContains('Payment Method: ligopay'));

        $this->callback->validateKhipuCallback($order, $this->notificacionLigoPay(), '3.0');
    }
}
