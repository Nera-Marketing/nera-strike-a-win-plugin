/**
 * Strike A Win demo seeder — staged AJAX with live per-phase progress.
 *
 * The browser drives the operation one phase at a time: each request runs a
 * single phase server-side and returns the `next` phase (and, for submissions, a
 * progress cursor). We loop until `next` is null, updating a status log + bar.
 */
( function ( $ ) {
	'use strict';

	if ( typeof neraSawSeeder === 'undefined' ) {
		return;
	}

	var cfg = neraSawSeeder;
	var $status = $( '#saw-seeder-status' );
	var $title = $status.find( '.saw-seeder-status__title' );
	var $log = $status.find( '.saw-seeder-status__log' );
	var $bar = null;
	var busy = false;
	var $phaseLine = null; // the log line for the phase currently reporting.
	var phaseKey = null;   // its phase key, so repeat responses update in place.

	function formatNumber( n ) {
		return String( n ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	function setBusy( isBusy ) {
		busy = isBusy;
		$( '.saw-seeder-form' ).find( 'input, button, select, textarea' ).prop( 'disabled', isBusy );
	}

	function resetStatus( op ) {
		$status.removeClass( 'is-success is-error' ).addClass( 'is-active' );
		$log.empty();
		$phaseLine = null;
		phaseKey = null;
		ensureBar();
		setBarTone( op );
		setBar( 0, 0 );
	}

	function setBarTone( op ) {
		if ( ! $bar ) {
			return;
		}
		$bar.attr( 'data-tone', op === 'wipe' ? 'wipe' : 'seed' );
	}

	function ensureBar() {
		if ( $bar && $bar.length ) {
			return;
		}
		$bar = $( '<div class="saw-seeder-bar"><span class="saw-seeder-bar__fill"></span></div>' );
		$status.find( '.saw-seeder-status__head' ).after( $bar );
	}

	function setBar( current, total ) {
		if ( ! $bar ) {
			return;
		}
		var pct = total > 0 ? Math.round( ( current / total ) * 100 ) : 0;
		$bar.find( '.saw-seeder-bar__fill' ).css( 'width', pct + '%' );
		$bar.attr( 'data-indeterminate', total > 0 ? 'false' : 'true' );
	}

	function finishStatus( ok, headline ) {
		$status.removeClass( 'is-active' ).addClass( ok ? 'is-success' : 'is-error' );
		$title.text( headline );
		if ( ok && $bar ) {
			setBar( 1, 1 );
		}
	}

	function appendLogLine( text, done ) {
		var $li = $( '<li></li>' ).text( text );
		if ( done ) {
			$li.addClass( 'is-done' );
		}
		$log.append( $li );
		return $li;
	}

	function updateStats( stats ) {
		if ( ! stats ) {
			return;
		}
		$( '[data-saw-stat="bank"]' ).text( formatNumber( stats.bank || 0 ) );
		$( '[data-saw-stat="competitions"]' ).text( formatNumber( stats.competitions || 0 ) );
		$( '[data-saw-stat="users"]' ).text( formatNumber( stats.users || 0 ) );
	}

	// Collect the form's option fields (excludes the admin-post action + nonce,
	// which we override for admin-ajax).
	function baseParams( $form ) {
		var params = {};
		$.each( $form.serializeArray(), function ( _i, f ) {
			if ( f.name === 'action' || f.name === '_wpnonce' || f.name === '_wp_http_referer' ) {
				return;
			}
			params[ f.name ] = f.value;
		} );
		params.action = cfg.action;
		params.nonce = cfg.nonce;
		return params;
	}

	function runPhase( params, phase, offset, headline ) {
		var data = $.extend( {}, params, { phase: phase || '', offset: offset || 0 } );

		$.ajax( { url: cfg.ajaxUrl, type: 'POST', data: data, dataType: 'json' } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					var errMsg = ( res && res.data && res.data.message ) || cfg.i18n.failed;
					appendLogLine( errMsg, true );
					finishStatus( false, errMsg );
					setBusy( false );
					return;
				}

				var d = res.data || {};
				if ( d.message ) {
					var text = ( d.label ? d.label + ': ' : '' ) + d.message;
					if ( d.phase && d.phase === phaseKey && $phaseLine ) {
						// Same phase reporting again (a chunk): update its line in place.
						$phaseLine.text( text );
					} else {
						// New phase: finish the previous line, start a fresh one.
						if ( $phaseLine ) {
							$phaseLine.addClass( 'is-done' );
						}
						$phaseLine = appendLogLine( text, false );
						phaseKey = d.phase || null;
					}
					// A phase is complete once the next step isn't itself.
					if ( $phaseLine && d.next !== d.phase ) {
						$phaseLine.addClass( 'is-done' );
					}
				}
				if ( d.progress ) {
					setBar( d.progress.current, d.progress.total );
				}
				updateStats( d.stats );

				if ( d.next ) {
					// Continue with the next phase (carry the cursor for chunks).
					runPhase( params, d.next, d.offset || 0, headline );
				} else {
					finishStatus( true, cfg.i18n.done );
					setBusy( false );
				}
			} )
			.fail( function () {
				appendLogLine( cfg.i18n.failed, true );
				finishStatus( false, cfg.i18n.failed );
				setBusy( false );
			} );
	}

	function submitForm( $form ) {
		if ( busy ) {
			return;
		}

		var op = $form.data( 'op' ) || '';
		var confirmMsg = $form.data( 'confirm' );
		if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
			return;
		}

		// Capture the form fields BEFORE disabling inputs — serializeArray() skips
		// disabled controls, so reading after setBusy(true) would drop `op` and all
		// the option fields (the request would arrive with op empty).
		var params = baseParams( $form );

		var headline = op === 'wipe' ? cfg.i18n.wiping : cfg.i18n.seeding;
		resetStatus( op );
		$title.text( headline );
		setBusy( true );

		runPhase( params, '', 0, headline );
	}

	$( document ).on( 'submit', '.saw-seeder-form', function ( e ) {
		e.preventDefault();
		submitForm( $( this ) );
	} );
}( jQuery ) );
