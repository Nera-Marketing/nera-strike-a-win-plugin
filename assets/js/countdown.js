/**
 * Countdown to the draw: the chip on each competition card and the "Time left" fact
 * on the competition page.
 *
 * The server prints the text for the moment it rendered (so there is something right
 * without this script) and the closing time, plus ITS OWN clock for that request.
 * Time is measured against that, not the visitor's clock: a laptop a few minutes out
 * would otherwise show every competition closing early or late. The offset is taken
 * once, from the first countdown on the page.
 *
 * When one reaches zero it reads "Closed" and, on the competition page, the buy form
 * is closed too (competition-buy.js exposes `form.sawCloseEntries()`), so nobody
 * keeps buying into a draw that has just closed. The server still refuses a late add;
 * this only stops it being offered.
 *
 * @package Nera_Strikeawin
 */
( function () {
	'use strict';

	var cfg = window.nsawCountdown || {};
	var nodes = document.querySelectorAll( '[data-saw-countdown]' );
	if ( ! nodes.length ) {
		return;
	}

	var units = cfg.units || { d: 'd', h: 'h', m: 'm', s: 's' };
	var leftFormat = cfg.left || '%s left';
	var closedText = cfg.closed || 'Closed';

	var serverNow = parseInt( nodes[ 0 ].getAttribute( 'data-saw-now' ), 10 );
	var offset = serverNow ? serverNow * 1000 - Date.now() : 0;

	function text( seconds, suffix ) {
		var d = Math.floor( seconds / 86400 );
		var h = Math.floor( ( seconds % 86400 ) / 3600 );
		var m = Math.floor( ( seconds % 3600 ) / 60 );
		var s = seconds % 60;
		var out;
		if ( d > 0 ) {
			out = d + units.d + ' ' + h + units.h;
		} else if ( h > 0 ) {
			out = h + units.h + ' ' + m + units.m;
		} else if ( m > 0 ) {
			out = m + units.m + ' ' + s + units.s;
		} else {
			out = s + units.s;
		}
		return suffix ? leftFormat.replace( '%s', out ) : out;
	}

	function closeEntries() {
		var form = document.querySelector( '[data-saw-buy]' );
		if ( form && typeof form.sawCloseEntries === 'function' ) {
			form.sawCloseEntries();
		}
	}

	var timer = null;

	function tick() {
		var now = Date.now() + offset;
		var soonest = Infinity;

		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var left = Math.ceil( ( parseInt( node.getAttribute( 'data-saw-countdown' ), 10 ) * 1000 - now ) / 1000 );

			if ( left <= 0 ) {
				if ( ! node.classList.contains( 'is-ended' ) ) {
					node.classList.add( 'is-ended' );
					node.textContent = closedText;
					closeEntries();
				}
				continue;
			}

			var next = text( left, node.hasAttribute( 'data-saw-suffix' ) );
			if ( node.textContent !== next ) {
				node.textContent = next;
			}
			soonest = Math.min( soonest, left );
		}

		// Every second inside the last hour, where seconds are shown; every 15 seconds
		// otherwise, which is as often as a minutes-resolution display can change.
		if ( soonest !== Infinity ) {
			timer = window.setTimeout( tick, soonest < 3600 ? 1000 : 15000 );
		}
	}

	// Stop ticking in a hidden tab, and catch up the moment it is visible again.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			window.clearTimeout( timer );
		} else {
			window.clearTimeout( timer );
			tick();
		}
	} );

	tick();
}() );
