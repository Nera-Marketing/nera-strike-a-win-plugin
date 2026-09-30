// Thin wrapper over the Strikeawin REST API. The server is authoritative for
// scoring and the per-slot deadline; this module never decides correctness.

const cfg = window.NeraSAW || {};

// A quiz request must never hang forever: a request that never returns is what
// froze the screen (timer stopped, no results). Abort after this long and surface
// a 'saw_stall' error so the UI can offer a retry and the server can log it.
const REQUEST_TIMEOUT_MS = 20000;

async function request( path, method = 'GET', body, timeoutMs = REQUEST_TIMEOUT_MS ) {
	const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
	const timer = controller
		? setTimeout( () => controller.abort(), timeoutMs )
		: null;

	let res;
	try {
		res = await fetch( cfg.root + path, {
			method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
			signal: controller ? controller.signal : undefined,
		} );
	} catch ( e ) {
		if ( timer ) { clearTimeout( timer ); }
		// AbortError = our timeout fired; anything else = network failure.
		const stalled = e && e.name === 'AbortError';
		const err = new Error(
			stalled
				? 'The server did not respond in time.'
				: 'Could not reach the server. Check your connection.'
		);
		err.code = stalled ? 'saw_stall' : 'saw_network';
		err.stalled = stalled;
		throw err;
	}
	if ( timer ) { clearTimeout( timer ); }

	const data = await res.json().catch( () => ( {} ) );
	if ( ! res.ok ) {
		const err = new Error( ( data && data.message ) || 'Request failed.' );
		err.code = ( data && data.code ) || '';
		err.status = res.status;
		throw err;
	}
	return data;
}

export const api = {
	startRun( competitionId, tier, startToken, language ) {
		return request( '/run/start', 'POST', { competition_id: competitionId, tier, start_token: startToken, language: language || '' } );
	},
	getSlot( runId, slotNo ) {
		return request( `/run/${ runId }/slot/${ slotNo }` );
	},
	answer( runId, slotNo, option ) {
		return request( `/run/${ runId }/answer`, 'POST', { slot: slotNo, option } );
	},
	// Minting can be heavy (creates every earned LFW ticket + confirm + email),
	// so allow a longer window than the interactive quiz steps before treating it
	// as a stall — the results screen shows a spinner while it runs.
	completeRun( runId ) {
		return request( `/run/${ runId }/complete`, 'POST', {}, 60000 );
	},
	// Awaited abandon — used on the leave paths we control (Back / in-app link /
	// F5). Also mints inline, so give it the longer window too.
	abandonRun( runId ) {
		return request( `/run/${ runId }/abandon`, 'POST', {}, 60000 );
	},
	// The client is still here. Called every few seconds while a run is on screen;
	// the server derives "interrupted" from these going quiet (ADR 0030).
	heartbeat( runId ) {
		return request( `/run/${ runId }/heartbeat`, 'POST', {}, 8000 );
	},
	// Pick an interrupted run back up.
	resumeRun( runId ) {
		return request( `/run/${ runId }/resume`, 'POST', {} );
	},
	// Best-effort client diagnostic report (error or stalled request). Fire-and-
	// forget: never throws, never blocks gameplay. Feeds the server Quiz Log.
	logClient( payload ) {
		try {
			fetch( cfg.root + '/log', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce,
				},
				credentials: 'same-origin',
				keepalive: true,
				body: JSON.stringify( payload || {} ),
			} ).catch( () => {} );
		} catch ( e ) {}
	},
	// One last ping as the page goes away, so the server knows when the run was
	// interrupted. Same transport constraints as abandonBeacon().
	heartbeatBeacon( runId ) {
		if ( typeof navigator === 'undefined' || typeof navigator.sendBeacon !== 'function' ) {
			return false;
		}
		const url = `${ cfg.root }/run/${ runId }/heartbeat?_wpnonce=${ encodeURIComponent( cfg.nonce ) }`;
		try {
			return navigator.sendBeacon( url, new Blob( [ '{}' ], { type: 'application/json' } ) );
		} catch ( e ) {
			return false;
		}
	},
	// Best-effort abandon for page unload (tab close / address bar / toolbar
	// reload), where async fetches are unreliable. sendBeacon can't set headers,
	// so the REST cookie-nonce travels as a query param. Returns true if queued.
	abandonBeacon( runId ) {
		if ( typeof navigator === 'undefined' || typeof navigator.sendBeacon !== 'function' ) {
			return false;
		}
		const url = `${ cfg.root }/run/${ runId }/abandon?_wpnonce=${ encodeURIComponent( cfg.nonce ) }`;
		try {
			// Empty JSON body; auth is the same-origin session cookie + _wpnonce.
			const blob = new Blob( [ '{}' ], { type: 'application/json' } );
			return navigator.sendBeacon( url, blob );
		} catch ( e ) {
			return false;
		}
	},
};

// localStorage breadcrumb: ONLY { run_id } per competition. No questions,
// answers, scores, or timings ever live here (server-authoritative).
export const breadcrumb = {
	key( competitionId ) {
		return `saw_run_${ competitionId }`;
	},
	save( competitionId, runId ) {
		try {
			localStorage.setItem(
				this.key( competitionId ),
				JSON.stringify( { run_id: runId } )
			);
		} catch ( e ) {}
	},
	read( competitionId ) {
		try {
			return JSON.parse( localStorage.getItem( this.key( competitionId ) ) || 'null' );
		} catch ( e ) {
			return null;
		}
	},
	clear( competitionId ) {
		try {
			localStorage.removeItem( this.key( competitionId ) );
		} catch ( e ) {}
	},
};
