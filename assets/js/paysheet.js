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
	var STEP = 'data-saw-pay-step-current';
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
			Array.prototype.forEach.call( document.querySelectorAll( '[data-saw-consent]' ), function ( form ) {
				if ( form.querySelector( '[data-saw-pay-fields]' ) ) {
					reset( form );
				}
			} );
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

	/* ---- the two steps: billing fields, then the payment method ------------ */

	// The form's step attribute is the one place the step lives; the panels follow it.
	function step( form, n, focus ) {
		form.setAttribute( STEP, String( n ) );
		Array.prototype.forEach.call( form.querySelectorAll( '[data-saw-pay-step]' ), function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-saw-pay-step' ) !== String( n );
		} );
		if ( ! focus ) {
			return;
		}
		var target = 1 === n
			? form.querySelector( '[data-saw-pay-fields] [name]' )
			: form.querySelector( '[data-saw-pay-methods] input[type="radio"]:checked' ) || form.querySelector( '[data-saw-pay-methods] input[type="radio"]:enabled' ) || form.querySelector( '[data-saw-pay-methods]' );
		if ( target ) {
			target.focus();
		}
	}

	// Only the chosen method's info shows. A row that cannot be chosen has a disabled radio.
	// The split's amount controls are enabled only while it is chosen: the post takes every enabled control.
	document.addEventListener( 'change', function ( event ) {
		var list = event.target.closest ? event.target.closest( '[data-saw-pay-methods]' ) : null;
		if ( ! list || 'payment_method' !== event.target.name ) {
			return;
		}
		Array.prototype.forEach.call( list.querySelectorAll( '[data-saw-method]' ), function ( row ) {
			var radio = row.querySelector( 'input[name="payment_method"]' );
			var on = !! ( radio && radio.checked );
			var info = row.querySelector( '[data-saw-method-info]' );
			var box = row.querySelector( '[data-saw-split-box]' );
			if ( info ) {
				info.hidden = ! on;
			}
			if ( box ) {
				box.hidden = ! on;
				Array.prototype.forEach.call( box.querySelectorAll( 'input' ), function ( input ) {
					input.disabled = ! on;
				} );
			}
		} );
	} );

	/* ---- the split's wallet share ------------------------------------------ */

	// Read as the parent theme's checkout reads it: symbols dropped, a lone comma is the
	// decimal point, then clamped to 0..max.
	function splitAmount( raw, max ) {
		var clean = String( raw ).replace( /[^0-9.,-]/g, '' );
		clean = -1 !== clean.indexOf( ',' ) && -1 !== clean.indexOf( '.' ) ? clean.replace( /,/g, '' ) : clean.replace( /,/g, '.' );
		var n = parseFloat( clean );
		return Math.max( 0, Math.min( isNaN( n ) ? 0 : n, max ) );
	}

	// The store's price format, as wc_price() prints it.
	function money( n, cfg ) {
		var d = parseInt( cfg.decimals, 10 );
		d = isNaN( d ) ? 2 : d;
		var parts = n.toFixed( d ).split( '.' );
		var num = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, cfg.tsep || '' ) + ( parts[ 1 ] ? ( cfg.dsep || '.' ) + parts[ 1 ] : '' );
		return String( cfg.format || '%1$s%2$s' ).replace( '%1$s', cfg.symbol || '' ).replace( '%2$s', num );
	}

	document.addEventListener( 'input', function ( event ) {
		var input = event.target;
		if ( ! input.matches || ! input.matches( '[data-saw-split-amount]' ) ) {
			return;
		}
		var row = input.closest( '[data-saw-method]' );
		var box = input.closest( '[data-saw-split-box]' );
		var cfg = {};
		try {
			cfg = JSON.parse( box.getAttribute( 'data-saw-money' ) || '{}' );
		} catch ( e ) {}
		var basket = parseFloat( input.getAttribute( 'data-saw-basket' ) ) || 0;
		var part = splitAmount( input.value, parseFloat( input.max ) || 0 );
		var shown = row.querySelector( '[data-saw-split="part"]' );
		var rest = row.querySelector( '[data-saw-split="rest"]' );
		if ( shown ) {
			shown.textContent = money( part, cfg );
		}
		if ( rest ) {
			rest.textContent = money( Math.max( 0, basket - part ), cfg );
		}
		input.removeAttribute( 'aria-invalid' );
	} );

	// The faults WooCommerce would refuse, in its own words (printed on each field by the server).
	function fieldsValid( form ) {
		var first = null;
		clearFieldErrors( form );
		Array.prototype.forEach.call( form.querySelectorAll( '[data-saw-pay-fields] [name]' ), function ( input ) {
			var empty = '' === input.value.trim();
			var text = '';
			if ( empty && input.required ) {
				text = input.getAttribute( 'data-saw-err-required' ) || input.validationMessage;
			} else if ( ! empty && 'email' === input.type && ! input.checkValidity() ) {
				text = input.getAttribute( 'data-saw-err-email' ) || input.validationMessage;
			}
			if ( text ) {
				mark( input, text );
				first = first || input;
			}
		} );
		if ( first ) {
			first.focus();
		}
		return ! first;
	}

	// Registered before the tick's handler: Pay on step 1 sends nothing and asks for no tick.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.matches || ! form.matches( '[data-saw-consent]' ) || '1' !== form.getAttribute( STEP ) ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( fieldsValid( form ) ) {
			step( form, 2, true );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var back = event.target.closest ? event.target.closest( '[data-saw-pay-back]' ) : null;
		var form = back ? back.closest( '[data-saw-consent]' ) : null;
		if ( form ) {
			clearFieldErrors( form );
			step( form, 1, true );
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
				// A billing field WooCommerce names is fixed on step 1, where it can be seen.
				if ( namesField( form, errors ) && form.hasAttribute( STEP ) ) {
					step( form, 1, false );
				}
				var placed = fieldErrors( form, errors );
				if ( ! errors.textContent.trim() && ! placed ) {
					errors.textContent = errors.getAttribute( 'data-saw-fallback' ) || '';
				}
				errors.hidden = ! errors.textContent.trim();
				// The parent's amount errors carry no field name: matched by their words.
				var amount = form.querySelector( '[data-saw-split-amount]:enabled' );
				var text = errors.textContent;
				if ( amount && [ 'data-saw-err-zero', 'data-saw-err-whole' ].some( function ( attr ) {
					var words = amount.getAttribute( attr );
					return words && -1 !== text.indexOf( words );
				} ) ) {
					amount.setAttribute( 'aria-invalid', 'true' );
					amount.focus();
				}
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
	function fieldFor( form, name ) {
		var inputs = form.querySelectorAll( '[data-saw-pay-fields] [name]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			if ( inputs[ i ].name === name ) {
				return inputs[ i ];
			}
		}
		return null;
	}

	function namesField( form, box ) {
		return Array.prototype.some.call( box.querySelectorAll( '[data-id]' ), function ( item ) {
			return !! fieldFor( form, item.getAttribute( 'data-id' ) );
		} );
	}

	function mark( input, text ) {
		var msg = document.createElement( 'p' );
		msg.className = 'saw-field__err';
		msg.id = input.id + '-err';
		msg.textContent = text;
		// After the field, not inside its label: the label's text is the input's name.
		( input.closest( '.saw-field' ) || input ).insertAdjacentElement( 'afterend', msg );
		input.setAttribute( 'aria-invalid', 'true' );
		input.setAttribute( 'aria-describedby', msg.id );
	}

	function fieldErrors( form, box ) {
		var placed = 0;
		var first = null;
		Array.prototype.forEach.call( box.querySelectorAll( '[data-id]' ), function ( item ) {
			var input = fieldFor( form, item.getAttribute( 'data-id' ) );
			if ( ! input ) {
				return;
			}
			mark( input, item.textContent.trim() );
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
		Array.prototype.forEach.call( form.querySelectorAll( '[data-saw-pay-fields] [aria-invalid], [data-saw-split-amount][aria-invalid]' ), function ( input ) {
			input.removeAttribute( 'aria-invalid' );
			input.removeAttribute( 'aria-describedby' );
		} );
	}

	// A page left for a payment page or the checkout comes back busy, with Pay and perhaps the
	// fields disabled: whoever sees it again (Back, or the sheet opened anew) can pay again.
	function reset( form ) {
		var panel = form.closest( '.saw-paysheet' ) || form;
		var fields = form.querySelector( '[data-saw-pay-fields]' );
		var button = form.querySelector( 'button[type="submit"]' );
		var errors = form.querySelector( '[data-saw-pay-errors]' );
		if ( fields ) {
			fields.disabled = false;
		}
		if ( button ) {
			button.disabled = false;
		}
		panel.removeAttribute( 'aria-busy' );
		if ( errors ) {
			errors.hidden = true;
		}
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
		var consent = el.querySelector( '[data-saw-consent]' );
		if ( consent ) {
			reset( consent );
		}
		// Every opening starts on the fields, with what was typed so far.
		var form = el.querySelector( '[' + STEP + ']' );
		if ( form ) {
			step( form, 1, false );
		}
		el.hidden = false;
		document.documentElement.classList.add( 'saw-sheet-open' );
		var close = el.querySelector( '[data-saw-sheet-close]' );
		if ( close ) {
			close.focus();
		}
	}

	function close() {
		var el = sheet();
		// While Pay is being sent the sheet stays: its answer, an error included, shows there.
		if ( ! el || el.hidden || el.querySelector( '.saw-paysheet[aria-busy="true"]' ) ) {
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

	/* ---- opened by a link (?saw_pay=1) ----------------------------------- */

	// The competition page is cached without the sheet, which carries the basket and
	// billing values: it is fetched from the basket before it can open.
	( function () {
		var OPEN = 'saw_pay';
		var cfg = window.neraSawBasket || {};
		if ( ! window.URL || ! window.history || ! window.history.replaceState || ! sheet() ) {
			return;
		}
		var url = new URL( window.location.href );
		if ( ! url.searchParams.has( OPEN ) ) {
			return;
		}
		url.searchParams.delete( OPEN );
		window.history.replaceState( window.history.state, '', url.pathname + url.search + url.hash );

		// Before you pay as a page still takes the payment without the sheet.
		function fallback() {
			if ( cfg.next ) {
				window.location.href = cfg.next;
			}
		}

		if ( ! window.fetch || ! window.FormData || ! cfg.refresh || ! cfg.sheet ) {
			fallback();
			return;
		}

		var body = new FormData();
		body.append( 'saw_basket', '1' );
		fetch( cfg.refresh, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.ok ? r.json() : Promise.reject( new Error( r.status ) );
			} )
			.then( function ( json ) {
				var html = json && json.fragments ? json.fragments[ cfg.sheet ] : '';
				var slot = html ? document.querySelector( cfg.sheet ) : null;
				if ( ! slot ) {
					throw new Error( 'no sheet' );
				}
				slot.outerHTML = html;
				document.dispatchEvent( new CustomEvent( 'saw:paysheet' ) );
			} )
			.catch( fallback );
	}() );
}() );
