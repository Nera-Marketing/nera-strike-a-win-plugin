/**
 * Strike A Win — single-product tier widget + runs pill.
 *
 * Swaps the theme purchase card's native price/quantity block for tier tabs
 * (selecting a tab drives the price + checkout tier), keeps a quantity selector
 * meaning "number of runs", and injects a "runs to play" pill beside the title.
 *
 * No theme edits: we locate the theme's DOM and insert next to it. Add-to-cart
 * uses the standard WooCommerce GET handler, carrying saw_tier + quantity, which
 * Nera_SAW_Cart_Entry reads on add_cart_item_data.
 */
( function () {
	'use strict';

	// Unconditional load marker (before any guard) so we can tell "script never
	// ran" apart from "guard bailed". console.warn shows at the default level.
	if ( window.console ) {
		console.warn( '[SAW] product-tiers.js loaded; NeraSAWProduct is', typeof window.NeraSAWProduct, window.NeraSAWProduct && window.NeraSAWProduct.tiers ? '(' + window.NeraSAWProduct.tiers.length + ' tiers)' : '' );
	}

	var data = window.NeraSAWProduct;
	if ( ! data || ! Array.isArray( data.tiers ) || ! data.tiers.length ) {
		if ( window.console ) {
			console.warn( '[SAW] product-tiers.js bailed: NeraSAWProduct missing or tiers not an array.' );
		}
		return;
	}

	var i18n = data.i18n || {};
	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}
	function sprintfN( str, n ) {
		return String( str ).replace( '%d', n );
	}

	// Selectable tiers first; fall back to first tier if none offered.
	var offered = data.tiers.filter( function ( x ) { return x.offered; } );
	var selectable = offered.length ? offered : [];
	var activeKey = selectable.length ? selectable[ 0 ].key : ( data.tiers[ 0 ] && data.tiers[ 0 ].key );

	function tierByKey( key ) {
		for ( var i = 0; i < data.tiers.length; i++ ) {
			if ( data.tiers[ i ].key === key ) {
				return data.tiers[ i ];
			}
		}
		return null;
	}

	var els = {};

	function buildRunsCta() {
		if ( ! data.isLoggedIn || ! data.runsTotal || data.runsTotal < 1 || ! data.playUrl ) {
			return null;
		}

		var link = document.createElement( 'a' );
		link.className = 'saw-runs-cta';
		link.href = data.playUrl;

		var count = document.createElement( 'span' );
		count.className = 'saw-runs-cta__count';
		count.setAttribute( 'aria-hidden', 'true' );
		count.textContent = String( data.runsTotal );

		var copy = document.createElement( 'span' );
		copy.className = 'saw-runs-cta__copy';

		var runsLabel = document.createElement( 'span' );
		runsLabel.className = 'saw-runs-cta__runs';
		runsLabel.textContent = data.runsTotal === 1
			? t( 'runToPlay', '1 run to play' )
			: sprintfN( t( 'runsToPlay', '%d runs to play' ), data.runsTotal );

		var action = document.createElement( 'span' );
		action.className = 'saw-runs-cta__action';
		action.textContent = t( 'playQuiz', 'Play quiz' );

		link.setAttribute( 'aria-label', runsLabel.textContent + ' — ' + action.textContent );

		copy.appendChild( runsLabel );
		copy.appendChild( action );
		link.appendChild( count );
		link.appendChild( copy );

		return link;
	}

	function buildWidget() {
		var box = document.createElement( 'div' );
		box.className = 'saw-tierbox';

		var runsCta = buildRunsCta();
		if ( runsCta ) {
			box.appendChild( runsCta );
		}

		var title = document.createElement( 'div' );
		title.className = 'saw-tierbox__title';
		title.textContent = t( 'chooseTier', 'Choose your entry tier' );
		box.appendChild( title );

		// Tabs.
		var tabs = document.createElement( 'div' );
		tabs.className = 'saw-tier-tabs';
		data.tiers.forEach( function ( tier ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'saw-tier-tab' + ( tier.key === activeKey ? ' is-active' : '' );
			btn.setAttribute( 'data-tier', tier.key );
			if ( ! tier.offered ) {
				btn.disabled = true;
			}
			var lbl = document.createElement( 'span' );
			lbl.className = 'saw-tier-tab__label';
			lbl.textContent = tier.label;
			var pr = document.createElement( 'span' );
			pr.className = 'saw-tier-tab__price';
			pr.innerHTML = tier.priceHtml;
			btn.appendChild( lbl );
			btn.appendChild( pr );
			btn.addEventListener( 'click', function () {
				if ( tier.offered ) {
					setActive( tier.key );
				}
			} );
			tabs.appendChild( btn );
		} );
		box.appendChild( tabs );

		// Price + note.
		var meta = document.createElement( 'div' );
		meta.className = 'saw-tier-meta';
		els.price = document.createElement( 'span' );
		els.price.className = 'saw-tier-price';
		els.note = document.createElement( 'span' );
		els.note.className = 'saw-tier-note';
		meta.appendChild( els.price );
		meta.appendChild( els.note );
		box.appendChild( meta );

		// Quantity (= runs).
		var qty = document.createElement( 'div' );
		qty.className = 'saw-qty';
		var qlabel = document.createElement( 'span' );
		qlabel.className = 'saw-qty__label';
		qlabel.textContent = t( 'runs', 'runs' ).replace( /^\w/, function ( c ) { return c.toUpperCase(); } );
		var minus = document.createElement( 'button' );
		minus.type = 'button';
		minus.className = 'saw-qty__btn';
		minus.textContent = '−';
		els.qty = document.createElement( 'input' );
		els.qty.className = 'saw-qty__input';
		els.qty.type = 'number';
		els.qty.min = '1';
		els.qty.value = '1';
		var plus = document.createElement( 'button' );
		plus.type = 'button';
		plus.className = 'saw-qty__btn';
		plus.textContent = '+';
		minus.addEventListener( 'click', function () { stepQty( -1 ); } );
		plus.addEventListener( 'click', function () { stepQty( 1 ); } );
		els.qty.addEventListener( 'input', render );
		qty.appendChild( qlabel );
		qty.appendChild( minus );
		qty.appendChild( els.qty );
		qty.appendChild( plus );
		box.appendChild( qty );

		// Total.
		els.total = document.createElement( 'div' );
		els.total.className = 'saw-tier-total';
		box.appendChild( els.total );

		// Buy button (anchor: robust GET add-to-cart).
		els.buy = document.createElement( 'a' );
		els.buy.className = 'saw-buy';
		els.buy.href = '#';
		els.buy.addEventListener( 'click', onBuy );
		box.appendChild( els.buy );

		return box;
	}

	function stepQty( delta ) {
		var v = parseInt( els.qty.value, 10 );
		if ( isNaN( v ) ) { v = 1; }
		v = Math.max( 1, v + delta );
		els.qty.value = v;
		render();
	}

	function getQty() {
		var v = parseInt( els.qty.value, 10 );
		return isNaN( v ) || v < 1 ? 1 : v;
	}

	function setActive( key ) {
		activeKey = key;
		var tabs = els.box.querySelectorAll( '.saw-tier-tab' );
		tabs.forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-tier' ) === key );
		} );
		render();
	}

	function render() {
		var tier = tierByKey( activeKey );
		if ( ! tier ) { return; }
		var qty = getQty();

		els.price.innerHTML = tier.priceHtml + ' <small>' + t( 'perRun', '/ run' ) + '</small>';
		els.note.textContent = tier.note || '';

		// Total = price * qty, using the currency HTML numerals if possible.
		var totalVal = ( tier.price * qty );
		els.total.innerHTML = t( 'total', 'Total' ) + ': <strong>' + formatMoney( totalVal, tier.priceHtml ) + '</strong>';

		var canBuy = data.poolOpen && tier.offered;
		if ( ! data.isLoggedIn ) {
			els.buy.textContent = t( 'loginToBuy', 'Log in to enter' );
			els.buy.href = data.loginUrl;
			els.buy.classList.remove( 'is-disabled' );
			els.buy.removeAttribute( 'data-buy' );
		} else if ( ! canBuy ) {
			els.buy.textContent = data.poolOpen ? ( tier.note || t( 'addToCart', 'Add to cart' ) ) : t( 'closed', 'Competition closed' );
			els.buy.href = '#';
			els.buy.classList.add( 'is-disabled' );
			els.buy.removeAttribute( 'data-buy' );
		} else {
			els.buy.textContent = t( 'addToCart', 'Add to cart' );
			els.buy.href = buildAddToCartUrl( tier.key, qty );
			els.buy.classList.remove( 'is-disabled' );
			els.buy.setAttribute( 'data-buy', '1' );
		}
	}

	// Reuse the tier's wc_price() HTML as a formatting template, swapping the
	// numeric part so currency symbol/placement stay correct. Replace the *last*
	// number in the string — wc_price() may encode "$" as &#36; and a naive
	// first-match regex would corrupt that entity (e.g. &#85.00; → "U.00;…").
	function formatMoney( value, templateHtml ) {
		var num = value.toFixed( 2 );
		if ( ! templateHtml ) {
			return num;
		}
		var matches = templateHtml.match( /[\d.,]+/g );
		if ( ! matches || ! matches.length ) {
			return num;
		}
		var last = matches[ matches.length - 1 ];
		var idx  = templateHtml.lastIndexOf( last );
		return templateHtml.slice( 0, idx ) + num + templateHtml.slice( idx + last.length );
	}

	function buildAddToCartUrl( tierKey, qty ) {
		var url;
		try {
			url = new URL( data.addToCartBase, window.location.origin );
		} catch ( e ) {
			url = new URL( window.location.origin );
		}
		url.searchParams.set( 'add-to-cart', data.competitionId );
		url.searchParams.set( 'quantity', qty );
		url.searchParams.set( 'saw_tier', tierKey );
		return url.toString();
	}

	// The theme's global Alpine toast store, when present.
	function toastStore() {
		return ( window.Alpine && typeof window.Alpine.store === 'function' )
			? window.Alpine.store( 'toast' )
			: null;
	}

	// Apply WooCommerce cart fragments (mini-cart) returned by the AJAX handler.
	function applyFragments( fragments ) {
		if ( ! fragments ) {
			return;
		}
		Object.keys( fragments ).forEach( function ( selector ) {
			var nodes = document.querySelectorAll( selector );
			nodes.forEach( function ( node ) {
				node.outerHTML = fragments[ selector ];
			} );
		} );
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wc_fragments_refreshed' );
			window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
		}
	}

	function setBuyBusy( busy ) {
		if ( busy ) {
			els.buy.classList.add( 'is-busy' );
			els.buy.setAttribute( 'aria-busy', 'true' );
			els.buy.textContent = t( 'adding', 'Adding…' );
		} else {
			els.buy.classList.remove( 'is-busy' );
			els.buy.removeAttribute( 'aria-busy' );
			render(); // restores the correct label/href for the active tier.
		}
	}

	function ajaxAddToCart( tierKey, qty ) {
		var body = new FormData();
		body.append( 'action', 'woocommerce_ajax_add_to_cart' );
		body.append( 'product_id', data.competitionId );
		body.append( 'quantity', qty );
		body.append( 'saw_tier', tierKey );

		setBuyBusy( true );

		fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( result ) {
				var toast = toastStore();
				if ( result && result.error === false ) {
					if ( toast ) {
						toast.success( result.message || t( 'added', 'Added to cart.' ), {
							label: t( 'viewCart', 'View cart' ),
							callback: function () { window.location.href = data.cartUrl; },
						} );
					}
					applyFragments( result.fragments );
					// Match the theme's AJAX add-to-cart so cart-sound.js chimes.
					document.dispatchEvent( new CustomEvent( 'nera:cart:updated', {
						detail: { productId: data.competitionId },
					} ) );
				} else if ( toast ) {
					toast.error( ( result && result.message ) || t( 'addFailed', 'Could not add to cart. Please try again.' ) );
				}
				setBuyBusy( false );
			} )
			.catch( function () {
				var toast = toastStore();
				if ( toast ) {
					toast.error( t( 'addFailed', 'Could not add to cart. Please try again.' ) );
				}
				setBuyBusy( false );
			} );
	}

	function onBuy( e ) {
		if ( ! data.isLoggedIn ) {
			return; // anchor navigates to loginUrl.
		}
		if ( els.buy.classList.contains( 'is-disabled' ) || ! els.buy.getAttribute( 'data-buy' ) ) {
			e.preventDefault();
			return;
		}
		if ( els.buy.classList.contains( 'is-busy' ) ) {
			e.preventDefault();
			return; // request already in flight.
		}
		// No Alpine toast store on the page (unexpected theme): let the anchor
		// navigate to the GET add-to-cart URL as a graceful fallback.
		if ( ! toastStore() || ! data.ajaxUrl ) {
			return;
		}
		e.preventDefault();
		ajaxAddToCart( tierByKey( activeKey ).key, getQty() );
	}

	function insertWidget() {
		var form = document.querySelector( 'form.cart' );
		var priceBlock = null;
		var anchorBlock = null;

		if ( form ) {
			anchorBlock = form.closest( 'div.px-6' ) || form.parentElement;
		}
		// Price/quantity block: previous sibling holding the TicketPrice atom.
		if ( anchorBlock ) {
			var prev = anchorBlock.previousElementSibling;
			while ( prev ) {
				if ( prev.querySelector && prev.querySelector( '.flex.items-center.justify-between' ) ) {
					priceBlock = prev;
					break;
				}
				prev = prev.previousElementSibling;
			}
		}

		var reference = priceBlock || anchorBlock;
		if ( ! reference || ! reference.parentNode ) {
			return false;
		}

		els.box = buildWidget();
		reference.parentNode.insertBefore( els.box, reference );
		render();

		// Explicitly hide the native price/quantity block + the add-to-cart form
		// block. This does NOT depend on CSS :has() support or product.css loading,
		// so the swap is reliable across browsers.
		if ( priceBlock ) {
			priceBlock.style.display = 'none';
		}
		if ( anchorBlock && anchorBlock !== priceBlock ) {
			anchorBlock.style.display = 'none';
		}
		// Also add the body class (belt-and-braces for the stylesheet path).
		document.body.classList.add( 'saw-tiers-ready' );
		return true;
	}

	function boot() {
		var inserted = insertWidget();
		if ( window.console ) {
			console.info( '[SAW] product-tiers boot — widget inserted:', inserted, '| tiers:', data.tiers.length, '| runs:', data.runsTotal || 0, '| version:', ( data.version || '?' ) );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
