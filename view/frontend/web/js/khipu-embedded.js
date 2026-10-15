define([
    'mage/url',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function (url, fullScreenLoader, alert, $t) {
    'use strict';

    // Orden de los resultados de KWS. El mejor visto nunca baja: al cerrar con la X,
    // KWS informa USER_CANCELED (un ERROR) aunque antes haya informado WARNING, y
    // ese pago todavía puede confirmarse.
    var RANGOS = {OK: 3, WARNING: 2, CONTINUE: 2, ERROR: 1};

    // Una sola salida por compra: una segunda navegación aborta la primera, y si esa
    // primera era la que restauraba el carro, el cliente cae en la página de error
    // por defecto de Magento con la orden ya cancelada.
    var saliendo = false;
    var avisoMostrado = false;

    function salir(destino) {
        if (saliendo) {
            return;
        }
        saliendo = true;
        window.location.replace(destino);
    }

    function ir(ruta) {
        salir(url.build(ruta));
    }

    function avisarErrorDeCarga() {
        if (avisoMostrado) {
            return;
        }
        avisoMostrado = true;
        fullScreenLoader.stopLoader();
        alert({
            content: $t('No pudimos cargar el pago de Khipu. Intenta nuevamente o elige otro medio de pago.'),
            actions: {
                always: function () {
                    ir('checkout/onepage/failure');
                }
            }
        });
    }

    return {
        open: function (paymentId) {
            var mejorRango = 0;

            // Sin id de cobro no hay nada que abrir: no dejar al cliente en un checkout muerto.
            if (!paymentId) {
                avisarErrorDeCarga();
                return;
            }

            // Sin límite de tiempo: Magento define waitSeconds: 0. Solo un error
            // real de carga termina en avisarErrorDeCarga.
            fullScreenLoader.startLoader();

            require(['khipu-kws'], function (Khipu) {
                if (!Khipu) {
                    avisarErrorDeCarga();
                    return;
                }
                fullScreenLoader.stopLoader();

                // Si KWS falla al iniciar, el cliente no debe quedar en un checkout muerto.
                try {
                    new Khipu().startOperation(paymentId, function (result) {
                        var rango = RANGOS[result && result.result] || 0;
                        if (rango > mejorRango) {
                            mejorRango = rango;
                        }
                    }, {
                        modal: true,
                        modalOptions: {maxWidth: 450, maxHeight: 860},
                        options: {
                            style: {primaryColor: '#8347AD'},
                            skipExitPage: false
                        },
                        // Se llama al cerrarse el modal, después de que el cliente vio la
                        // pantalla de resultado de khenshin. exitUrl viene cuando khenshin
                        // lo manda a otra página (por ejemplo, transferencia manual).
                        closeCallback: function (exitUrl) {
                            if (mejorRango >= RANGOS.WARNING) {
                                ir('checkout/onepage/success');
                            } else if (exitUrl) {
                                salir(exitUrl);
                            } else {
                                ir('checkout/onepage/failure');
                            }
                        }
                    });
                } catch (e) {
                    avisarErrorDeCarga();
                }
            }, avisarErrorDeCarga);
        }
    };
});
