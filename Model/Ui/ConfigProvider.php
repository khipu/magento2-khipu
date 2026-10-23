<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Ui;

use Khipu\Payment\Model\Config\MethodsTable;
use Magento\Checkout\Model\ConfigProviderInterface;

/**
 * Expone al checkout los medios de pago de Khipu activos (solo id y título),
 * en window.checkoutConfig.payment.khipu.methods.
 *
 * En el checkout cada botón se ve como "Khipu - {medio}"; en la tabla del admin
 * el comercio edita solo el nombre del medio.
 */
class ConfigProvider implements ConfigProviderInterface
{
    private const PREFIJO = 'Khipu';

    public function __construct(
        private readonly MethodsTable $methodsTable
    ) {
    }

    public function getConfig(): array
    {
        $medios = array_map(
            fn(array $fila) => [
                'id' => (string)$fila['id'],
                'title' => $this->conPrefijo((string)($fila['title'] ?? $fila['id'])),
            ],
            $this->methodsTable->activeMethods()
        );

        return ['payment' => ['khipu' => ['methods' => $medios]]];
    }

    private function conPrefijo(string $medio): string
    {
        if (stripos($medio, self::PREFIJO) === 0) {
            return $medio;
        }

        return self::PREFIJO . ' - ' . $medio;
    }
}
