define([
		   'uiComponent',
		   'Magento_Checkout/js/model/payment/renderer-list'
	   ], function (Component, rendererList) {
	'use strict';

	rendererList.push(
		{
			type: 'simplified',
			component: 'Khipu_Payment/js/view/payment/method-renderer/simplified'
		}
	);

	/** Add view logic here if needed */
	return Component.extend({});
});