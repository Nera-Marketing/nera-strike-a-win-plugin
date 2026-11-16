/*
 * The pay sheet: the consent tick's message, and the sheet opened over the
 * competition page after Continue.
 *
 * Listeners are delegated: the sheet's body is replaced by every add, so anything
 * bound to its nodes would be lost with them.
 */
( function () {
	'use strict';

	var SHEET = '[data-saw-sheet]';
	var opener = null;

	/* ---- the consent tick ----------------------------------------------- */

	function boxes( form ) {
		return form.querySelectorAll( 'input[type="checkbox"]' );
	}

	function ticked( form ) {
		return Array.prototype.every.call( boxes( form ), function ( box ) {
			return box.checked;
		} );
	}

	function show( form, on ) {
		var msg = form.querySelector( '.saw-consent__msg' );
		if ( msg ) {
			msg.hidden = ! on;
		}
		Array.prototype.forEach.call( boxes( form ), function ( box ) {
			if ( on && ! box.checked ) {
				box.setAttribute( 'aria-invalid', 'true' );
			} else {
				box.removeAttribute( 'aria-invalid' );
			}
		} );
	}

	// Consent is given afresh each visit: a page restored by Back or Forward would
	// otherwise bring last time's ticks with it.
	function clear( reset ) {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-saw-consent]' ), function ( form ) {
			Array.prototype.forEach.call( boxes( form ), function ( box ) {
				box.checked = false;
			} );
			if ( reset ) {
				show( form, false );
			}
		} );
	}

	// The first load keeps a message the server sent (an unticked submit without script).
	clear( false );
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			clear( true );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var form = event.target.closest ? event.target.closest( '[data-saw-consent]' ) : null;
		if ( ! form ) {
			return;
		}
		var all = ticked( form );
		var msg = form.querySelector( '.saw-consent__msg' );
		if ( all || ( msg && ! msg.hidden ) ) {
			show( form, ! all );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.matches || ! form.matches( '[data-saw-consent]' ) ) {
			return;
		}
		if ( ! ticked( form ) ) {
			// Without this script the server sends the player back with the same message.
			event.preventDefault();
			show( form, true );
		}
	} );

	/* ---- Pay: the order placed from the sheet ------------------------------ */

	function pay( form, post ) {
		var button = form.querySelector( 'button[type="submit"]' );
		var errors = form.querySelector( '[data-saw-pay-errors]' );
		var panel = form.closest( '.saw-paysheet' ) || form;

		// A disabled fieldset is left out of the form data; it stays disabled so the
		// GET fallback to the checkout page carries none of it.
		post.disabled = false;
		var body = new FormData( form );
		post.disabled = true;

		if ( button ) {
			button.disabled = true;
		}
		panel.setAttribute( 'aria-busy', 'true' );
		if ( errors ) {
			errors.hidden = true;
			errors.innerHTML = '';
		}

		function fail( html ) {
			panel.removeAttribute( 'aria-busy' );
			if ( button ) {
				button.disabled = false;
			}
			if ( errors ) {
				errors.innerHTML = html || '';
				if ( ! errors.textContent.trim() ) {
					errors.textContent = errors.getAttribute( 'data-saw-fallback' ) || '';
				}
				errors.hidden = false;
			}
		}

		fetch( post.getAttribute( 'data-saw-pay-post' ), { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				if ( json && 'success' === json.result && json.redirect ) {
					window.location.href = json.redirect;
					return;
				}
				if ( json && json.reload ) {
					window.location.reload();
					return;
				}
				fail( json && json.messages );
			} )
			.catch( function () {
				fail( '' );
			} );
	}

	// Only a sheet that carries what the checkout needs (a signed-in player's nonce and
	// billing values) pays from here; otherwise the form goes on to the checkout page.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( event.defaultPrevented || ! form.matches || ! form.matches( '[data-saw-consent]' ) || ! window.fetch ) {
			return;
		}
		var post = form.querySelector( '[data-saw-pay-post]' );
		if ( ! post || ! post.getAttribute( 'data-saw-pay-post' ) || ! post.querySelector( '[name="woocommerce-process-checkout-nonce"]' ) || ! post.querySelector( '[name^="billing_"]' ) ) {
			return;
		}
		event.preventDefault();
		var button = form.querySelector( 'button[type="submit"]' );
		if ( ! button || ! button.disabled ) {
			pay( form, post );
		}
	} );

	/* ---- the sheet ------------------------------------------------------- */

	function sheet() {
		return document.querySelector( SHEET );
	}

	function open() {
		var el = sheet();
		if ( ! el ) {
			return;
		}
		opener = document.activeElement;
		el.hidden = false;
		document.documentElement.classList.add( 'saw-sheet-open' );
		var close = el.querySelector( '[data-saw-sheet-close]' );
		if ( close ) {
			close.focus();
		}
	}

	function close() {
		var el = sheet();
		if ( ! el || el.hidden ) {
			return;
		}
		el.hidden = true;
		document.documentElement.classList.remove( 'saw-sheet-open' );
		if ( opener && opener.focus ) {
			opener.focus();
		}
	}

	document.addEventListener( 'saw:paysheet', open );

	// × and the scrim around the sheet; the basket keeps the entry.
	document.addEventListener( 'click', function ( event ) {
		var el = sheet();
		if ( ! el || el.hidden ) {
			return;
		}
		if ( event.target === el || ( event.target.closest && event.target.closest( '[data-saw-sheet-close]' ) ) ) {
			event.preventDefault();
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			close();
		}
	} );
}() );
