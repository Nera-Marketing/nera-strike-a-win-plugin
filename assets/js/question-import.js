/**
 * Question bank CSV import modal + chunked export progress.
 */
( function ( $ ) {
	'use strict';

	if ( typeof neraSawImport === 'undefined' ) {
		return;
	}

	var cfg = neraSawImport;
	var token = null;
	var batchId = '';
	var busy = false;
	var exportToken = null;
	var dismissTimer = null;

	function $modal() {
		return $( '#saw-import-modal' );
	}

	function $job() {
		return $( '#saw-job-progress' );
	}

	function setBusy( isBusy ) {
		busy = !! isBusy;
		$( '#saw-import-open, #saw-export-start' ).prop( 'disabled', busy );
	}

	function placeExportProgress() {
		var $el = $job();
		if ( ! $el.length ) {
			return;
		}
		var $nav = $( '#posts-filter .tablenav.top' ).first();
		if ( $nav.length && ! $el.parent().is( '#posts-filter' ) ) {
			$nav.before( $el );
		}
	}

	function setStep( name ) {
		var $m = $modal();
		$m.find( '.saw-import-step' ).attr( 'hidden', true );
		$m.find( '.saw-import-step[data-step="' + name + '"]' ).removeAttr( 'hidden' );
	}

	function openModal() {
		if ( busy ) {
			return;
		}
		token = null;
		batchId = '';
		$( '#saw-import-file' ).val( '' );
		setStep( 'upload' );
		$modal().removeAttr( 'hidden' ).addClass( 'is-open' );
		$( 'body' ).addClass( 'saw-import-open' );
	}

	function closeModal( force ) {
		if ( busy && ! force ) {
			return;
		}
		$modal().attr( 'hidden', true ).removeClass( 'is-open' );
		$( 'body' ).removeClass( 'saw-import-open' );
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function truncate( s, n ) {
		s = String( s || '' );
		return s.length > n ? s.slice( 0, n - 1 ) + '…' : s;
	}

	function setBar( $fill, current, total ) {
		var pct = total > 0 ? Math.round( ( current / total ) * 100 ) : 0;
		$fill.css( 'width', pct + '%' );
		$fill.closest( '.saw-seeder-bar' ).attr( 'data-indeterminate', total > 0 ? 'false' : 'true' );
	}

	function statusText( verb, current, total ) {
		return verb + ' ' + current + ' / ' + total;
	}

	function fillPreview( data ) {
		var s = data.summary || {};
		var parts = [
			( s.create || 0 ) + ' ' + cfg.i18n.create,
			( s.update || 0 ) + ' ' + cfg.i18n.overwrite
		];
		if ( ( s.warning || 0 ) > 0 ) {
			parts.push( s.warning + ' ' + ( cfg.i18n.warning || 'warning' ) );
		}
		parts.push( ( s.rejected || 0 ) + ' ' + cfg.i18n.rejected );
		$( '#saw-import-summary' ).text( parts.join( ' / ' ) );

		var $tb = $( '#saw-import-preview-table tbody' ).empty();
		( data.rows || [] ).forEach( function ( r ) {
			var notes = [].concat( r.errors || [], r.warnings || [] ).join( ' · ' );
			var hasWarn = ( r.warnings || [] ).length > 0;
			var actionLabel = r.action;
			if ( r.action === 'update' ) {
				actionLabel = cfg.i18n.overwrite + ( r.id ? ' #' + r.id : '' );
			} else if ( r.action === 'create' ) {
				actionLabel = cfg.i18n.create;
			} else if ( r.action === 'reject' ) {
				actionLabel = cfg.i18n.rejected;
			}
			if ( hasWarn && r.action !== 'reject' ) {
				actionLabel += ' ' + ( cfg.i18n.toDraft || '→ draft' );
			}
			var cls = 'saw-import-row--ok';
			if ( r.action === 'reject' ) {
				cls = 'saw-import-row--reject';
			} else if ( hasWarn ) {
				cls = 'saw-import-row--warn';
			}
			$tb.append(
				'<tr class="' + cls + '">' +
					'<td>' + r.line + '</td>' +
					'<td>' + escapeHtml( actionLabel ) + '</td>' +
					'<td>' + escapeHtml( truncate( r.question, 80 ) ) + '</td>' +
					'<td>' + escapeHtml( r.level ) + '</td>' +
					'<td>' + escapeHtml( r.category ) + '</td>' +
					'<td>' + escapeHtml( truncate( r.correct, 40 ) ) + '</td>' +
					'<td>' + escapeHtml( notes ) + '</td>' +
				'</tr>'
			);
		} );

		var actionable = ( s.create || 0 ) + ( s.update || 0 ) + ( s.warning || 0 );
		$( '#saw-import-confirm' ).prop( 'disabled', actionable < 1 );
		token = data.token;
		setStep( 'preview' );
	}

	function doPreview() {
		var fileInput = document.getElementById( 'saw-import-file' );
		if ( ! fileInput || ! fileInput.files || ! fileInput.files[0] ) {
			window.alert( cfg.i18n.noFile );
			return;
		}
		var fd = new FormData();
		fd.append( 'action', cfg.upload );
		fd.append( 'nonce', cfg.nonce );
		fd.append( 'file', fileInput.files[0] );

		$( '#saw-import-preview' ).prop( 'disabled', true );
		$.ajax( {
			url: cfg.ajaxUrl,
			type: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			dataType: 'json'
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || cfg.i18n.failed );
					return;
				}
				fillPreview( res.data );
			} )
			.fail( function () {
				window.alert( cfg.i18n.failed );
			} )
			.always( function () {
				$( '#saw-import-preview' ).prop( 'disabled', false );
			} );
	}

	function applyChunk( offset ) {
		$.ajax( {
			url: cfg.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: cfg.apply,
				nonce: cfg.nonce,
				token: token,
				offset: offset || 0,
				batch_id: batchId,
				status: $( 'input[name="saw_import_status"]:checked' ).val() || 'publish'
			}
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					setBusy( false );
					$( '#saw-import-status' ).text( ( res && res.data && res.data.message ) || cfg.i18n.failed );
					$( '#saw-import-done-msg' ).text( cfg.i18n.failed );
					setStep( 'done' );
					return;
				}
				var d = res.data || {};
				batchId = d.batch_id || batchId;
				var cur = d.progress ? d.progress.current : 0;
				var tot = d.progress ? d.progress.total : 0;
				setBar( $( '#saw-import-bar' ), cur, tot );
				$( '#saw-import-status' ).text( statusText( cfg.i18n.importing, cur, tot ) );
				if ( d.done ) {
					setBusy( false );
					var msg = cfg.i18n.importDone + ': ' + d.created + ' created, ' + d.updated + ' updated.';
					if ( d.failed && d.failed.length ) {
						msg += ' ' + d.failed.length + ' row error(s).';
					}
					$( '#saw-import-done-msg' ).text( msg );
					setStep( 'done' );
					return;
				}
				applyChunk( d.offset || 0 );
			} )
			.fail( function () {
				setBusy( false );
				$( '#saw-import-status' ).text( cfg.i18n.failed );
				$( '#saw-import-done-msg' ).text( cfg.i18n.failed );
				setStep( 'done' );
			} );
	}

	function startImport() {
		if ( ! token || busy ) {
			return;
		}
		setBusy( true );
		batchId = '';
		setBar( $( '#saw-import-bar' ), 0, 0 );
		$( '#saw-import-status' ).text( statusText( cfg.i18n.importing, 0, 0 ) );
		setStep( 'running' );
		applyChunk( 0 );
	}

	function triggerDownload( url, filename ) {
		if ( ! url ) {
			return;
		}
		// Programmatic <a download> — more reliable than a hidden iframe for
		// Content-Disposition attachments (and avoids wp_nonce_url &amp; issues).
		var a = document.createElement( 'a' );
		a.href = url;
		a.setAttribute( 'download', filename || 'download.csv' );
		a.style.display = 'none';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
	}

	function showExportProgress() {
		placeExportProgress();
		$job().removeAttr( 'hidden' ).removeClass( 'is-done is-error' );
	}

	function hideExportProgressSoon() {
		if ( dismissTimer ) {
			window.clearTimeout( dismissTimer );
		}
		dismissTimer = window.setTimeout( function () {
			$job().attr( 'hidden', true ).removeClass( 'is-done is-error' );
			dismissTimer = null;
		}, 3000 );
	}

	function exportChunk( offset ) {
		$.ajax( {
			url: cfg.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: cfg.exportChunk,
				nonce: cfg.nonce,
				token: exportToken,
				offset: offset || 0
			}
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					setBusy( false );
					$job().addClass( 'is-error' );
					$( '#saw-job-status' ).text( ( res && res.data && res.data.message ) || cfg.i18n.failed );
					hideExportProgressSoon();
					return;
				}
				var d = res.data || {};
				var cur = d.progress ? d.progress.current : 0;
				var tot = d.progress ? d.progress.total : 0;
				setBar( $( '#saw-job-bar' ), cur, tot );
				$( '#saw-job-status' ).text( statusText( cfg.i18n.exporting, cur, tot ) );
				if ( d.done ) {
					setBusy( false );
					setBar( $( '#saw-job-bar' ), tot, tot );
					$( '#saw-job-status' ).text(
						cfg.i18n.exportDone + ' — ' + tot + ' ' + cfg.i18n.questions
					);
					$job().addClass( 'is-done' );
					triggerDownload( d.download, 'saw-questions.csv' );
					hideExportProgressSoon();
					return;
				}
				exportChunk( d.offset || 0 );
			} )
			.fail( function () {
				setBusy( false );
				$job().addClass( 'is-error' );
				$( '#saw-job-status' ).text( cfg.i18n.failed );
				hideExportProgressSoon();
			} );
	}

	function startExport() {
		if ( busy ) {
			return;
		}
		setBusy( true );
		exportToken = null;
		showExportProgress();
		setBar( $( '#saw-job-bar' ), 0, 0 );
		$( '#saw-job-status' ).text( statusText( cfg.i18n.exporting, 0, 0 ) );

		var selected = [];
		$( '#the-list input[name="post[]"]:checked' ).each( function () {
			var id = parseInt( $( this ).val(), 10 );
			if ( id > 0 ) {
				selected.push( id );
			}
		} );

		var data = {
			action: cfg.exportStart,
			nonce: cfg.nonce,
			filters: cfg.filters || {}
		};
		if ( selected.length ) {
			data.selected = selected;
		}

		$.ajax( {
			url: cfg.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: data
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					setBusy( false );
					$job().addClass( 'is-error' );
					$( '#saw-job-status' ).text( ( res && res.data && res.data.message ) || cfg.i18n.failed );
					hideExportProgressSoon();
					return;
				}
				exportToken = res.data.token;
				var total = res.data.total || 0;
				$( '#saw-job-status' ).text( statusText( cfg.i18n.exporting, 0, total ) );
				setBar( $( '#saw-job-bar' ), 0, total );
				exportChunk( 0 );
			} )
			.fail( function () {
				setBusy( false );
				$job().addClass( 'is-error' );
				$( '#saw-job-status' ).text( cfg.i18n.failed );
				hideExportProgressSoon();
			} );
	}

	function doUndo() {
		if ( busy ) {
			return;
		}
		if ( ! window.confirm( cfg.i18n.undoConfirm ) ) {
			return;
		}
		$.post( cfg.ajaxUrl, { action: cfg.undo, nonce: cfg.nonce }, null, 'json' )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || cfg.i18n.failed );
					return;
				}
				window.alert( cfg.i18n.undoDone + ' (' + ( res.data.trashed || 0 ) + ')' );
				window.location.reload();
			} )
			.fail( function () {
				window.alert( cfg.i18n.failed );
			} );
	}

	$( function () {
		placeExportProgress();
	} );

	$( document ).on( 'click', '#saw-import-open', function ( e ) {
		e.preventDefault();
		openModal();
	} );
	$( document ).on( 'click', '#saw-export-start', function ( e ) {
		e.preventDefault();
		startExport();
	} );
	$( document ).on( 'click', '#saw-import-close', function () {
		closeModal( false );
	} );
	$( document ).on( 'click', '#saw-import-finish', function () {
		closeModal( true );
		window.location.reload();
	} );
	$( document ).on( 'click', '#saw-import-preview', doPreview );
	$( document ).on( 'click', '#saw-import-back', function () {
		setStep( 'upload' );
	} );
	$( document ).on( 'click', '#saw-import-confirm', startImport );
	$( document ).on( 'click', '#saw-import-undo', doUndo );
	$( document ).on( 'click', '#saw-import-example', function ( e ) {
		e.preventDefault();
		var url = ( cfg.exampleUrl || window.neraSawImportExampleUrl || $( this ).attr( 'href' ) || '' );
		if ( ! url || url === '#' ) {
			return;
		}
		triggerDownload( url, 'saw-questions-example.csv' );
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && $modal().hasClass( 'is-open' ) ) {
			closeModal( false );
		}
	} );
	$( document ).on( 'click', '#saw-import-modal', function ( e ) {
		if ( e.target === this ) {
			closeModal( false );
		}
	} );
}( jQuery ) );
