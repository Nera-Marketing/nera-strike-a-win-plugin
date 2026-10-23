/**
 * Competition page buy form: the runs stepper and the Enter button's label.
 *
 * "Enter for £50 (2 runs)": the total follows the tier and the number of runs, so
 * the label is rebuilt here. The server prints the same sentence for the default
 * selection, and the number is a real input the form posts, so the page is correct
 * before this runs and still works without it.
 *
 * - minus / plus change the number, clamped to 1..max;
 * - the maximum is the tier's own (`data-max-runs`): how many runs of that tier the
 *   pool can still hold, which changes when another tier is picked;
 * - a whole total drops its ".00", formatted from WooCommerce's own currency
 *   settings (`data-money`), as the design has it.
 *
 * @package Nera_Strikeawin
 */
( function () {
	'use strict';

	var form = document.querySelector( '[data-saw-buy]' );
	if ( ! form ) {
		return;
	}

	var input = form.querySelector( '.saw-qty__input' );
	var enter = form.querySelector( '[data-saw-enter]' );
	if ( ! input || ! enter ) {
		return;
	}

	var money = {};
	try {
		money = JSON.parse( form.getAttribute( 'data-money' ) || '{}' );
	} catch ( e ) {
		money = {};
	}
	var enterFormat = form.getAttribute( 'data-enter-format' ) || 'Enter for %1$s (%2$s)';
	var runOne = form.getAttribute( 'data-run-one' ) || '%d run';
	var runMany = form.getAttribute( 'data-run-many' ) || '%d runs';
	var minus = form.querySelector( '[data-saw-step="-1"]' );
	var plus = form.querySelector( '[data-saw-step="1"]' );

	// The buttons are printed hidden: without this script they would do nothing.
	if ( minus ) {
		minus.hidden = false;
	}
	if ( plus ) {
		plus.hidden = false;
	}

	function selectedTier() {
		return form.querySelector( 'input[type="radio"][data-price]:checked' );
	}

	function maxRuns() {
		var tier = selectedTier();
		var max = tier ? parseInt( tier.getAttribute( 'data-max-runs' ), 10 ) : parseInt( input.getAttribute( 'max' ), 10 );
		return max > 0 ? max : 99;
	}

	function quantity() {
		var n = parseInt( input.value, 10 );
		if ( isNaN( n ) || n < 1 ) {
			n = 1;
		}
		return Math.min( n, maxRuns() );
	}

	function group( digits, sep ) {
		return digits.replace( /\B(?=(\d{3})+(?!\d))/g, sep );
	}

	// Same rule as the server: no decimals for a whole amount.
	function formatMoney( amount ) {
		var decimals = Math.floor( amount ) === amount ? 0 : ( money.decimals === undefined ? 2 : money.decimals );
		var fixed = amount.toFixed( decimals );
		var parts = fixed.split( '.' );
		var number = group( parts[ 0 ], money.tsep === undefined ? ',' : money.tsep );
		if ( parts.length > 1 ) {
			number += ( money.dsep === undefined ? '.' : money.dsep ) + parts[ 1 ];
		}
		return ( money.format || '%1$s%2$s' ).replace( '%1$s', money.symbol || '' ).replace( '%2$s', number );
	}

	function runs( n ) {
		return ( 1 === n ? runOne : runMany ).replace( '%d', String( n ) );
	}

	function render() {
		var tier = selectedTier();
		var n = quantity();

		input.setAttribute( 'max', String( maxRuns() ) );
		if ( String( n ) !== input.value ) {
			input.value = String( n );
		}
		if ( minus ) {
			minus.disabled = n <= 1;
		}
		if ( plus ) {
			plus.disabled = n >= maxRuns();
		}

		if ( ! tier || enter.disabled ) {
			return;
		}
		var price = parseFloat( tier.getAttribute( 'data-price' ) );
		if ( isNaN( price ) ) {
			return;
		}
		enter.textContent = enterFormat.replace( '%1$s', formatMoney( price * n ) ).replace( '%2$s', runs( n ) );
	}

	form.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-saw-step]' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		input.value = String( Math.max( 1, Math.min( maxRuns(), quantity() + parseInt( button.getAttribute( 'data-saw-step' ), 10 ) ) ) );
		render();
	} );
	input.addEventListener( 'input', render );
	input.addEventListener( 'change', render );
	form.addEventListener( 'change', function ( event ) {
		if ( event.target && 'radio' === event.target.type ) {
			render();
		}
	} );

	// Exposed so the countdown can close the form when the draw closes.
	form.sawCloseEntries = function () {
		enter.disabled = true;
		enter.textContent = form.getAttribute( 'data-closed-label' ) || enter.textContent;
		input.disabled = true;
		if ( minus ) {
			minus.disabled = true;
		}
		if ( plus ) {
			plus.disabled = true;
		}
	};

	render();
}() );
