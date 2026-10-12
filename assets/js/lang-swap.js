/**
 * Header language swap pill — a cosmetic enhancement only.
 *
 * `[data-saw-lang-swap]` is an ordinary `<a href>` to the other language's
 * URL (see templates/standalone/language-toggle.php); this never calls
 * preventDefault() and never blocks the click. All it does is flip the
 * visible flag and the two-letter code to the OTHER language an instant
 * before the browser navigates there, so the swap feels immediate rather
 * than only resolving once the next page has loaded — the same effect the
 * client's own prototype's `data-saw-swap` button has. With scripting off
 * the link still works exactly the same, just without that instant.
 *
 * @package Nera_Strikeawin
 */
( function () {
	'use strict';

	function swap( el ) {
		var flags = el.querySelectorAll( '[data-saw-lang-swap-flag]' );
		for ( var i = 0; i < flags.length; i++ ) {
			flags[ i ].hidden = ! flags[ i ].hidden;
		}

		var code = el.querySelector( '[data-saw-lang-swap-code]' );
		if ( code ) {
			code.textContent = el.getAttribute( 'data-other-code' ) || code.textContent;
		}

		var otherLabel = el.getAttribute( 'data-other-aria-label' );
		if ( otherLabel ) {
			el.setAttribute( 'aria-label', otherLabel );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest( '[data-saw-lang-swap]' );
		if ( el ) {
			swap( el );
		}
	} );
} )();
