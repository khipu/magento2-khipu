<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Ui;

use Khipu\Payment\Model\Config\MethodsTable;
use Magento\Checkout\Model\ConfigProviderInterface;

/**
 * Expone al checkout los medios de pago de Khipu activos (solo id y título),
 * en window.checkoutConfig.payment.khipu.methods.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly MethodsTable $methodsTable
    ) {
    }

    public function getConfig(): array
    {
        $medios = array_map(
            static fn(array $fila) => [
                'id' => (string)$fila['id'],
                'title' => (string)($fila['title'] ?? $fila['id']),
            ],
            $this->methodsTable->activeMethods()
        );

        return ['payment' => ['khipu' => ['methods' => $medios]]];
    }
}
