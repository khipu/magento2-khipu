<?php
declare(strict_types=1);

namespace Khipu\Payment\Block\Adminhtml\System\Config;

use Khipu\Payment\Model\Config\MethodsTable;
use Khipu\Payment\Model\Config\MethodsTableMerger;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Tabla editable de medios de pago (se llena con el botón "Conectar").
 */
class PaymentMethodsTable extends Field
{
    protected $_template = 'Khipu_Payment::system/config/payment-methods-table.phtml';

    public function __construct(
        Context $context,
        private readonly MethodsTable $methodsTable,
        private readonly MethodsTableMerger $merger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('field_name', $element->getName());
        return $this->_toHtml();
    }

    public function getFieldName(): string
    {
        return (string)$this->getData('field_name');
    }

    public function getTabla(): ?array
    {
        return $this->methodsTable->get();
    }

    public function getMerchantIdConfigurado(): string
    {
        return trim((string)$this->_scopeConfig->getValue('payment/simplified/merchant_id'));
    }

    public function merchantCambio(?array $tabla): bool
    {
        return $tabla !== null
            && trim((string)($tabla['merchant_id'] ?? '')) !== $this->getMerchantIdConfigurado();
    }

    /** Nombre legible del medio (Khipu manda el id como name). */
    public function getNombreMedio(array $fila): string
    {
        return $this->merger->defaultTitle((string)($fila['id'] ?? ''), (string)($fila['name'] ?? ''));
    }

    /** Fecha de la última consulta en la hora local del admin. */
    public function getFetchedAtLocal(?array $tabla): string
    {
        $raw = (string)($tabla['fetched_at'] ?? '');
        if ($raw === '') {
            return '';
        }
        try {
            return $this->_localeDate->formatDateTime(
                new \DateTime($raw),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Exception $e) {
            return $raw;
        }
    }
}
