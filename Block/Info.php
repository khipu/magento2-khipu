<?php
declare(strict_types=1);

namespace Khipu\Payment\Block;

/**
 * Información del pago en el admin, los correos y el detalle del pedido:
 * agrega el medio de Khipu que eligió el comprador.
 */
class Info extends \Magento\Payment\Block\Info
{
    protected function _prepareSpecificInformation($transport = null)
    {
        if ($this->_paymentSpecificInformation !== null) {
            return $this->_paymentSpecificInformation;
        }

        $transport = parent::_prepareSpecificInformation($transport);

        $titulo = $this->getInfo()->getAdditionalInformation('khipu_payment_method_title');
        if (is_string($titulo) && $titulo !== '') {
            $transport->setData((string) __('Medio de pago'), $titulo);
        }

        return $transport;
    }
}
