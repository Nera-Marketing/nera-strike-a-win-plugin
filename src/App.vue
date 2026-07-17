<script setup>
import { ref, reactive, onMounted, onUnmounted, computed, nextTick, watch } from 'vue';
import { api, breadcrumb } from './api.js';

const props = defineProps( {
	competitionId: { type: Number, required: true },
	tier: { type: String, default: '' },
	startToken: { type: String, default: '' },
} );

const strings = ( window.NeraSAW && window.NeraSAW.strings ) || {};
const t = ( key, fallback ) => strings[ key ] || fallback;

function timerWarnThreshold() {
	const raw = window.NeraSAW && window.NeraSAW.timerWarnSeconds;
	const n = parseInt( raw, 10 );
	return Number.isFinite( n ) && n > 0 ? n : 3;
}

function isAnswerFeedbackEnabled() {
	const el = document.getElementById( 'saw-quiz' );
	const fromDom = el ? el.getAttribute( 'data-show-feedback' ) : null;
	const raw = fromDom !== null && fromDom !== '' ? fromDom : ( window.NeraSAW && window.NeraSAW.showAnswerFeedback );
	return raw === true || raw === 1 || raw === '1';
}

const FEEDBACK_MS = 1100;

const phase = ref( 'loading' ); // loading | question | feedback | end | error
const errorMsg = ref( '' );
const canRetry = ref( false ); // error screen: offer a "Try again" for the failed step.

const runId = ref( 0 );
const competitionId = ref( 0 );
const totalSlots = ref( 0 );
const spinsSoFar = ref( 0 );
const spinsFinal = ref( null );

const selectedAnswer = ref( null );
const ticketsLoading = ref( false );
const ticketsError = ref( '' );
const ticketNumbers = ref( [] );
const runsRemainingTotal = ref( null );
const runsRemainingTier = ref( null );
const tierLabel = ref( '' );

const slot = reactive( {
	slot_no: 0,
	level: '',
	question: '',
	answers: [], // [ { index, text } ]
	timer_seconds: 0,
	seconds_left: 0,
} );

const lastResult = reactive( { correct: false, timed_out: false, spins_awarded: 0 } );

let ticker = null;
let locked = false;
let navGuardActive = false;
let navListenersBound = false;
let historyTrapDepth = 0;
let quizSessionActive = false;
let leaveBypass = false;
let leaveResolver = null;
let leavePromptInFlight = false;
let leavePromptPromise = null;
let abandonSent = false;
let retryAction = null; // closure that re-runs the last failed step (set before each risky call).

// Finalize the run because the player is leaving mid-quiz (ADR 0010). Awaited
// form for the leave paths we control; single-flight so a follow-on pagehide
// beacon can't double-submit (the server is idempotent regardless).
async function abandonOnce() {
	if ( abandonSent || ! runId.value ) {
		return;
	}
	abandonSent = true;
	try {
		await api.abandonRun( runId.value );
	} catch ( e ) {
		// Best-effort: the finalize_stale() cron is the guaranteed backstop.
	}
}

// Page is genuinely unloading (tab close / address bar / confirmed toolbar
// reload). Fire a best-effort beacon; pagehide (not beforeunload) so a cancelled
// native prompt never finalizes a run the player stayed on.
function onPageHide() {
	if ( ! shouldBlockLeave() || abandonSent || ! runId.value ) {
		return;
	}
	abandonSent = true;
	api.abandonBeacon( runId.value );
}

function requestLeavePrompt( proceed ) {
	if ( leavePromptInFlight || leaveDialogOpen.value ) {
		return leavePromptPromise || Promise.resolve( false );
	}
	leavePromptPromise = promptLeaveNavigation( proceed ).finally( () => {
		leavePromptPromise = null;
	} );
	return leavePromptPromise;
}

function getNavApi() {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const nav = window.navigation;
	return nav && typeof nav.addEventListener === 'function' ? nav : null;
}

const leaveDialogOpen = ref( false );
const stayButtonRef = ref( null );

const timerPct = computed( () => {
	const total = slot.timer_seconds || 1;
	return Math.max( 0, Math.min( 100, ( slot.seconds_left / total ) * 100 ) );
} );

const timerUrgent = computed( () => {
	if ( phase.value !== 'question' ) {
		return false;
	}
	const left = slot.seconds_left;
	return left > 0 && left <= timerWarnThreshold();
} );

const canSubmit = computed( () => selectedAnswer.value !== null && ! locked );

const finalSpins = computed( () =>
	spinsFinal.value !== null && spinsFinal.value !== undefined ? spinsFinal.value : spinsSoFar.value
);

const runsRemainingLine = computed( () => {
	if ( runsRemainingTotal.value === null ) {
		return '';
	}
	const total = runsRemainingTotal.value;
	const base = t( 'runsRemaining', '%d runs left for this competition' ).replace( '%d', String( total ) );
	if ( runsRemainingTier.value !== null && tierLabel.value ) {
		const tierLine = t( 'runsRemainingTier', '%d runs left on %s' )
			.replace( '%d', String( runsRemainingTier.value ) )
			.replace( '%s', tierLabel.value );
		return `${ base } · ${ tierLine }`;
	}
	return base;
} );

function isPlaying() {
	return quizSessionActive && ( phase.value === 'loading' || phase.value === 'question' || phase.value === 'feedback' );
}

function shouldBlockLeave() {
	return isPlaying() && ! leaveBypass;
}

function clearTicker() {
	if ( ticker ) {
		clearInterval( ticker );
		ticker = null;
	}
}

function fail( e ) {
	clearTicker();
	const prevPhase = phase.value;
	const stalled = !! ( e && ( e.stalled || e.code === 'saw_stall' ) );

	// Report to the server Quiz Log (best-effort) so a client-side lock — the
	// frozen screen with a stopped countdown — leaves a trace to troubleshoot.
	api.logClient( {
		event: stalled ? 'client_stall' : 'client_error',
		run_id: runId.value || 0,
		slot: slot.slot_no || 0,
		phase: prevPhase,
		status: ( e && e.status ) || 0,
		path: ( e && e.code ) || '',
		message: e && e.message ? e.message : String( e ),
	} );

	// Preserve the run: do NOT abandon on error. The run stays active so an admin
	// can investigate and restore it; the player is told to contact support.
	quizSessionActive = false;
	releaseNavigationGuard();
	errorMsg.value = e && e.message ? e.message : String( e );
	canRetry.value = !! retryAction;
	phase.value = 'error';
}

// Re-run the step that failed (transient blip / stall). Re-arms play + the leave
// guard that fail() released, then retries the tracked action.
function retry() {
	if ( ! retryAction ) {
		return;
	}
	locked = false;
	errorMsg.value = '';
	canRetry.value = false;
	quizSessionActive = true;
	setupNavigationGuards();
	armNavigationGuard();
	phase.value = 'loading';
	const action = retryAction;
	action();
}

function setupNavigationGuards() {
	if ( navListenersBound || typeof window === 'undefined' ) {
		return;
	}
	navListenersBound = true;
	// Custom card: Back/forward (popstate + history trap), keyboard reload
	// (keydown F5/Ctrl-R), and in-app links (click). Native browser prompt for the
	// cases the browser forbids custom UI on — toolbar reload, address-bar
	// navigation, tab close — via beforeunload. (ADR-style rationale in CONTEXT.)
	window.addEventListener( 'popstate', onPopState );
	window.addEventListener( 'keydown', onKeyDown, true );
	window.addEventListener( 'beforeunload', onBeforeUnload );
	window.addEventListener( 'pagehide', onPageHide );
	document.addEventListener( 'click', onDocumentClick, true );
}

function armHistoryTrap() {
	if ( historyTrapDepth > 0 || typeof window === 'undefined' ) {
		return;
	}
	history.pushState( { sawQuiz: 1 }, '', window.location.href );
	history.pushState( { sawQuiz: 2 }, '', window.location.href );
	historyTrapDepth = 2;
}

function disarmHistoryTrap() {
	historyTrapDepth = 0;
}

function armNavigationGuard() {
	if ( navGuardActive || typeof window === 'undefined' ) {
		return;
	}
	navGuardActive = true;
	leaveBypass = false;
	armHistoryTrap();
}

function teardownNavigationGuards() {
	if ( ! navListenersBound || typeof window === 'undefined' ) {
		return;
	}
	navListenersBound = false;
	window.removeEventListener( 'popstate', onPopState );
	window.removeEventListener( 'keydown', onKeyDown, true );
		window.removeEventListener( 'beforeunload', onBeforeUnload );
	window.removeEventListener( 'pagehide', onPageHide );
	document.removeEventListener( 'click', onDocumentClick, true );
}

function releaseNavigationGuard() {
	navGuardActive = false;
	disarmHistoryTrap();
	teardownNavigationGuards();
	closeLeaveDialog( false );
}

function shouldGuardNavigation( event ) {
	const dest = event.destination;
	if ( ! dest ) {
		return false;
	}
	const navType = event.navigationType || '';
	if ( navType === 'reload' || navType === 'traverse' ) {
		return true;
	}
	try {
		const destHref = new URL( dest.url, window.location.href ).href;
		const here = window.location.href;
		if ( destHref === here ) {
			return navType !== 'replace';
		}
		return destHref !== here;
	} catch ( err ) {
		return false;
	}
}

async function promptLeaveNavigation( proceed ) {
	if ( leavePromptInFlight || leaveDialogOpen.value ) {
		return false;
	}
	leavePromptInFlight = true;
	try {
		const leave = await openLeaveDialog();
		if ( ! leave ) {
			return false;
		}
		await proceedLeave( proceed );
		return true;
	} finally {
		leavePromptInFlight = false;
	}
}

function onNavigate( event ) {
	if ( ! shouldBlockLeave() || ! shouldGuardNavigation( event ) ) {
		return;
	}

	const navType = event.navigationType || '';
	// Back/forward: block the browser default and let popstate show our dialog.
	if ( navType === 'traverse' ) {
		event.preventDefault();
		return;
	}

	event.preventDefault();
	const targetUrl = event.destination?.url || window.location.href;
	const isReload = navType === 'reload';

	const confirmLeave = async () => {
		const leave = await openLeaveDialog();
		if ( ! leave ) {
			return false;
		}
		leaveBypass = true;
		quizSessionActive = false;
		releaseNavigationGuard();
		await abandonOnce();
		return true;
	};

	if ( event.canIntercept && typeof event.intercept === 'function' ) {
		event.intercept( {
			async handler() {
				const leave = await confirmLeave();
				if ( ! leave ) {
					throw new DOMException( 'Navigation aborted', 'AbortError' );
				}
				if ( ! isReload && navType !== 'traverse' ) {
					window.location.assign( targetUrl );
				}
			},
		} );
		return;
	}

	requestLeavePrompt( async () => {
		if ( isReload ) {
			window.location.reload();
			return;
		}
		window.location.assign( targetUrl );
	} );
}

function openLeaveDialog() {
	if ( leaveDialogOpen.value ) {
		return new Promise( ( resolve ) => {
			const prev = leaveResolver;
			leaveResolver = ( leave ) => {
				if ( prev ) {
					prev( leave );
				}
				resolve( leave );
			};
		} );
	}
	leaveDialogOpen.value = true;
	nextTick( () => {
		stayButtonRef.value?.focus();
	} );
	return new Promise( ( resolve ) => {
		leaveResolver = resolve;
	} );
}

function closeLeaveDialog( leave ) {
	leaveDialogOpen.value = false;
	if ( leaveResolver ) {
		leaveResolver( leave );
		leaveResolver = null;
	}
}

function onLeaveStay() {
	closeLeaveDialog( false );
}

function onLeaveConfirm() {
	closeLeaveDialog( true );
}

function onLeaveDialogKeydown( e ) {
	if ( e.key === 'Escape' ) {
		e.preventDefault();
		onLeaveStay();
	}
}

async function proceedLeave( action ) {
	leaveBypass = true;
	quizSessionActive = false;
	releaseNavigationGuard();
	await abandonOnce();
	await action();
}

function onPopState() {
	if ( ! shouldBlockLeave() ) {
		return;
	}
	// Stay on the page synchronously; the custom dialog replaces the native prompt.
	history.pushState( { sawQuiz: 2 }, '', window.location.href );
	requestLeavePrompt( () => {
		const steps = historyTrapDepth > 0 ? historyTrapDepth + 1 : 1;
		leaveBypass = true;
		quizSessionActive = false;
		releaseNavigationGuard();
		history.go( -steps );
	} );
}

function onKeyDown( e ) {
	if ( ! shouldBlockLeave() || leaveDialogOpen.value ) {
		return;
	}
	const key = e.key.toLowerCase();
	const isReload = key === 'f5' || ( ( e.ctrlKey || e.metaKey ) && key === 'r' );
	if ( ! isReload ) {
		return;
	}
	e.preventDefault();
	e.stopPropagation();
	requestLeavePrompt( () => {
		window.location.reload();
	} );
}

function onBeforeUnload( e ) {
	if ( ! shouldBlockLeave() ) {
		return;
	}
	// Native browser prompt for the cases the browser forbids custom UI on:
	// toolbar reload, address-bar navigation, and tab/window close. The wording
	// is controlled by the browser; our custom card handles Back / links / F5.
	e.preventDefault();
	e.returnValue = '';
	return '';
}

function onDocumentClick( e ) {
	if ( ! shouldBlockLeave() || leaveDialogOpen.value ) {
		return;
	}
	const anchor = e.target.closest( 'a[href]' );
	if ( ! anchor || anchor.target === '_blank' || anchor.hasAttribute( 'download' ) ) {
		return;
	}
	const raw = anchor.getAttribute( 'href' );
	if ( ! raw || raw.startsWith( '#' ) || raw.startsWith( 'javascript:' ) ) {
		return;
	}
	let url;
	try {
		url = new URL( anchor.href, window.location.href );
	} catch ( err ) {
		return;
	}
	if ( url.origin !== window.location.origin || url.href === window.location.href ) {
		return;
	}
	e.preventDefault();
	e.stopPropagation();
	requestLeavePrompt( () => {
		window.location.assign( url.href );
	} );
}

watch( leaveDialogOpen, ( open ) => {
	if ( typeof document === 'undefined' ) {
		return;
	}
	document.body.classList.toggle( 'saw-quiz-dialog-open', open );
} );

function applyState( state ) {
	runId.value = state.run_id || runId.value;
	competitionId.value = state.competition_id || competitionId.value;
	totalSlots.value = state.total_slots || totalSlots.value;
	spinsSoFar.value = state.spins_so_far || 0;
	if ( state.spins_final !== null && state.spins_final !== undefined ) {
		spinsFinal.value = state.spins_final;
	}
}

async function start() {
	retryAction = start;
	try {
		const state = await api.startRun( props.competitionId, props.tier, props.startToken );
		applyState( state );
		breadcrumb.save( props.competitionId, runId.value );
		if ( state.status === 'finalized' ) {
			quizSessionActive = false;
			return toEnd();
		}
		quizSessionActive = true;
		armNavigationGuard();
		await loadSlot( state.next_slot || 1 );
	} catch ( e ) {
		// Expired/invalid Start token: the play session lapsed. Show a friendly
		// notice and reload the Overview to issue a fresh token (no run consumed).
		if ( e && e.code === 'saw_bad_token' ) {
			errorMsg.value = t( 'sessionExpired', 'Your play session expired — refreshing…' );
			phase.value = 'error';
			if ( typeof window !== 'undefined' ) {
				window.setTimeout( function () { window.location.reload(); }, 1600 );
			}
			return;
		}
		fail( e );
	}
}

async function loadSlot( slotNo ) {
	clearTicker();
	locked = false;
	selectedAnswer.value = null;
	retryAction = () => loadSlot( slotNo );
	try {
		const data = await api.getSlot( runId.value, slotNo );
		if ( data.status === 'finalized' ) {
			applyState( data );
			return toEnd();
		}
		Object.assign( slot, data );
		spinsSoFar.value = data.spins_so_far || 0;
		totalSlots.value = data.total_slots || totalSlots.value;

		if ( slot.seconds_left <= 0 ) {
			return submit( '' );
		}

		phase.value = 'question';
		startCountdown();
	} catch ( e ) {
		fail( e );
	}
}

function startCountdown() {
	clearTicker();
	ticker = setInterval( () => {
		slot.seconds_left -= 1;
		if ( slot.seconds_left <= 0 ) {
			clearTicker();
			if ( ! locked ) {
				submit( '' );
			}
		}
	}, 1000 );
}

function selectOption( index ) {
	if ( locked ) {
		return;
	}
	selectedAnswer.value = index;
}

function confirmSubmit() {
	if ( selectedAnswer.value === null || locked ) {
		return;
	}
	submit( selectedAnswer.value );
}

function advanceAfterAnswer( state ) {
	if ( state.status === 'finalized' || ! state.next_slot ) {
		toEnd();
	} else {
		loadSlot( state.next_slot );
	}
}

async function submit( option ) {
	if ( locked ) {
		return;
	}
	locked = true;
	clearTicker();
	retryAction = () => submit( option );
	try {
		const state = await api.answer( runId.value, slot.slot_no, option );
		applyState( state );
		if ( state.result ) {
			Object.assign( lastResult, state.result );
		}
		if ( isAnswerFeedbackEnabled() ) {
			phase.value = 'feedback';
			setTimeout( () => advanceAfterAnswer( state ), FEEDBACK_MS );
		} else {
			advanceAfterAnswer( state );
		}
	} catch ( e ) {
		fail( e );
	}
}

async function fetchRunComplete() {
	if ( ! runId.value ) {
		return;
	}
	ticketsLoading.value = true;
	ticketsError.value = '';
	try {
		const summary = await api.completeRun( runId.value );
		if ( summary.spins_final !== null && summary.spins_final !== undefined ) {
			spinsFinal.value = summary.spins_final;
		}
		ticketNumbers.value = Array.isArray( summary.ticket_numbers ) ? summary.ticket_numbers : [];
		runsRemainingTotal.value = summary.runs_remaining_total ?? 0;
		runsRemainingTier.value = summary.runs_remaining_tier ?? 0;
		tierLabel.value = summary.tier_label || summary.tier_key || '';
	} catch ( e ) {
		ticketsError.value = e && e.message ? e.message : t( 'ticketsMintError', 'We could not add your tickets right now.' );
	} finally {
		ticketsLoading.value = false;
	}
}

async function toEnd() {
	clearTicker();
	quizSessionActive = false;
	breadcrumb.clear( props.competitionId );
	releaseNavigationGuard();
	phase.value = 'end';
	await fetchRunComplete();
}

const competitionUrl = computed( () => ( competitionId.value ? `/?p=${ competitionId.value }` : '#' ) );
const playAgainUrl = computed( () => {
	if ( ! competitionId.value || typeof window === 'undefined' ) {
		return '#';
	}
	const params = new URLSearchParams( window.location.search );
	params.delete( 'saw_start' );
	params.set( 'saw_competition', String( competitionId.value ) );
	if ( props.tier ) {
		params.set( 'saw_tier', props.tier );
	}
	return `${ window.location.pathname }?${ params.toString() }`;
} );

onMounted( () => {
	setupNavigationGuards();
	start();
} );
onUnmounted( () => {
	clearTicker();
	releaseNavigationGuard();
	if ( typeof document !== 'undefined' ) {
		document.body.classList.remove( 'saw-quiz-dialog-open' );
	}
} );
</script>

<template>
	<div class="saw-app">
		<div v-if="phase === 'loading'" class="saw-loading">{{ t('loading', 'Loading your entry…') }}</div>

		<div v-else-if="phase === 'error'" class="saw-notice saw-error">
			<p class="saw-error__msg">{{ errorMsg }}</p>
			<template v-if="canRetry">
				<div class="saw-error__actions">
					<button type="button" class="saw-btn" @click="retry">{{ t('tryAgain', 'Try again') }}</button>
				</div>
				<p class="saw-error__help">
					{{ t('errorContactAdmin', 'If this keeps happening, please contact support and we will restore your run.') }}
					<span v-if="runId"> ({{ t('runRef', 'Run reference') }}: #{{ runId }})</span>
				</p>
			</template>
		</div>

		<div v-else-if="phase === 'question'" class="saw-play">
			<div class="saw-head">
				<span class="saw-progress">Question {{ slot.slot_no }} / {{ totalSlots }}</span>
				<span v-if="isAnswerFeedbackEnabled()" class="saw-tally">{{ spinsSoFar }} tickets</span>
			</div>
			<div class="saw-timerbar" :class="{ 'is-urgent': timerUrgent }">
				<div class="saw-timerfill" :style="{ width: timerPct + '%' }"></div>
			</div>
			<div class="saw-count" :class="{ 'is-urgent': timerUrgent }">{{ Math.max(0, slot.seconds_left) }}</div>
			<div class="saw-question">{{ slot.question }}</div>
			<p class="saw-hint">{{ t('selectAnswer', 'Choose an answer, then confirm to lock it in.') }}</p>
			<div class="saw-options">
				<button
					v-for="a in slot.answers"
					:key="a.index"
					type="button"
					class="saw-option"
					:class="{ 'is-selected': selectedAnswer === a.index }"
					:disabled="locked"
					:aria-pressed="selectedAnswer === a.index"
					@click="selectOption(a.index)"
				>
					<span class="saw-opttext">{{ a.text }}</span>
				</button>
			</div>
			<div class="saw-submitwrap">
				<button
					type="button"
					class="saw-btn-submit"
					:disabled="!canSubmit"
					@click="confirmSubmit"
				>
					{{ t('submitAnswer', 'Submit answer') }}
				</button>
			</div>
		</div>

		<div v-else-if="phase === 'feedback'" class="saw-feedbackwrap">
			<div class="saw-feedback" :class="lastResult.correct ? 'saw-correct' : 'saw-wrong'">
				<template v-if="lastResult.correct">+{{ lastResult.spins_awarded }} tickets!</template>
				<template v-else-if="lastResult.timed_out">Time up — 0 tickets</template>
				<template v-else>Wrong — 0 tickets</template>
			</div>
			<div class="saw-tally">{{ spinsSoFar }} tickets so far</div>
		</div>

		<div v-else-if="phase === 'end'" class="saw-end">
			<h3 class="saw-endtitle">Run complete</h3>
			<div class="saw-endspins">{{ finalSpins }} tickets earned</div>

			<div v-if="finalSpins > 0 && ticketsLoading" class="saw-minting" role="status" aria-live="polite">
				<span class="saw-spinner" aria-hidden="true"></span>
				<span>{{ t('mintingTickets', 'Adding your tickets to the draw…') }}</span>
			</div>

			<div v-else-if="ticketsError" class="saw-mint-error">
				<p>{{ ticketsError }}</p>
				<button type="button" class="saw-btn saw-btn-ghost" @click="fetchRunComplete">
					{{ t('retryTickets', 'Try again') }}
				</button>
			</div>

			<div v-else-if="ticketNumbers.length" class="saw-tickets">
				<div class="saw-tickets__head">
					<p class="saw-tickets__label">{{ t('yourTicketNumbers', 'Your ticket numbers') }}</p>
					<span class="saw-tickets__count">{{ ticketNumbers.length }}</span>
				</div>
				<div class="saw-tickets__panel" :class="{ 'is-scroll': ticketNumbers.length > 30 }">
					<ul class="saw-tickets__list">
						<li v-for="num in ticketNumbers" :key="num" class="saw-ticket">{{ num }}</li>
					</ul>
				</div>
			</div>

			<p v-if="runsRemainingLine" class="saw-runs-left">{{ runsRemainingLine }}</p>

			<p class="saw-endnote">
				<template v-if="finalSpins > 0 && !ticketsLoading && !ticketsError">
					Your tickets are in the draw — good luck! A confirmation email is on its way.
				</template>
				<template v-else-if="finalSpins > 0 && ticketsLoading">
					Your score is locked in. We are adding your ticket numbers to this order now.
				</template>
				<template v-else-if="finalSpins === 0">No tickets this time. Every run is a fresh test of skill.</template>
			</p>
			<div class="saw-endactions">
				<a class="saw-btn" :href="competitionUrl">{{ t('viewCompetition', 'View competition') }}</a>
				<a v-if="runsRemainingTotal > 0" class="saw-btn saw-btn-ghost" :href="playAgainUrl">{{ t('playAgain', 'Play again') }}</a>
			</div>
		</div>
	</div>

	<Teleport to="body">
		<div
			v-if="leaveDialogOpen"
			class="saw-leave"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="'saw-leave-title'"
			@keydown="onLeaveDialogKeydown"
		>
			<button type="button" class="saw-leave__backdrop" aria-label="Keep playing" @click="onLeaveStay"></button>
			<div class="saw-leave__panel">
				<div class="saw-leave__clock" :class="{ 'is-urgent': timerUrgent }" aria-hidden="true">
					<span class="saw-leave__clock-num">{{ Math.max(0, slot.seconds_left) }}</span>
					<span class="saw-leave__clock-label">sec left</span>
				</div>
				<h2 id="saw-leave-title" class="saw-leave__title">{{ t('leaveDialogTitle', 'Leave the quiz?') }}</h2>
				<p class="saw-leave__body">{{ t('leaveDialogBody', 'The countdown keeps running on the server while you are away. Any question you have not submitted scores zero.') }}</p>
				<div class="saw-leave__actions">
					<button
						type="button"
						class="saw-leave__stay"
						ref="stayButtonRef"
						@click="onLeaveStay"
					>
						{{ t('stayOnQuiz', 'Keep playing') }}
					</button>
					<button type="button" class="saw-leave__go" @click="onLeaveConfirm">
						{{ t('leaveQuiz', 'Leave anyway') }}
					</button>
				</div>
			</div>
		</div>
	</Teleport>
</template>

<style>
.saw-app {
	--saw-brand: var(--color-primary, #1313ec);
	--saw-accent: var(--color-accent, #fbbf24);
	--saw-surface: #e8edf5;
	--saw-text: var(--color-text-primary, #0d0d1b);
	--saw-text-muted: var(--color-text-secondary, #64748b);
	--saw-danger: #dc2626;
	--saw-danger-soft: #fee2e2;
	width: 100%;
	max-width: none;
	margin: 0;
	padding: 0;
	background: transparent;
	color: var(--saw-text);
	border: none;
	border-radius: 0;
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
.saw-head { display: flex; justify-content: space-between; align-items: center; font-size: 14px; margin-bottom: 12px; }
.saw-progress { font-weight: 600; color: var(--saw-text-muted); }
.saw-tally { font-weight: 700; color: var(--saw-brand); }
.saw-timerbar { height: 6px; background: var(--saw-surface); border-radius: 6px; overflow: hidden; margin-bottom: 6px; transition: background-color .25s ease; }
.saw-timerbar.is-urgent { background: var(--saw-danger-soft); }
.saw-timerfill { height: 100%; background: var(--saw-brand); transition: width 1s linear, background-color .25s ease; }
.saw-timerbar.is-urgent .saw-timerfill { background: var(--saw-danger); }
.saw-count { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; margin-bottom: 14px; color: var(--saw-brand); transition: color .25s ease; }
.saw-count.is-urgent { color: var(--saw-danger); }
.saw-question { font-size: 20px; line-height: 1.35; font-weight: 600; margin-bottom: 8px; }
.saw-hint { font-size: 14px; color: var(--saw-text-muted); margin: 0 0 14px; }
.saw-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 480px) { .saw-options { grid-template-columns: 1fr; } }
.saw-option {
	display: flex; align-items: center; gap: 12px; text-align: left; padding: 16px;
	background: #fff; border: 2px solid var(--saw-surface); border-radius: 12px;
	cursor: pointer; font-size: 16px; color: var(--saw-text);
	transition: border-color .12s, transform .06s, box-shadow .12s, background .12s;
}
.saw-option:hover:not(:disabled) { border-color: color-mix(in srgb, var(--saw-brand) 55%, var(--saw-surface)); box-shadow: 0 4px 14px -8px color-mix(in srgb, var(--saw-brand) 30%, transparent); }
.saw-option.is-selected { border-color: var(--saw-brand); background: color-mix(in srgb, var(--saw-brand) 6%, #fff); box-shadow: 0 0 0 1px var(--saw-brand); }
.saw-option:active:not(:disabled) { transform: scale(0.99); }
.saw-option:disabled { opacity: .6; cursor: default; }
.saw-submitwrap {
	margin-top: 14px;
	display: flex;
	justify-content: flex-end;
}
.saw-btn-submit {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 132px;
	padding: 9px 18px;
	border: none;
	border-radius: 10px;
	background: var(--saw-brand);
	color: #fff;
	font-size: 14px;
	font-weight: 700;
	line-height: 1.2;
	cursor: pointer;
	box-shadow: 0 6px 16px -10px color-mix(in srgb, var(--saw-brand) 50%, transparent);
	transition: background 0.15s ease, transform 0.12s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}
.saw-btn-submit:hover:not(:disabled) {
	background: color-mix(in srgb, var(--saw-brand) 92%, #000);
	transform: translateY(-1px);
	box-shadow: 0 8px 20px -10px color-mix(in srgb, var(--saw-brand) 55%, transparent);
}
.saw-btn-submit:active:not(:disabled) { transform: translateY(0); }
.saw-btn-submit:focus-visible {
	outline: 2px solid var(--saw-brand);
	outline-offset: 2px;
}
.saw-btn-submit:disabled { opacity: .42; cursor: not-allowed; transform: none; box-shadow: none; }
@media (max-width: 480px) {
	.saw-submitwrap { justify-content: stretch; }
	.saw-btn-submit { width: 100%; min-width: 0; }
}
.saw-feedback { text-align: center; font-size: 26px; font-weight: 800; padding: 28px 0 8px; }
.saw-correct { color: #16a34a; }
.saw-wrong { color: #dc2626; }
.saw-endtitle { margin: 0 0 8px; font-size: 1.5rem; }
.saw-end { text-align: center; }
.saw-endspins { font-size: 30px; font-weight: 800; color: var(--saw-brand); margin-bottom: 12px; }
.saw-minting {
	display: flex; align-items: center; gap: 12px; padding: 14px 16px; margin-bottom: 14px;
	background: color-mix(in srgb, var(--saw-brand) 6%, #fff); border-radius: 12px;
	color: var(--saw-text-muted); font-size: 15px;
}
.saw-spinner {
	width: 22px; height: 22px; flex-shrink: 0;
	border: 3px solid color-mix(in srgb, var(--saw-brand) 20%, transparent);
	border-top-color: var(--saw-brand); border-radius: 50%;
	animation: saw-spin .75s linear infinite;
}
@keyframes saw-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
	.saw-spinner { animation: none; border-top-color: var(--saw-brand); opacity: .7; }
}
.saw-mint-error { margin-bottom: 14px; color: #dc2626; }
.saw-mint-error p { margin: 0 0 10px; }
.saw-tickets {
	margin-bottom: 16px; text-align: left;
	border: 1px solid color-mix(in srgb, var(--saw-brand) 12%, #e8edf5);
	border-radius: 14px; background: color-mix(in srgb, var(--saw-brand) 3%, #fff);
	padding: 14px 16px;
}
.saw-tickets__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.saw-tickets__label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--saw-text-muted); margin: 0; }
.saw-tickets__count {
	font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums;
	padding: 4px 10px; border-radius: 999px;
	background: color-mix(in srgb, var(--saw-brand) 10%, #fff);
	color: var(--saw-brand);
}
.saw-tickets__panel.is-scroll {
	max-height: 220px; overflow-y: auto; padding-right: 4px;
	scrollbar-width: thin;
	scrollbar-color: color-mix(in srgb, var(--saw-brand) 30%, transparent) transparent;
}
.saw-tickets__list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(58px, 1fr));
	gap: 6px;
	list-style: none; margin: 0; padding: 0;
}
.saw-ticket {
	font-variant-numeric: tabular-nums; font-weight: 600; font-size: 13px;
	text-align: center; padding: 7px 6px; border-radius: 8px;
	background: var(--saw-brand); color: #fff;
	border: 1px solid color-mix(in srgb, var(--saw-brand) 80%, #000);
	box-shadow: 0 2px 6px -2px color-mix(in srgb, var(--saw-brand) 35%, transparent);
}
.saw-runs-left { font-weight: 600; color: var(--saw-brand); margin: 0 0 8px; }
.saw-endnote { color: var(--saw-text-muted); line-height: 1.5; margin-left: auto; margin-right: auto; max-width: 36rem; }
.saw-endactions { display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap; justify-content: center; }
.saw-btn {
	display: inline-block; padding: 0.75rem 1.5rem; background: var(--saw-brand); color: #fff;
	border-radius: 0.75rem; text-decoration: none; font-weight: 600;
	box-shadow: 0 12px 32px -12px color-mix(in srgb, var(--saw-brand) 45%, transparent);
	transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
a.saw-btn:hover, button.saw-btn:hover:not(:disabled) {
	color: #fff;
	text-decoration: none;
	background: color-mix(in srgb, var(--saw-brand) 90%, #000);
	transform: translateY(-1px);
}
.saw-btn-ghost { background: transparent; color: var(--saw-brand); border: 2px solid color-mix(in srgb, var(--saw-brand) 35%, transparent); box-shadow: none; }
a.saw-btn.saw-btn-ghost:hover, button.saw-btn.saw-btn-ghost:hover:not(:disabled) { color: var(--saw-brand); background: color-mix(in srgb, var(--saw-brand) 8%, #fff); }
.saw-notice { text-align: center; }
.saw-error { color: #dc2626; }
.saw-error__msg { margin: 0 0 14px; font-weight: 600; }
.saw-error__actions { display: flex; justify-content: center; margin-bottom: 12px; }
.saw-error__help { color: var(--saw-text-muted); font-size: 14px; margin: 0; }
.saw-loading { text-align: center; opacity: .7; color: var(--saw-text-muted); }
</style>

<style>
body.saw-quiz-dialog-open { overflow: hidden; }
.saw-leave {
	--saw-brand: var(--color-primary, #1313ec);
	--saw-text: var(--color-text-primary, #0d0d1b);
	--saw-text-muted: var(--color-text-secondary, #64748b);
	--saw-danger: #dc2626;
	--saw-danger-soft: #fee2e2;
	position: fixed; inset: 0; z-index: 100050;
	display: grid; place-items: center; padding: 20px;
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
.saw-leave__backdrop {
	position: absolute; inset: 0; margin: 0; padding: 0; border: 0;
	background: color-mix(in srgb, var(--saw-text) 48%, transparent);
	backdrop-filter: blur(8px);
	cursor: default;
}
.saw-leave__panel {
	position: relative; width: min(100%, 400px);
	background: #fff; border-radius: 20px; padding: 24px 24px 20px;
	box-shadow: 0 28px 72px -28px color-mix(in srgb, var(--saw-brand) 40%, transparent);
	border: 1px solid color-mix(in srgb, var(--saw-brand) 10%, #e8edf5);
	color: var(--saw-text);
}
.saw-leave__clock {
	display: flex; flex-direction: column; align-items: center; justify-content: center;
	width: 72px; height: 72px; margin: 0 auto 18px; border-radius: 50%;
	background: color-mix(in srgb, var(--saw-brand) 8%, #fff);
	border: 2px solid color-mix(in srgb, var(--saw-brand) 20%, #e8edf5);
	transition: background-color .25s ease, border-color .25s ease;
}
.saw-leave__clock.is-urgent {
	background: var(--saw-danger-soft);
	border-color: color-mix(in srgb, var(--saw-danger) 35%, #fff);
}
.saw-leave__clock-num {
	font-size: 26px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums;
	color: var(--saw-brand); transition: color .25s ease;
}
.saw-leave__clock.is-urgent .saw-leave__clock-num { color: var(--saw-danger); }
.saw-leave__clock-label {
	margin-top: 2px; font-size: 10px; font-weight: 700; letter-spacing: .06em;
	text-transform: uppercase; color: var(--saw-text-muted);
}
.saw-leave__title { margin: 0 0 10px; font-size: 1.25rem; line-height: 1.3; text-align: center; font-weight: 800; }
.saw-leave__body { margin: 0 0 22px; font-size: 15px; line-height: 1.55; color: var(--saw-text-muted); text-align: center; }
.saw-leave__actions { display: flex; flex-direction: column; gap: 10px; }
.saw-leave__stay {
	display: block; width: 100%; padding: 14px 20px; border: none; border-radius: 12px;
	background: var(--saw-brand); color: #fff; font-weight: 700; font-size: 16px;
	cursor: pointer; box-shadow: 0 10px 28px -12px color-mix(in srgb, var(--saw-brand) 50%, transparent);
	transition: background .15s ease, transform .15s ease;
}
.saw-leave__stay:hover { background: color-mix(in srgb, var(--saw-brand) 88%, #000); transform: translateY(-1px); }
.saw-leave__go {
	display: block; width: 100%; padding: 10px 12px; border: none; border-radius: 8px;
	background: transparent; color: var(--saw-text-muted); font-weight: 600; font-size: 14px;
	cursor: pointer; text-decoration: underline; text-underline-offset: 3px;
}
.saw-leave__go:hover { color: var(--saw-text); background: #f8fafc; }
.saw-leave__stay:focus-visible, .saw-leave__go:focus-visible, .saw-leave__backdrop:focus-visible {
	outline: 2px solid var(--saw-brand);
	outline-offset: 2px;
}
</style>
