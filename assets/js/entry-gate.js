/**
 * Entry pop-up — visibility and the "answered" cookie, decided in the browser.
 *
 * The server always renders the pop-up (hidden when it saw the cookie), because a
 * full-page cache can hand the same markup to visitors whose cookie it never saw.
 * So the cookie itself decides here: absent -> reveal, present -> keep hidden.
 * Without scripting the server's own `?saw_entry=` handling still works, and a
 * <noscript> rule shows the pop-up even on a cached page that carries it hidden.
 *
 * Language: on a first visit the browser's language is pre-selected (its button is
 * highlighted, and "Enter" goes to that language). Nothing navigates by itself.
 * Closing the pop-up without choosing means English, the client's own rule.
 *
 * @package Nera_Strikeawin
 */
( function () {
	'use strict';

	var gate = document.querySelector( '[data-saw-gate]' );
	if ( ! gate ) {
		return;
	}

	var name = gate.getAttribute( 'data-cookie' ) || 'nera_saw_entry';

	function hasCookie() {
		return ( '; ' + document.cookie ).indexOf( '; ' + name + '=' ) !== -1;
	}

	function setCookie() {
		var life = parseInt( gate.getAttribute( 'data-life' ), 10 ) || 31536000;
		var parts = [
			name + '=1',
			'path=' + ( gate.getAttribute( 'data-path' ) || '/' ),
			'max-age=' + life,
			'SameSite=Lax'
		];
		var domain = gate.getAttribute( 'data-domain' );
		if ( domain ) {
			parts.push( 'domain=' + domain );
		}
		if ( gate.getAttribute( 'data-secure' ) ) {
			parts.push( 'Secure' );
		}
		document.cookie = parts.join( '; ' );
	}

	function close() {
		gate.hidden = true;
	}

	if ( hasCookie() ) {
		close();
		return;
	}

	gate.hidden = false;

	var current = gate.getAttribute( 'data-current-lang' ) || '';
	var links = gate.querySelectorAll( '[data-lang]' );
	var suggested = null;

	// Only the address counts: the server sets pll_language on the very first
	// response, so a language cookie says nothing about whether anyone chose.
	function hasExplicitLanguage() {
		return /[?&]saw_lang=/.test( window.location.search );
	}

	// First visit only: no language chosen yet in the address.
	if ( links.length > 1 && ! hasExplicitLanguage() ) {
		var prefs = navigator.languages && navigator.languages.length ? navigator.languages : [ navigator.language || '' ];
		for ( var i = 0; i < prefs.length && ! suggested; i++ ) {
			var base = String( prefs[ i ] ).toLowerCase().split( '-' )[ 0 ];
			for ( var j = 0; j < links.length; j++ ) {
				if ( links[ j ].getAttribute( 'data-lang' ) === base ) {
					suggested = links[ j ];
					break;
				}
			}
		}
		if ( suggested ) {
			for ( var k = 0; k < links.length; k++ ) {
				links[ k ].classList.toggle( 'is-current', links[ k ] === suggested );
			}
			suggested.classList.add( 'is-suggested' );
		}
	}

	var form = gate.querySelector( 'form' );
	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			setCookie();
			event.preventDefault();
			if ( suggested && suggested.getAttribute( 'data-lang' ) !== current ) {
				// Leave on the pre-selected language's own address; the cookie is set.
				window.location.href = suggested.getAttribute( 'href' );
				return;
			}
			close();
		} );
	}

	var closer = gate.querySelector( '[data-saw-gate-close]' );
	if ( closer ) {
		closer.addEventListener( 'click', function ( event ) {
			setCookie();
			// Closing means English. Already English: just hide. Otherwise let the
			// link run — its address (saw_lang=en) switches the page.
			if ( current === 'en' || current === '' ) {
				event.preventDefault();
				close();
			}
		} );
	}
} )();
