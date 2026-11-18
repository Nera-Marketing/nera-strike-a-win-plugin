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

		clearFieldErrors( form );

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
				var placed = fieldErrors( form, errors );
				if ( ! errors.textContent.trim() && ! placed ) {
					errors.textContent = errors.getAttribute( 'data-saw-fallback' ) || '';
				}
				errors.hidden = ! errors.textContent.trim();
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
				// Taken, but with nowhere to go from here: the checkout page finishes it.
				if ( json && 'success' === json.result ) {
					toCheckout( form );
					form.submit();
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

	// WooCommerce tags a field's error with the field's name (data-id): each such error moves
	// under its field; the rest stay in the errors box. Returns how many moved.
	function fieldErrors( form, box ) {
		var inputs = form.querySelectorAll( '[data-saw-pay-fields] [name]' );
		var placed = 0;
		var first = null;
		Array.prototype.forEach.call( box.querySelectorAll( '[data-id]' ), function ( item ) {
			var input = null;
			for ( var i = 0; i < inputs.length; i++ ) {
				if ( inputs[ i ].name === item.getAttribute( 'data-id' ) ) {
					input = inputs[ i ];
				}
			}
			if ( ! input ) {
				return;
			}
			var msg = document.createElement( 'p' );
			msg.className = 'saw-field__err';
			msg.id = input.id + '-err';
			msg.textContent = item.textContent.trim();
			input.insertAdjacentElement( 'afterend', msg );
			input.setAttribute( 'aria-invalid', 'true' );
			input.setAttribute( 'aria-describedby', msg.id );
			item.parentNode.removeChild( item );
			first = first || input;
			placed++;
		} );
		if ( first ) {
			first.focus();
		}
		return placed;
	}

	function clearFieldErrors( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '.saw-field__err' ), function ( msg ) {
			msg.parentNode.removeChild( msg );
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '[data-saw-pay-fields] [aria-invalid]' ), function ( input ) {
			input.removeAttribute( 'aria-invalid' );
			input.removeAttribute( 'aria-describedby' );
		} );
	}

	// The chosen method's mode, set by the server: 'post' (from here) or 'checkout' (on the checkout page).
	function mode( form ) {
		var method = form.querySelector( 'input[name="payment_method"]:checked' ) || form.querySelector( 'input[type="hidden"][name="payment_method"]' );
		return method ? method.getAttribute( 'data-saw-pay-mode' ) : '';
	}

	// The checkout page asks for the billing fields itself, and an email address does not belong in the URL the GET builds.
	function toCheckout( form ) {
		var fields = form.querySelector( '[data-saw-pay-fields]' );
		if ( fields ) {
			fields.disabled = true;
		}
	}

	// Only a sheet that carries what the checkout needs (a signed-in player's nonce and
	// billing fields) pays from here, with a method that can be posted; otherwise the form
	// goes on to the checkout page with the chosen method.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( event.defaultPrevented || ! form.matches || ! form.matches( '[data-saw-consent]' ) ) {
			return;
		}
		var post = form.querySelector( '[data-saw-pay-post]' );
		if ( ! window.fetch || ! post || ! post.getAttribute( 'data-saw-pay-post' ) || ! post.querySelector( '[name="woocommerce-process-checkout-nonce"]' ) || ! form.querySelector( '[name^="billing_"]' ) || 'checkout' === mode( form ) ) {
			toCheckout( form );
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
