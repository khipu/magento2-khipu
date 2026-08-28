<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Refund;

use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;

/**
 * Registra un pago de Khipu como transacción de captura de Magento.
 *
 * Sin esto pasan dos cosas malas:
 *   1. El botón "Refund" (online) del credit memo no aparece, porque exige
 *      $invoice->getTransactionId() — ver Creditmemo/Create/Items.php:62-63.
 *   2. Magento reemplaza nuestros mensajes de error por "If the invoice was
 *      created offline, try creating an offline credit memo" cuando no
 *      encuentra la transacción de captura — ver Order/Payment.php:700-708.
 *
 * El llamador es responsable de persistir el payment y el invoice.
 */
class CaptureRegistrar
{
    public function register(
        Payment $payment,
        Invoice $invoice,
        string $khipuPaymentId,
        array $rawNotification = []
    ): void {
        $payment->setTransactionId($khipuPaymentId);
        $payment->setLastTransId($khipuPaymentId);
        $payment->setAdditionalInformation('khipu_payment_id', $khipuPaymentId);
        // Abierta: si se cierra, no se puede reembolsar contra ella.
        $payment->setIsTransactionClosed(false);

        if ($rawNotification !== []) {
            $payment->setTransactionAdditionalInfo('khipu_raw_notification', $rawNotification);
        }

        $invoice->setTransactionId($khipuPaymentId);

        $payment->addTransaction(TransactionInterface::TYPE_CAPTURE, $invoice, true);
    }
}
