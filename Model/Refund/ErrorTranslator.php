<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Refund;

/**
 * Traduce un error de Khipu al mensaje que ve el administrador.
 *
 * "no es reembolsable" es AMBIGUO: puede ser pago agotado, reversado por otro
 * medio, flag de billetera apagado, o pago de más de 180 días. No se apuesta
 * por una única causa: se nombran las cuatro y se apunta al log, donde queda
 * el texto crudo, para el detalle.
 */
class ErrorTranslator
{
    private const MSG_BILLETERA = 'La billetera de reversas no está habilitada en tu cuenta Khipu.';
    private const MSG_NO_REEMBOLSABLE = 'Khipu no permite reversar este pago. Revisa que la billetera de reversas esté habilitada en la configuración de Khipu; si lo está, el pago puede estar agotado o tener más de 180 días desde su conciliación. El detalle está en var/log/khipu.log.';
    /**
     * Un httpCode 0 significa que no hubo respuesta HTTP: red, DNS o timeout.
     * CURLOPT_TIMEOUT es el timeout TOTAL de la operación, no el de conexión, así
     * que un timeout ocurre típicamente DESPUÉS de que el POST llegó y el servidor
     * lo procesó. Khipu pudo haber encolado la reversa y solo haber tardado en
     * responder. Afirmarle al admin que "no se realizó" es justo la información
     * que lo lleva a reintentar a ciegas — y un reintento parcial pasa el backstop
     * de saldo de Khipu, o sea que duplica la devolución.
     */
    private const MSG_RED = 'No se recibió respuesta de Khipu, así que no se puede saber si la reversa se '
        . 'encoló o no: PUEDE haberse realizado igual. Antes de reintentar, revisa var/log/khipu.log y el '
        . 'estado del pago en el panel de Khipu — un reintento a ciegas puede duplicar la reversa.';

    /**
     * Un 200 al que `Service::exigirCampos()` le rechaza un campo obligatorio NO
     * es un "no pasó nada": el POST se completó, así que Khipu (o lo que sea que
     * contestó) recibió la orden de reversa y la plata pudo haberse movido. Solo
     * no se pudo interpretar la respuesta. Es el mismo peligro que el httpCode 0
     * — reintentar a ciegas puede duplicar la reversa — así que lleva la misma
     * advertencia.
     */
    private const MSG_200_INVALIDO = 'Khipu respondió 200, así que la reversa pudo haberse procesado igual: '
        . 'PUEDE haberse realizado aunque no se pudo interpretar la respuesta. Antes de reintentar, revisa '
        . 'var/log/khipu.log y el estado de la reversa en el panel de Khipu — un reintento a ciegas puede '
        . 'duplicar la reversa.';

    public function toAdminMessage(RefundException $e): string
    {
        if ($e->getHttpCode() === 0) {
            return self::MSG_RED;
        }

        if ($e->getHttpCode() === 200) {
            return self::MSG_200_INVALIDO;
        }

        $mensaje = $e->getMessage();

        // Este error SÍ es inequívoco: la cuenta no tiene el flag.
        //
        // Se compara solo 'no está habilitada' y no la frase completa: Khipu
        // cambia su vocabulario (verificado el 2026-08-26, pasó de decir
        // "reversa" a "devolución" en el mensaje de pendiente). Anclar a la cola
        // que cambia haría que este gate dejara de calzar EN SILENCIO.
        if (stripos($mensaje, 'no está habilitada') !== false) {
            return self::MSG_BILLETERA;
        }

        // Este es AMBIGUO. No se apuesta por una causa: se nombran todas y se
        // apunta al log. El admin ya conoce el estado de su billetera por la
        // sección de configuración (Task 9), así que afirmarle que está
        // deshabilitada sería falso en la mayoría de los casos.
        if (stripos($mensaje, 'no es reembolsable') !== false) {
            return self::MSG_NO_REEMBOLSABLE;
        }

        return $mensaje;
    }
}
