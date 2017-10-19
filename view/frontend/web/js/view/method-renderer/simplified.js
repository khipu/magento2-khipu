define(
	[
		'Magento_Checkout/js/view/payment/default'
	],
	function (Component) {
		'use strict';

		return Component.extend({
									defaults: {
										template: 'Khipu_Payment/payment/simplified'
									},
									getCode: function() {
										return 'khipu_payment-simplified';
									},

									isActive: function() {
										return true;
									},
								});
	}
);