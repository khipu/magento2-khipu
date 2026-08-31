<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Refund;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\ClientInterface;

/**
 * Cliente HTTP de la API de Khipu v3.
 *
 * No conoce Sales ni pedidos: solo habla HTTP. Usa el cliente Curl de Magento,
 * que mantiene la verificación TLS activa — no deshabilitarla.
 *
 * Validación de respuestas: un 200 con JSON válido NO es prueba de que la
 * operación ocurrió. Un proxy, un WAF o un portal cautivo pueden responder
 * `200 {"message":"..."}`, y sin validar los campos ese cuerpo pasaría como
 * éxito — dejando el pedido marcado como reembolsado con cero pesos devueltos.
 * Por eso cada método público exige los campos que SU endpoint debe traer: la
 * forma de la respuesta es distinta por endpoint, así que la validación vive en
 * el llamador y no dentro de request().
 */
class Service
{
    private const BASE_URL = 'https://payment-api.khipu.com';
    private const TIMEOUT = 20;

    /** La creación del cobro históricamente usó 30 s; se conserva. */
    private const TIMEOUT_PAGO = 30;

    private const API_KEY_PATH = 'payment/simplified/api_key';

    private ClientInterface $http;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(ClientInterface $http, ScopeConfigInterface $scopeConfig)
    {
        $this->http = $http;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * POST /v3/refunds
     *
     * @param string      $type   'partial' o 'full'
     * @param string|null $amount Requerido si $type === 'partial'; prohibido si 'full'.
     * @throws RefundException
     */
    public function refund(string $paymentId, string $type, ?string $amount = null): \stdClass
    {
        $data = ['type' => $type, 'payment_id' => $paymentId];
        if ($type === 'partial') {
            $data['amount'] = (string) $amount;
        }

        $respuesta = $this->request('POST', '/v3/refunds', $this->encodeBody($data));

        // `id` alimenta la fila de sales_payment_transaction (el registro
        // permanente); los tres montos alimentan la nota del pedido y la
        // heurística de saldo; `payment_id` permite contrastar contra lo pedido.
        $this->exigirCampos(
            $respuesta,
            ['id', 'payment_id', 'refunded_amount', 'total_refunded', 'remaining'],
            'POST /v3/refunds'
        );

        return $respuesta;
    }

    /**
     * GET /v3/refund-wallet/balance
     *
     * @throws RefundException
     */
    public function getWalletBalance(): \stdClass
    {
        $respuesta = $this->request('GET', '/v3/refund-wallet/balance');

        $this->exigirCampos($respuesta, ['balance'], 'GET /v3/refund-wallet/balance');

        return $respuesta;
    }



    /**
     * `json_encode()` devuelve `false` (no `null`) cuando el arreglo trae bytes que
     * no son UTF-8 válido — por ejemplo un nombre de producto importado con
     * Latin-1 crudo, que termina en el `body` del cobro. `request()` declara
     * `?string $body` bajo `declare(strict_types=1)`, así que pasarle `false` sin
     * chequear lanza un `TypeError` fatal en vez de un error manejable.
     *
     * @throws RefundException
     */
    private function encodeBody(array $data): string
    {
        $body = json_encode($data);
        if ($body === false) {
            throw new RefundException(
                'No se pudo construir la solicitud a Khipu: los datos contienen texto con '
                . 'codificación inválida (' . json_last_error_msg() . ').',
                0
            );
        }

        return $body;
    }

    /**
     * @param array{api_key?:?string,user_agent?:?string,timeout?:int} $opciones
     * @throws RefundException
     */
    private function request(string $method, string $path, ?string $body = null): \stdClass
    {
        $apiKey = (string) $this->scopeConfig->getValue(self::API_KEY_PATH);

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => (string) $apiKey,
        ];
        if (!empty($opciones['user_agent'])) {
            $headers['User-Agent'] = (string) $opciones['user_agent'];
        }

        $this->http->setTimeout((int) ($opciones['timeout'] ?? self::TIMEOUT));
        $this->http->setHeaders($headers);

        try {
            if ($method === 'POST') {
                $this->http->post(self::BASE_URL . $path, $body);
            } else {
                $this->http->get(self::BASE_URL . $path);
            }
        } catch (\Exception $e) {
            // Sin respuesta HTTP: red, DNS o timeout.
            throw new RefundException($e->getMessage(), 0);
        }

        $status = (int) $this->http->getStatus();
        $raw = (string) $this->http->getBody();
        $decoded = json_decode($raw);

        if ($status === 200) {
            if (!$decoded instanceof \stdClass) {
                throw new RefundException(
                    'Respuesta inválida de Khipu (cuerpo vacío o mal formado).',
                    $status,
                    $raw
                );
            }
            return $decoded;
        }

        throw new RefundException($this->messageFromBody($decoded, $status), $status, $raw);
    }

    /**
     * Un 200 al que le falta un campo obligatorio NO es un éxito: es una
     * respuesta que no vino de Khipu, o que Khipu cambió sin avisar. En
     * cualquiera de los dos casos hay que fallar, no rellenar con ceros.
     *
     * @param string[] $campos
     * @throws RefundException
     */
    private function exigirCampos(\stdClass $respuesta, array $campos, string $endpoint): void
    {
        foreach ($campos as $campo) {
            $valor = $respuesta->$campo ?? null;

            if ($valor === null || (is_string($valor) && trim($valor) === '')) {
                throw new RefundException(
                    sprintf(
                        'Respuesta inválida de Khipu (%s): falta el campo obligatorio "%s".',
                        $endpoint,
                        $campo
                    ),
                    200,
                    (string) json_encode($respuesta)
                );
            }
        }
    }

    /**
     * El formato de error de Khipu es {status, message, errors:[{field, message}]}.
     * Los errores por campo son más específicos que el `message` general, así que
     * cuando existen se concatenan y se prefieren.
     */
    private function messageFromBody(?object $body, int $status): string
    {
        if ($body !== null && !empty($body->errors) && is_array($body->errors)) {
            $partes = [];
            foreach ($body->errors as $error) {
                $partes[] = $error->message ?? json_encode($error);
            }
            return implode(' ', $partes);
        }

        if ($body !== null && isset($body->message)) {
            return (string) $body->message;
        }

        return 'Error HTTP ' . $status;
    }
}
