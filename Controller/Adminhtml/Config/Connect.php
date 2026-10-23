<?php
declare(strict_types=1);

namespace Khipu\Payment\Controller\Adminhtml\Config;

use Khipu\Payment\Model\Config\Connector;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Botón "Conectar" de la configuración de Khipu: guarda credenciales y trae los
 * medios de pago de la cuenta.
 */
class Connect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Payment::payment';

    public function __construct(
        Context $context,
        private readonly Connector $connector,
        private readonly JsonFactory $resultJsonFactory,
        private readonly LoggerInterface $khipuLogger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultado = $this->resultJsonFactory->create();
        try {
            $this->connector->connect(
                (string)$this->getRequest()->getParam('api_key'),
                (string)$this->getRequest()->getParam('merchant_id'),
                (string)$this->getRequest()->getParam('merchant_secret')
            );
            return $resultado->setData(['success' => true]);
        } catch (LocalizedException $e) {
            return $resultado->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Solo clase y mensaje: nunca las credenciales del POST.
            $this->khipuLogger->error(
                'Conectar: error inesperado',
                ['exception' => get_class($e), 'error' => $e->getMessage()]
            );
            return $resultado->setData([
                'success' => false,
                'message' => (string)__('Ocurrió un error inesperado al conectar. Revisa var/log/khipu.log.'),
            ]);
        }
    }
}
