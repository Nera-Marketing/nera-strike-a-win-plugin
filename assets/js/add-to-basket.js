/*
 * "Continue" — add the entry, then open the pay sheet over the page.
 *
 * The form still posts to WooCommerce and still redirects to Before you pay. This
 * takes the same add over fetch first, plays the site's two-note chime, and opens
 * the pay sheet the add's response carries, so the player pays without leaving
 * the competition page.
 *
 * Progressive enhancement, like the walkthrough:
 *   - no JavaScript, or a failed request, and the form submits normally;
 *   - the server does the same work either way, through the same validation, so
 *     basket segregation and the ceiling gate are not bypassed;
 *   - nothing here decides what was added. The response is WooCommerce's, and so
 *     is the sheet's total;
 *   - a page with no slot for the sheet goes on to Before you pay.
 *
 * THE SOUND IS SYNTHESISED, NOT A FILE
 * The main site's chime is two sine notes built with Web Audio, so this matches it
 * by playing the same two notes rather than by loading the theme's script — which
 * the section dequeues — or shipping an audio file the plugin would then have to
 * carry. Identical output, no asset, no dependency.
 */
( function () {
	'use strict';

	var cfg = window.neraSawBasket || {};
	var form = document.querySelector( '.saw-buy' );
	if ( ! form || ! window.fetch || ! cfg.ajaxUrl ) {
		return;
	}

	var busy = false;
	var ctx = null;

	/* ---- the chime ------------------------------------------------------- */

	function chime() {
		try {
			var Ctor = window.AudioContext || window.webkitAudioContext;
			if ( ! Ctor ) {
				return;
			}
			ctx = ctx || new Ctor();

			var play = function () {
				// C5 then E5, a beat apart — the same two notes the main site plays.
				[ [ 523, 0 ], [ 659, 0.15 ] ].forEach( function ( note ) {
					var osc = ctx.createOscillator();
					var gain = ctx.createGain();
					osc.connect( gain );
					gain.connect( ctx.destination );
					osc.frequency.value = note[ 0 ];
					osc.type = 'sine';
					gain.gain.setValueAtTime( 0.3, ctx.currentTime + note[ 1 ] );
					gain.gain.exponentialRampToValueAtTime( 0.001, ctx.currentTime + note[ 1 ] + 0.4 );
					osc.start( ctx.currentTime + note[ 1 ] );
					osc.stop( ctx.currentTime + note[ 1 ] + 0.4 );
				} );
			};

			// Browsers suspend audio until a gesture. This runs inside a click, so
			// resuming here is allowed.
			if ( 'suspended' === ctx.state ) {
				ctx.resume().then( play );
			} else {
				play();
			}
		} catch ( e ) {
			// No Web Audio, no chime. The toast still says what happened.
		}
	}

	/* ---- the add --------------------------------------------------------- */

	form.addEventListener( 'submit', function ( e ) {
		if ( busy ) {
			e.preventDefault();
			return;
		}

		var data = new FormData( form );
		var product = data.get( 'add-to-cart' );
		if ( ! product ) {
			return; // Nothing to enhance; let the browser post it.
		}

		e.preventDefault();
		busy = true;

		// Every Continue button (the form's and the phone bar's), not the first
		// .saw-cta: that can be a tier's Play quiz link.
		var buttons = Array.prototype.slice.call( form.querySelectorAll( '[data-saw-enter]' ) );
		buttons.forEach( function ( button ) {
			button.setAttribute( 'aria-busy', 'true' );
			button.disabled = true;
		} );
		var release = function () {
			busy = false;
			buttons.forEach( function ( button ) {
				button.removeAttribute( 'aria-busy' );
				button.disabled = false;
			} );
		};

		var body = new FormData();
		body.append( 'product_id', product );
		body.append( 'quantity', data.get( 'quantity' ) || '1' );
		if ( data.get( 'saw_tier' ) ) {
			body.append( 'saw_tier', data.get( 'saw_tier' ) );
		}
		// Says which basket this is for. WooCommerce's AJAX endpoint has no path of
		// its own, and guessing from the referer is the weaker of the two signals.
		body.append( 'saw_basket', '1' );

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.ok ? r.json() : Promise.reject( new Error( r.status ) );
			} )
			.then( function ( json ) {
				// WooCommerce answers a refusal with an error and a URL to fall back to,
				// rather than an HTTP status. Honour it instead of claiming success.
				if ( ! json || json.error ) {
					throw new Error( 'refused' );
				}

				chime();

				var sheet = cfg.sheet && json.fragments ? json.fragments[ cfg.sheet ] : '';
				var slot = sheet ? document.querySelector( cfg.sheet ) : null;
				if ( ! slot ) {
					window.location.href = cfg.next || form.action;
					return;
				}

				slot.outerHTML = sheet;
				release();
				document.dispatchEvent( new CustomEvent( 'saw:paysheet' ) );
			} )
			.catch( function () {
				// Anything at all — submit the form for real and let the server answer.
				release();
				form.submit();
			} );
	} );
}() );
