( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if ( ! registry || ! wcSettings ) {
		return;
	}

	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var settings = wcSettings.getSetting( 'mpwcr_data', {} );
	var label = decode( settings.title || 'Mercado Pago' );

	var Content = function () {
		return el( 'div', null, decode( settings.description || '' ) );
	};

	registry.registerPaymentMethod( {
		name: 'mpwcr',
		label: el( 'span', null, label ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
