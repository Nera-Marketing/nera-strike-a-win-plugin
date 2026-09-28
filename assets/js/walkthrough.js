/*
 * The walkthrough, without the page flash.
 *
 * Progressive enhancement, not a rewrite. Every step is still a real URL that the
 * server renders on its own; this only stops the browser throwing the page away
 * and repainting it to show a card that moved two centimetres.
 *
 * What that buys, and why it is worth the file:
 *   - the step stays linkable and the back button still walks the steps, because
 *     the URL is pushed rather than faked;
 *   - with scripting off, or if a fetch fails, the links are ordinary links and
 *     the walkthrough works exactly as it did;
 *   - the practice question is still scored on the server. The answer key is not
 *     in this file and never reaches the browser.
 *
 * It swaps one element: the card. The header, footer and page chrome are already
 * correct, so re-rendering them is the flash we came to remove.
 */
( function () {
	'use strict';

	var CARD = '.saw-wt__card';
	var root = document.querySelector( '.saw-wt' );
	if ( ! root || ! window.fetch || ! window.history || ! window.DOMParser ) {
		return;
	}

	var page = window.location.pathname;
	var busy = false;

	function reduced() {
		return window.matchMedia && window.matchMedia( '( prefers-reduced-motion: reduce )' ).matches;
	}

	/* A link this script handles: same page, same origin, inside the card. */
	function stepLink( target ) {
		var a = target.closest ? target.closest( 'a[href]' ) : null;
		if ( ! a || ! root.contains( a ) ) {
			return null;
		}
		if ( a.target || a.hasAttribute( 'download' ) ) {
			return null;
		}

		var url;
		try {
			url = new URL( a.href, window.location.href );
		} catch ( e ) {
			return null;
		}

		if ( url.origin !== window.location.origin || url.pathname !== page ) {
			return null; // Close and Finish leave the walkthrough; let them.
		}
		return url;
	}

	function swap( html ) {
		var next = new DOMParser().parseFromString( html, 'text/html' ).querySelector( CARD );
		var current = root.querySelector( CARD );
		if ( ! next || ! current ) {
			return false;
		}

		current.replaceWith( next );

		/*
		 * Move focus to the new step's heading. Without this the swap is silent to a
		 * screen reader and leaves keyboard focus on a button that no longer exists,
		 * which drops the user back at the top of the document on the next Tab.
		 */
		var heading = next.querySelector( '.saw-wt__title' );
		if ( heading ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
		}

		if ( ! reduced() ) {
			next.animate(
				[ { opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' } ],
				{ duration: 220, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' }
			);
		}
		return true;
	}

	function go( url, push ) {
		if ( busy ) {
			return;
		}
		busy = true;
		root.setAttribute( 'aria-busy', 'true' );

		fetch( url.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( r.status );
				}
				return r.text();
			} )
			.then( function ( html ) {
				if ( ! swap( html ) ) {
					throw new Error( 'no card' );
				}
				if ( push ) {
					window.history.pushState( {}, '', url.href );
				}
			} )
			.catch( function () {
				// Whatever went wrong, the plain link still works. Take it.
				window.location.href = url.href;
			} )
			.finally( function () {
				busy = false;
				root.removeAttribute( 'aria-busy' );
			} );
	}

	root.addEventListener( 'click', function ( e ) {
		// Leave modified clicks alone — a new tab is a reasonable thing to want.
		if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}
		var url = stepLink( e.target );
		if ( ! url ) {
			return;
		}
		e.preventDefault();
		go( url, true );
	} );

	window.addEventListener( 'popstate', function () {
		var url;
		try {
			url = new URL( window.location.href );
		} catch ( e ) {
			return;
		}
		if ( url.pathname === page ) {
			go( url, false );
		}
	} );
}() );
