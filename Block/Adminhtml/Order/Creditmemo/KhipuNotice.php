<?php
declare(strict_types=1);

namespace Khipu\Payment\Block\Adminhtml\Order\Creditmemo;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order\Creditmemo;

/**
 * Aviso en la pantalla de credit memo de un pedido pagado con Khipu.
 *
 * Existe porque "Refund Offline" y "Refund" están uno al lado del otro, con el
 * mismo aspecto, y hacen cosas radicalmente distintas: el primero solo registra
 * el reembolso en Magento, el segundo devuelve la plata al pagador. Sin aviso,
 * un pedido queda marcado como reembolsado con el cliente esperando su dinero.
 *
 * No es hipotético: pasó durante las pruebas de esta misma feature.
 *
 * Deliberadamente NO bloquea el reembolso offline. Hay casos legítimos — el
 * comercio devolvió por otro medio, o el pago superó los 180 días que Khipu
 * admite. El objetivo es que la elección sea consciente, no impedirla.
 */
class KhipuNotice extends Template
{
    private const METODO_KHIPU = 'simplified';

    private Registry $registry;

    public function __construct(Context $context, Registry $registry, array $data = [])
    {
        parent::__construct($context, $data);
        $this->registry = $registry;
    }

    public function getCreditmemo(): ?Creditmemo
    {
        $cm = $this->registry->registry('current_creditmemo');

        return $cm instanceof Creditmemo ? $cm : null;
    }

    /** El aviso solo aplica a pedidos cobrados por Khipu. */
    public function isKhipuOrder(): bool
    {
        $cm = $this->getCreditmemo();
        if ($cm === null || $cm->getOrder() === null) {
            return false;
        }
        $payment = $cm->getOrder()->getPayment();

        return $payment !== null && $payment->getMethod() === self::METODO_KHIPU;
    }

    /**
     * ¿Está disponible el botón "Refund" (online) en esta pantalla?
     *
     * Misma condición que el core en Creditmemo/Create/Items.php: exige una
     * factura CON transaction_id. Un credit memo iniciado desde el pedido no
     * tiene factura asociada, así que ahí el único botón es el offline — y el
     * admin necesita saber que existe otro camino.
     */
    public function hasOnlineRefund(): bool
    {
        $cm = $this->getCreditmemo();
        if ($cm === null || !$cm->canRefund()) {
            return false;
        }
        $invoice = $cm->getInvoice();

        return $invoice !== null && (bool) $invoice->getTransactionId();
    }

    /** URL de la factura, para mandar al admin donde sí está el botón online. */
    public function getInvoicesUrl(): string
    {
        $cm = $this->getCreditmemo();
        $orderId = ($cm && $cm->getOrder()) ? $cm->getOrder()->getId() : null;

        return $this->getUrl('sales/order/view', ['order_id' => $orderId, '_fragment' => 'order_invoices']);
    }
}
