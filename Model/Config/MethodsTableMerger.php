<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Config;

/**
 * Lógica pura de la tabla de medios de pago: fusiona lo que informa Khipu con lo
 * que el comercio ya configuró y aplica las ediciones del formulario del admin.
 *
 * Las constantes replican PaymentMethods::SIMPLIFIED_TRANSFER y Simplified::MODE_*
 * para mantener esta clase sin dependencias.
 */
class MethodsTableMerger
{
    private const SIMPLIFIED_TRANSFER = 'SIMPLIFIED_TRANSFER';
    private const MODE_EMBEDDED = 'embedded';
    private const MODE_REDIRECT = 'redirect';

    private const DEFAULT_TITLES = [
        'SIMPLIFIED_TRANSFER' => 'Transferencia simplificada',
        'LIGOPAY' => 'LigoPay',
        'REGULAR' => 'Transferencia normal',
        'REGULAR_TRANSFER' => 'Transferencia normal',
    ];

    /** Medios que Khipu informa pero el plugin no ofrece: no entran a la tabla ni al checkout. */
    public const IGNORED_METHODS = ['DIRECT_DEBIT'];

    /**
     * @param array|null $actual tabla guardada (o null)
     * @param array $deKhipu list<array{id:string,name:string,logo_url:string}>
     */
    public function merge(
        ?array $actual,
        array $deKhipu,
        string $merchantId,
        string $fetchedAt,
        string $modoInicialSimplificada
    ): array {
        $previas = [];
        if ($actual !== null && (string)($actual['merchant_id'] ?? '') === $merchantId) {
            foreach ((array)($actual['methods'] ?? []) as $fila) {
                if (isset($fila['id'])) {
                    $previas[(string)$fila['id']] = $fila;
                }
            }
        }

        $modoInicial = $this->modoValido($modoInicialSimplificada) ?? self::MODE_REDIRECT;

        $methods = [];
        foreach ($deKhipu as $medio) {
            $id = (string)$medio['id'];
            if (in_array($id, self::IGNORED_METHODS, true)) {
                continue;
            }
            $name = (string)($medio['name'] ?? '');
            $previa = $previas[$id] ?? null;

            if ($previa !== null) {
                $active = (bool)($previa['active'] ?? true);
                $title = (string)($previa['title'] ?? '');
                if ($title === '') {
                    $title = $this->defaultTitle($id, $name);
                }
                $mode = (string)($previa['mode'] ?? self::MODE_REDIRECT);
            } else {
                $active = true;
                $title = $this->defaultTitle($id, $name);
                $mode = $modoInicial;
            }

            $methods[] = [
                'id' => $id,
                'name' => $name,
                'logo_url' => (string)($medio['logo_url'] ?? ''),
                'active' => $active,
                'title' => $title,
                'mode' => $id === self::SIMPLIFIED_TRANSFER && $this->modoValido($mode) !== null
                    ? $mode
                    : self::MODE_REDIRECT,
            ];
        }

        return ['merchant_id' => $merchantId, 'fetched_at' => $fetchedAt, 'methods' => self::ordenar($methods)];
    }

    /**
     * La simplificada va primero (es el medio principal de Khipu); el resto
     * mantiene el orden en que lo informa Khipu. Ese orden es el del checkout.
     */
    public static function ordenar(array $methods): array
    {
        $primero = [];
        $resto = [];
        foreach ($methods as $fila) {
            if (($fila['id'] ?? null) === self::SIMPLIFIED_TRANSFER) {
                $primero[] = $fila;
            } else {
                $resto[] = $fila;
            }
        }

        return array_merge($primero, $resto);
    }

    /**
     * @param array $filas [id => ['active' => '1'|'0', 'title' => string, 'mode' => string]]
     */
    public function applyEdits(array $tabla, array $filas): array
    {
        foreach ($tabla['methods'] as $i => $fila) {
            $id = (string)$fila['id'];
            if (!isset($filas[$id]) || !is_array($filas[$id])) {
                continue;
            }
            $edit = $filas[$id];

            $tabla['methods'][$i]['active'] = (string)($edit['active'] ?? '0') === '1';

            $title = trim((string)($edit['title'] ?? ''));
            if ($title !== '') {
                $tabla['methods'][$i]['title'] = $title;
            }

            if ($id === self::SIMPLIFIED_TRANSFER) {
                $modo = $this->modoValido((string)($edit['mode'] ?? ''));
                if ($modo !== null) {
                    $tabla['methods'][$i]['mode'] = $modo;
                }
            } else {
                $tabla['methods'][$i]['mode'] = self::MODE_REDIRECT;
            }
        }

        return $tabla;
    }

    public function defaultTitle(string $id, string $name): string
    {
        if (isset(self::DEFAULT_TITLES[$id])) {
            return self::DEFAULT_TITLES[$id];
        }

        return trim($name) !== '' ? $name : $id;
    }

    private function modoValido(string $modo): ?string
    {
        return in_array($modo, [self::MODE_EMBEDDED, self::MODE_REDIRECT], true) ? $modo : null;
    }
}
