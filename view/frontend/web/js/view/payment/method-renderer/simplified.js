define(
	[
		'jquery',
		'Magento_Checkout/js/view/payment/default',
		'mage/url',
		'Magento_Checkout/js/action/place-order'
	],
	function ($,Component,url,placeOrderAction) {
		'use strict';

		return Component.extend({
									defaults: {
										template: 'Khipu_Payment/payment/simplified',
										redirectAfterPlaceOrder: false
									},
									getCode: function() {
										return 'simplified';
									},

									isActive: function() {
										return true;
									},
									/*placeOrder: function (data, event) {
										if (event) {
											event.preventDefault();
										}
										var self = this,
											placeOrder;
										if (this.validate()) {
											this.isPlaceOrderActionAllowed(false);
											placeOrder = placeOrderAction(this.getData(), false, this.messageContainer);

											$.when(placeOrder).fail(function () {
												self.isPlaceOrderActionAllowed(true);
											}).done(this.afterPlaceOrder.bind(this));
											return true;
										}
										return false;
									},*/

									afterPlaceOrder: function (quoteId) {
										var request = $.ajax({
																 url: url.build('khipupayment/payment/placeOrder'),
																 type: 'POST',
																 dataType: 'json',
																 data: {quote_id: quoteId}
															 });

										request.done(function (response) {
											if (response.status) {
												window.location.replace(response.payment_url);
											} else {
												if(confirm(response.reason)) {
													window.location.replace(url.build('/checkout/onepage/failure'));
												}
											}
										});
									}
								});
	}
);