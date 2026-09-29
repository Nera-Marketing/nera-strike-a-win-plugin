/**
 * Login / Register tabs, desktop only (900px and up).
 *
 * The two panels are plain <details> so the page works identically with
 * scripting off — both simply stay open, stacked, same as the mobile
 * accordion. This script only adds the one thing plain HTML can't express
 * conditionally per breakpoint: closing the other panel when one opens, but
 * only above 900px. Below that width it does nothing at all, on purpose —
 * the mobile accordion's panels are independent by design (ADR: same
 * pattern as the logged-in My Account screen), and must stay that way.
 */
( function () {
	var group = document.querySelector( '.saw-loginreg' );
	if ( ! group ) {
		return;
	}

	var panels = Array.prototype.slice.call(
		group.querySelectorAll( 'details.saw-loginreg__panel' )
	);
	if ( panels.length < 2 ) {
		return;
	}

	function isTabbed() {
		return window.matchMedia( '(min-width: 900px)' ).matches;
	}

	/*
	 * Desktop's first load: exactly one tab active, never both panels open in
	 * full, and never neither — a back/forward restore can hand the page
	 * <details> in whatever open/closed combination the visitor left them in
	 * (including both closed), so this does not assume panels[0] starts open
	 * just because the markup says so. Whichever panel is already open wins;
	 * with none (or more than one) open, the first panel is forced open and
	 * every other panel is forced closed.
	 */
	if ( isTabbed() ) {
		var active = panels.filter( function ( panel ) {
			return panel.open;
		} )[ 0 ] || panels[ 0 ];
		panels.forEach( function ( panel ) {
			panel.open = panel === active;
		} );
	}

	panels.forEach( function ( panel ) {
		panel.addEventListener( 'toggle', function () {
			if ( ! panel.open || ! isTabbed() ) {
				return;
			}
			panels.forEach( function ( other ) {
				if ( other !== panel ) {
					other.open = false;
				}
			} );
		} );
	} );
} )();
