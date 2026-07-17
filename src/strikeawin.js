import { createApp } from 'vue';
import App from './App.vue';

// The quiz is NEVER auto-mounted from URL state. It mounts only when the Overview
// screen's Start button is clicked, which calls window.NeraSAWLaunch() with a
// server-issued start token (see class-frontend.php). This guarantees a run is
// consumed only by a deliberate Start click, never by opening a URL (ADR 0007).
function launch( opts ) {
	opts = opts || {};
	const el = opts.mountEl || document.getElementById( 'saw-quiz' );
	if ( ! el || el.getAttribute( 'data-saw-mounted' ) === '1' ) {
		return;
	}
	el.setAttribute( 'data-saw-mounted', '1' );
	el.hidden = false;
	el.style.display = '';
	createApp( App, {
		competitionId: parseInt( opts.competitionId, 10 ) || 0,
		tier: opts.tier || '',
		startToken: opts.token || '',
	} ).mount( el );
}

window.NeraSAWLaunch = launch;
