define(
	[
		'jquery',
		'Magento_Checkout/js/view/payment/default',
		'mage/url',
		'Magento_Checkout/js/action/place-order',
		'Khipu_Payment/js/khipu-embedded'
	],
	function ($,Component,url,placeOrderAction,khipuEmbedded) {
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

									afterPlaceOrder: function (quoteId) {
										var request = $.ajax({
																 url: url.build('khipupayment/payment/placeOrder'),
																 type: 'POST',
																 dataType: 'json',
																 data: {quote_id: quoteId}
															 });

										request.done(function (response) {
											if (response.status) {
												if (response.mode === 'embedded') {
													khipuEmbedded.open(response.payment_id);
												} else {
													window.location.replace(response.payment_url);
												}
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