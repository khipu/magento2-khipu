define(
	[
		'jquery',
		'ko',
		'Magento_Checkout/js/view/payment/default',
		'mage/url',
		'Magento_Checkout/js/action/place-order',
		'Khipu_Payment/js/khipu-embedded'
	],
	function ($,ko,Component,url,placeOrderAction,khipuEmbedded) {
		'use strict';

		var khipuConfig = (window.checkoutConfig && window.checkoutConfig.payment && window.checkoutConfig.payment.khipu) || {};

		return Component.extend({
									defaults: {
										template: 'Khipu_Payment/payment/simplified',
										redirectAfterPlaceOrder: false
									},

									// Medios de Khipu activos: [{id, title}], en el orden de la tabla.
									khipuMethods: khipuConfig.methods || [],

									initialize: function () {
										// Parte con el primer medio elegido: si Magento selecciona
										// `simplified` por su cuenta (único método o selección
										// restaurada), el pedido nunca sale sin khipu_payment_method.
										this.selectedKhipuMethod = ko.observable(
											this.khipuMethods.length ? this.khipuMethods[0].id : null
										);
										this._super();
										return this;
									},

									getCode: function() {
										return 'simplified';
									},

									isActive: function() {
										return true;
									},

									selectKhipuMethod: function (method) {
										this.selectedKhipuMethod(method.id);
										this.selectPaymentMethod();
										return true;
									},

									isKhipuMethodChecked: function (id) {
										return this.isChecked() === this.getCode() && this.selectedKhipuMethod() === id;
									},

									// Con más de un medio, los radios se muestran aunque `simplified`
									// sea el único método de Magento.
									isKhipuRadioVisible: function () {
										return this.isRadioButtonVisible() || this.khipuMethods.length > 1;
									},

									getData: function () {
										return {
											method: this.getCode(),
											additional_data: {
												khipu_payment_method: this.selectedKhipuMethod()
											}
										};
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
