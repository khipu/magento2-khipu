define([
		   'uiComponent',
		   'Magento_Checkout/js/model/payment/renderer-list'
	   ], function (Component, rendererList) {
	'use strict';

	rendererList.push(
		{
			type: 'simplified',
			component: 'Khipu_Payment/js/view/payment/method-renderer/simplified'
		}/*,
		{
			type: 'banktransfer',
			component: 'Magento_OfflinePayments/js/view/payment/method-renderer/banktransfer-method'
		},
		{
			type: 'cashondelivery',
			component: 'Magento_OfflinePayments/js/view/payment/method-renderer/cashondelivery-method'
		},
		{
			type: 'purchaseorder',
			component: 'Magento_OfflinePayments/js/view/payment/method-renderer/purchaseorder-method'
		}*/
	);

	/** Add view logic here if needed */
	return Component.extend({});
});