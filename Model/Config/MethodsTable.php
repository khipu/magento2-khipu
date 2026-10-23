<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Lee y escribe la tabla de medios de pago (JSON en la configuración, scope default).
 */
class MethodsTable
{
    public const PATH = 'payment/simplified/payment_methods';
    private const MERCHANT_PATH = 'payment/simplified/merchant_id';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter
    ) {
    }

    public function get(): ?array
    {
        $raw = $this->scopeConfig->getValue(self::PATH);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $tabla = json_decode($raw, true);
        if (!is_array($tabla) || !isset($tabla['methods']) || !is_array($tabla['methods'])) {
            return null;
        }

        // Una tabla guardada antes de ignorar un medio, o antes de ordenar, todavía
        // puede traerlo o venir en otro orden.
        $tabla['methods'] = MethodsTableMerger::ordenar(array_values(array_filter(
            $tabla['methods'],
            static fn($fila) => !in_array($fila['id'] ?? null, MethodsTableMerger::IGNORED_METHODS, true)
        )));

        return $tabla;
    }

    public function save(array $tabla): void
    {
        $this->configWriter->save(self::PATH, json_encode($tabla), 'default', 0);
    }

    /**
     * Hay una tabla obtenida con "Conectar" para el Merchant_ID configurado.
     * Si no la hay (recién actualizado desde 2.7.x, o cambió la cuenta), el
     * checkout muestra el botón único de Khipu como antes.
     */
    public function isConfigured(): bool
    {
        return $this->tablaDelMerchantActual() !== null;
    }

    /**
     * Filas activas, en orden. Vacío si no hay tabla o es de otra cuenta.
     */
    public function activeMethods(): array
    {
        $tabla = $this->tablaDelMerchantActual();
        if ($tabla === null) {
            return [];
        }

        return array_values(array_filter(
            $tabla['methods'],
            static fn($fila) => is_array($fila) && ($fila['active'] ?? false) === true
        ));
    }

    private function tablaDelMerchantActual(): ?array
    {
        $tabla = $this->get();
        if ($tabla === null) {
            return null;
        }

        $merchantId = trim((string)$this->scopeConfig->getValue(self::MERCHANT_PATH));

        return trim((string)($tabla['merchant_id'] ?? '')) === $merchantId ? $tabla : null;
    }

    public function findActive(string $id): ?array
    {
        foreach ($this->activeMethods() as $fila) {
            if (($fila['id'] ?? null) === $id) {
                return $fila;
            }
        }

        return null;
    }
}
