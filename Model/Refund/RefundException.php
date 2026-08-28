<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Refund;

/**
 * Error al hablar con los endpoints de reversa de Khipu.
 *
 * El mensaje ya viene normalizado desde errors[].message. El body crudo se
 * conserva para el log de diagnóstico: en una reversa fallida no se puede
 * dejar nota en el pedido, porque el rollback del credit memo se la lleva.
 */
class RefundException extends \RuntimeException
{
    private int $httpCode;
    private ?string $rawBody;

    public function __construct(string $message, int $httpCode = 0, ?string $rawBody = null)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
        $this->rawBody = $rawBody;
    }

    /** 0 = no hubo respuesta HTTP (error de red o timeout). */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }
}
