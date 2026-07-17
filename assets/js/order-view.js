(function () {
	'use strict';

	var lines = window.NeraSAWOrderViewLines || [];
	var itemIds = window.NeraSAWOrderItemIds || [];
	if ( ! lines.length ) {
		return;
	}

	var rows = document.querySelectorAll( '.nera-view-order .space-y-4 > div.flex.items-start' );
	if ( ! rows.length ) {
		return;
	}

	var lineById = {};
	for ( var i = 0; i < lines.length; i += 1 ) {
		lineById[ lines[ i ].id ] = lines[ i ].html;
	}

	rows.forEach( function ( row, index ) {
		if ( row.querySelector( '.saw-order-line' ) ) {
			return;
		}

		var itemId = itemIds[ index ];
		var html = itemId ? lineById[ itemId ] : '';
		if ( ! html ) {
			return;
		}

		var target = row.querySelector( '.flex-1' );
		if ( target ) {
			target.insertAdjacentHTML( 'beforeend', html );
		}
	} );
}());
