define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'khipu_merchant',
                component: 'Khipu_Merchant/js/view/payment/method-renderer/khipu-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
