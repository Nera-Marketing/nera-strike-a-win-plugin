/**
 * "My runs & tickets" — page through each competition's completed runs without
 * reloading.
 *
 * The page renders the first page of every competition on the server, and the pager
 * is made of ordinary links (`?runs_page_{id}=N`), so nothing here is required: with
 * scripting off, or if a request fails, following the link does the same thing with
 * a reload. This only intercepts the click, asks the REST route for that page
 * already rendered, and swaps it in. See Nera_SAW_Account_Pages::rest_completed_runs().
 *
 * Each competition pages independently. The address is kept up to date with
 * history.replaceState(), so a reload or a copied link lands on the same pages.
 *
 * @package Nera_Strikeawin
 */
( function () {
	'use strict';

	var cfg = window.nsawRunsTickets;
	if ( ! cfg || ! window.fetch ) {
		return;
	}

	function pageArg( competition ) {
		return 'runs_page_' + competition;
	}

	function rememberPage( competition, page ) {
		if ( ! window.history || ! window.history.replaceState || ! window.URL ) {
			return;
		}
		try {
			var url = new URL( window.location.href );
			if ( page > 1 ) {
				url.searchParams.set( pageArg( competition ), String( page ) );
			} else {
				url.searchParams.delete( pageArg( competition ) );
			}
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {
			// The address is a nicety; the swap does not depend on it.
		}
	}

	function load( group, page, fallbackHref ) {
		var competition = group.getAttribute( 'data-competition' );
		var list = group.querySelector( '[data-saw-runs-list]' );
		var pager = group.querySelector( '[data-saw-runs-pager]' );
		if ( ! list || ! pager ) {
			window.location.href = fallbackHref;
			return;
		}

		group.classList.add( 'is-loading' );
		group.setAttribute( 'aria-busy', 'true' );

		fetch( cfg.url + '?competition_id=' + encodeURIComponent( competition ) + '&page=' + encodeURIComponent( page ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' }
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				list.innerHTML = data.html;
				pager.innerHTML = data.pager;
				rememberPage( competition, data.page );

				// Keep the reader where they were: back to the top of this list if the new
				// page left it off-screen, and focus on the list so a screen reader
				// announces the change (the list is aria-live).
				var top = group.getBoundingClientRect().top;
				if ( top < 0 || top > window.innerHeight * 0.6 ) {
					group.scrollIntoView( { block: 'start', behavior: 'smooth' } );
				}
				list.focus( { preventScroll: true } );
			} )
			.catch( function () {
				// Anything wrong: do it the old way.
				window.location.href = fallbackHref;
			} )
			.then( function () {
				group.classList.remove( 'is-loading' );
				group.removeAttribute( 'aria-busy' );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '[data-saw-runs] a[data-saw-page]' ) : null;
		if ( ! link || event.metaKey || event.ctrlKey || event.shiftKey || event.button > 0 ) {
			return;
		}

		var group = link.closest( '[data-saw-runs]' );
		var page = parseInt( link.getAttribute( 'data-saw-page' ), 10 );
		if ( ! group || ! page ) {
			return;
		}

		event.preventDefault();
		load( group, page, link.href );
	} );
}() );
