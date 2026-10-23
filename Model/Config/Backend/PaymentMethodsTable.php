<?php
declare(strict_types=1);

namespace Khipu\Payment\Model\Config\Backend;

use Khipu\Payment\Model\Config\MethodsTable;
use Khipu\Payment\Model\Config\MethodsTableMerger;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Guarda las ediciones de la tabla de medios de pago. La estructura de la tabla
 * se lee siempre de la configuración guardada; del formulario solo se toman
 * active, title y mode de cada fila.
 */
class PaymentMethodsTable extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly MethodsTable $methodsTable,
        private readonly MethodsTableMerger $merger,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $tabla = $this->methodsTable->get();
        if ($tabla === null) {
            // Sin tabla guardada no se inventan filas.
            $this->setValue('');
            return parent::beforeSave();
        }

        $valor = $this->getValue();
        $filas = is_array($valor) && is_array($valor['rows'] ?? null) ? $valor['rows'] : [];

        $this->setValue(json_encode($this->merger->applyEdits($tabla, $filas)));

        return parent::beforeSave();
    }
}
