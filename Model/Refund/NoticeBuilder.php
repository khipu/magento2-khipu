<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Refund;

/**
 * Construye la nota del pedido y el aviso en pantalla de una reversa exitosa.
 *
 * Regla del proyecto: una reversa PENDIENTE es ÉXITO, no advertencia. Toda
 * reversa arranca pendiente y se concreta en el lote diario de Khipu. Solo se
 * usa tono de advertencia cuando el saldo de la billetera podría no alcanzar.
 *
 * Los montos NO tienen valor por defecto. La nota del pedido es el registro
 * permanente de una operación de dinero (la API de Khipu no permite consultar
 * reversas después), así que un `?? 0` la haría decir "reversado: $0" sobre una
 * reversa real. Peor: en successMessage() apagaría la heurística de saldo, que
 * es la única señal de "tu billetera puede no alcanzar". Si un campo falta, se
 * revienta — y en la práctica no llega hasta acá, porque Refund\Service ya
 * exige los campos obligatorios de cada endpoint.
 */
class NoticeBuilder
{
    public function orderNote(\stdClass $r, string $currency): string
    {
        $reversado = $this->formatMoney($this->campo($r, 'refunded_amount'), $currency);
        $restante = $this->formatMoney($this->campo($r, 'remaining'), $currency);

        if (!empty($r->message)) {
            return sprintf(
                'Reversa Khipu solicitada, queda pendiente: "%s" '
                . '(reversado en esta operación: %s, restante por reversar: %s)',
                $r->message,
                $reversado,
                $restante
            );
        }

        return sprintf(
            'Reversa Khipu concretada: %s (total reversado del pedido: %s, restante por reversar: %s)',
            $reversado,
            $this->formatMoney($this->campo($r, 'total_refunded'), $currency),
            $restante
        );
    }

    /**
     * @param float|null $walletBalance null si no se pudo consultar el saldo.
     * @return array{tipo: string, texto: string}
     */
    public function successMessage(\stdClass $r, ?float $walletBalance): array
    {
        if (empty($r->message)) {
            return ['tipo' => 'success', 'texto' => 'Reversa realizada con éxito.'];
        }

        $reversado = (float) $this->campo($r, 'refunded_amount');
        $saldoAlcanza = $walletBalance === null || $walletBalance >= $reversado;

        if ($saldoAlcanza) {
            return [
                'tipo' => 'success',
                'texto' => 'Reversa solicitada correctamente. Quedó en proceso y se '
                    . 'concretará en el próximo ciclo de reversas de Khipu.',
            ];
        }

        return [
            'tipo' => 'warning',
            'texto' => 'Reversa solicitada: quedó en proceso, pero el saldo de la billetera '
                . 'de reversas podría no alcanzar para concretarla. Recárgala para asegurar '
                . 'que se complete en el ciclo de Khipu.',
        ];
    }

    /**
     * Campo obligatorio de la respuesta de Khipu. Explota en vez de inventar un
     * cero: ver el docblock de la clase.
     *
     * @return mixed
     * @throws \InvalidArgumentException
     */
    private function campo(\stdClass $r, string $nombre)
    {
        if (!isset($r->$nombre)) {
            throw new \InvalidArgumentException(
                sprintf('La respuesta de reversa de Khipu no trae el campo obligatorio "%s".', $nombre)
            );
        }

        return $r->$nombre;
    }

    private function formatMoney($amount, string $currency): string
    {
        $decimales = in_array($currency, ['CLP', 'COP'], true) ? 0 : ($currency === 'CLF' ? 4 : 2);

        return '$' . number_format((float) $amount, $decimales);
    }
}
