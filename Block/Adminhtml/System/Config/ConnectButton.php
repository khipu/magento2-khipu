<?php
declare(strict_types=1);

namespace Khipu\Payment\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Botón "Conectar": toma las credenciales del formulario y le pide al servidor
 * que consulte los medios de pago de la cuenta en Khipu.
 */
class ConnectButton extends Field
{
    protected $_template = 'Khipu_Payment::system/config/connect-button.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('html_id', $element->getHtmlId());
        return $this->_toHtml();
    }

    public function getConnectUrl(): string
    {
        return $this->getUrl('khipupayment/config/connect');
    }

    /** Prefijo común de los ids de los inputs hermanos (el id del campo sin el sufijo "connect"). */
    public function getFieldPrefix(): string
    {
        return (string)preg_replace('/connect$/', '', (string)$this->getData('html_id'));
    }
}
