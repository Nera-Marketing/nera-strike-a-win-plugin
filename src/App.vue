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

// Positional substitution for the results/language strings, which use PHP's
// %1$d / %2$s convention (several take more than one value, in an order that
// differs between languages once translated) rather than the older strings'
// single sequential %d / %s.
function fmt( key, fallback, ...args ) {
	let i = 0;
	return t( key, fallback )
		// Positional first ( %1$d, %2$s, … ) — the multi-argument strings, where
		// order can legitimately change between languages once translated.
		.replace( /%(\d)\$[ds]/g, ( _m, n ) => {
			const v = args[ Number( n ) - 1 ];
			return v === undefined || v === null ? '' : String( v );
		} )
		// Then plain %d / %s in sequence — the single-argument strings, which have
		// nothing to reorder.
		.replace( /%[ds]/g, () => {
			const v = args[ i++ ];
			return v === undefined || v === null ? '' : String( v );
		} );
}

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

// Answer reveal hold: how long the answered question stays on screen before the
// next slot is served. Admin-tunable; the server clamps it to 1–10s.
const FEEDBACK_SECONDS_FALLBACK = 5;

function feedbackHoldSeconds() {
	const n = parseInt( window.NeraSAW && window.NeraSAW.feedbackSeconds, 10 );
	return Number.isFinite( n ) && n > 0 ? n : FEEDBACK_SECONDS_FALLBACK;
}

const phase = ref( 'loading' ); // language | loading | stage-break | question | reveal | end | error
const errorMsg = ref( '' );
const canRetry = ref( false ); // error screen: offer a "Try again" for the failed step.

/* --- Quiz language ----------------------------------------------------------
 * Screen 24 of the reference design. Only shown when there is a real choice —
 * two or more languages the bank actually has enough questions in for THIS
 * competition (Nera_SAW_Run::playable_languages(), localised once per page load).
 * A monolingual site or a competition whose Russian bank is too thin sees no
 * screen at all and no error: §3 of docs/LANGUAGE-PLAN.md, "degrade to silence".
 * ------------------------------------------------------------------------- */
const playableLanguages = ( window.NeraSAW && window.NeraSAW.playableLanguages ) || [];
const languageNames = ( window.NeraSAW && window.NeraSAW.languageNames ) || {};
const languageOptions = playableLanguages.map( ( code ) => ( { code, name: languageNames[ code ] || code } ) );
const hasLanguageChoice = languageOptions.length > 1;
const chosenLanguage = ref( '' );

/* --- Stages -------------------------------------------------------------
 * 'Stage' is only meaningful in ladder mode (CONTEXT.md, ADR 0019): a consecutive
 * run of same-level questions. In random mode every question is its own stage —
 * the head-bar still numbers it "Stage N of M" for a consistent feel, but nothing
 * groups and this break screen never fires. See Nera_SAW_Run::stage_of() and
 * screen 25 of the reference design.
 * ------------------------------------------------------------------------- */
const stageNo = ref( 1 );
const stageCount = ref( 1 );
const stageRewardLabel = ref( '' );

// Pre-run header preview: lets the .saw-stagebar header (normally driven by the
// current slot, once a run exists) also render on the language-choice screen,
// matching the reference design's screen 24. Localised server-side from the
// competition's config with no run started — see class-frontend.php's
// enqueue_app(); blank/default in random-mode competitions, where "stage 1"
// isn't a deterministic concept before the run's slots are actually drawn. (The
// `slot.level_label` / `slot.level_text_color` half of this seeding happens
// where `slot` itself is declared below, since it doesn't exist yet here.)
( function seedPreRunStageCount() {
	const preRunStageCount = parseInt( window.NeraSAW && window.NeraSAW.stageCount, 10 );
	if ( Number.isFinite( preRunStageCount ) && preRunStageCount > 0 ) {
		stageCount.value = preRunStageCount;
	}
}() );
// Which stage the break screen has already been shown for, so a retry or a
// resume that re-fetches the same slot does not show it a second time.
let stageBreakShownFor = 0;
// The slot payload fetched while the break screen is up, applied once the
// player continues past it.
let pendingSlotData = null;

const runId = ref( 0 );
const competitionId = ref( 0 );
const totalSlots = ref( 0 );
const spinsSoFar = ref( 0 );
const spinsFinal = ref( null );

const selectedAnswer = ref( null );
const submitting = ref( false ); // answer POST in flight (reveal can't paint until it lands).
const revealCorrectIndex = ref( -1 ); // -1 = nothing to reveal (unusable snapshot).
const revealSecondsLeft = ref( 0 );
const revealIsFinal = ref( false ); // last slot: the hold leads to the results screen.
const advancing = ref( false ); // past the reveal, waiting on the next slot / results.
const ticketsLoading = ref( false );
const ticketsError = ref( '' );
const ticketNumbers = ref( [] );
const runsRemainingTotal = ref( null );
const runsRemainingTier = ref( null );
const tierLabel = ref( '' );
const slotResults = ref( [] ); // [{ slot_no, outcome, spins_awarded }] — the results chip row.
const drawDate = ref( '' );

const slot = reactive( {
	slot_no: 0,
	level: '',
	// Pre-seeded from the server's pre-run stage preview (see seedPreRunStageCount
	// above) so the language screen's .saw-stagebar shows real stage-1 info before
	// any slot has loaded; serve_slot()'s real values overwrite these the moment
	// the run actually starts.
	level_label: ( window.NeraSAW && window.NeraSAW.firstStageLabel ) || '',
	level_text_color: ( window.NeraSAW && window.NeraSAW.firstStageColor ) || '', // ladder colour, already contrast-corrected server-side — used here as a background fill (see .saw-stagebar), which is the same contrast rule read the other way round.
	question: '',
	answers: [], // [ { index, text } ]
	timer_seconds: 0,
	seconds_left: 0,
	reward: 0, // tickets this question is worth if answered correctly.
} );

const lastResult = reactive( { correct: false, timed_out: false, spins_awarded: 0, correct_index: -1 } );

let ticker = null;
let holdTicker = null;
let holdAdvance = null; // closure that advances past the reveal (button or timeout).
// Reactive: the template's disabled states (options, Submit) depend on it, so a
// plain let would only repaint by luck, whenever some other ref happened to change.
const locked = ref( false );
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

// The slot's difficulty colour, exposed as a custom property so the question text
// and the head-bar level name pick it up from one place. Absent (unparseable
// ladder colour) leaves the property unset and the CSS falls back to normal text.
const levelStyle = computed( () =>
	slot.level_text_color ? { '--saw-level': slot.level_text_color } : {}
);

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

/* --- Results screen (28 / 29) --------------------------------------------
 * Two layouts sharing one card: with tickets, or zero. Which one shows is
 * finalSpins > 0 — the same number the head-bar's TICKETS counter tallied live,
 * not a re-derivation from slotResults, so the two can never disagree.
 * ------------------------------------------------------------------------- */
const correctCount = computed( () => slotResults.value.filter( ( r ) => 'correct' === r.outcome ).length );
const hasTickets = computed( () => finalSpins.value > 0 );

const resultsTitle = computed( () => hasTickets.value ? t( 'inTheDrawTitle', "You're in the draw" ) : t( 'zeroTicketsTitle', 'No tickets this run' ) );

const resultsSubtitle = computed( () => {
	if ( hasTickets.value ) {
		return fmt( 'inTheDrawSubtitle', '%1$d tickets banked from %2$d correct answers.', finalSpins.value, correctCount.value );
	}
	if ( correctCount.value > 0 ) {
		return fmt( 'zeroSomeCorrectSubtitle', '%1$d of %2$d correct, but not enough to bank a ticket.', correctCount.value, totalSlots.value );
	}
	return fmt( 'zeroCorrectSubtitle', 'None of the %d answers landed in time.', totalSlots.value );
} );

// One chip per slot: "+N" (correct), "0" (wrong), "–" (timeout) — matches the
// vocabulary Nera_SAW_Run::slot_results() already scores in, so there is nothing
// to re-derive here beyond the label.
const resultChips = computed( () =>
	slotResults.value.map( ( r ) => {
		if ( 'correct' === r.outcome ) {
			return { key: r.slot_no, text: `+${ r.spins_awarded }`, kind: 'correct' };
		}
		if ( 'wrong' === r.outcome ) {
			return { key: r.slot_no, text: '0', kind: 'wrong' };
		}
		return { key: r.slot_no, text: '–', kind: 'timeout' };
	} )
);

const visibleTicketNumbers = computed( () => ticketNumbers.value.slice( 0, 6 ) );
const extraTicketCount = computed( () => Math.max( 0, ticketNumbers.value.length - 6 ) );

function isPlaying() {
	return quizSessionActive && ( phase.value === 'loading' || phase.value === 'question' || phase.value === 'reveal' );
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

/* --- Answer reveal ---------------------------------------------------------
 * After a submit we hold the answered question on screen: the picked option and
 * the correct one are marked, the head tally moves, and the player either waits
 * out the countdown or clicks through. Nothing is scored here — the server
 * already did that — and the next slot's deadline is stamped when it is served,
 * so the hold costs the player no answering time.
 * ------------------------------------------------------------------------- */

function clearHoldTicker() {
	if ( holdTicker ) {
		clearInterval( holdTicker );
		holdTicker = null;
	}
}

function clearHold() {
	clearHoldTicker();
	holdAdvance = null;
}

function startHold( state ) {
	revealSecondsLeft.value = feedbackHoldSeconds();
	holdAdvance = () => advanceAfterAnswer( state );
	resumeHold();
}

function resumeHold() {
	clearHoldTicker();
	if ( phase.value !== 'reveal' || ! holdAdvance ) {
		return;
	}
	holdTicker = setInterval( () => {
		revealSecondsLeft.value -= 1;
		if ( revealSecondsLeft.value <= 0 ) {
			endHold();
		}
	}, 1000 );
}

// Paused while the leave dialog is up. Otherwise the hold would expire behind the
// modal, serve the next slot, and let its server-side deadline drain on a question
// the player cannot see — real seconds lost off a paid run.
function pauseHold() {
	clearHoldTicker();
}

// Advance past the reveal — the countdown reaching zero and the Next button are
// the same action. Single-flight: whichever fires first wins.
function endHold() {
	clearHoldTicker();
	if ( phase.value !== 'reveal' || ! holdAdvance ) {
		return;
	}
	const advance = holdAdvance;
	holdAdvance = null;
	advancing.value = true;
	advance();
}

const revealDelta = computed( () =>
	phase.value === 'reveal' && lastResult.correct ? Math.max( 0, lastResult.spins_awarded ) : 0
);

const correctAnswerText = computed( () => {
	const found = ( slot.answers || [] ).find( ( a ) => a.index === revealCorrectIndex.value );
	return found ? found.text : '';
} );

// Screen-reader announcement: the visual reveal is colour alone, which assistive
// tech cannot convey, so the outcome is also stated in words off-screen.
const revealAnnouncement = computed( () => {
	if ( phase.value !== 'reveal' ) {
		return '';
	}
	if ( lastResult.correct ) {
		return t( 'revealCorrect', 'Correct. %d tickets earned.' ).replace( '%d', String( lastResult.spins_awarded ) );
	}
	const answer = correctAnswerText.value;
	if ( lastResult.timed_out ) {
		return answer
			? t( 'revealTimeout', 'Time up, no answer counted. The correct answer was: %s' ).replace( '%s', answer )
			: t( 'revealTimeoutBare', 'Time up — no answer counted.' );
	}
	return answer
		? t( 'revealWrong', 'Wrong. The correct answer was: %s' ).replace( '%s', answer )
		: t( 'revealWrongBare', 'Wrong — no tickets.' );
} );

// Green on the correct option; red only on a pick the server actually scored. A
// timed-out slot recorded no choice at all, so nothing goes red there even if the
// browser still had something highlighted.
function optionClass( answer ) {
	if ( phase.value !== 'reveal' ) {
		return { 'is-selected': selectedAnswer.value === answer.index };
	}
	return {
		'is-correct': revealCorrectIndex.value >= 0 && answer.index === revealCorrectIndex.value,
		'is-wrong': ! lastResult.correct && ! lastResult.timed_out && selectedAnswer.value === answer.index,
	};
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
	locked.value = false;
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
	// Every teardown path (error, results, confirmed leave) funnels through here,
	// so this is where a pending reveal advance is dropped. Without it a hold that
	// expires after we have abandoned would try to serve the next slot of a run
	// that is already finalized.
	clearHold();
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
	// The only path back into play, so the only place the reveal hold resumes.
	resumeHold();
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
	if ( open ) {
		pauseHold();
	}
	if ( typeof document === 'undefined' ) {
		return;
	}
	document.body.classList.toggle( 'saw-quiz-dialog-open', open );
} );

// The run's own screen is the only thing on the page from language choice
// through the results screen — the competition hero, quiz-spec panel and
// result teaser that share this page outside idle/start belong to a screen
// the player has already read, not to the one the run itself is showing.
// `end` (the results screen) is included: it is still one screen of the run,
// not a return to the competition page — `saw-results__actions`' own "Back to
// competitions" link is how a player actually leaves it.
watch( phase, ( p ) => {
	if ( typeof document === 'undefined' ) {
		return;
	}
	const takeover = p === 'loading' || p === 'language' || p === 'stage-break' || p === 'question' || p === 'reveal' || p === 'end';
	document.body.classList.toggle( 'saw-quiz-takeover', takeover );
}, { immediate: true } );

function applyState( state ) {
	runId.value = state.run_id || runId.value;
	competitionId.value = state.competition_id || competitionId.value;
	totalSlots.value = state.total_slots || totalSlots.value;
	spinsSoFar.value = state.spins_so_far || 0;
	if ( state.spins_final !== null && state.spins_final !== undefined ) {
		spinsFinal.value = state.spins_final;
	}
}

// The reference design's constant, mirrored from Nera_SAW_Mode::QUIZ_LADDER —
// kept as one literal here rather than threaded through localisation for a
// single string comparison.
const Nera_SAW_QUIZ_LADDER = 'ladder';

/**
 * Show the stage-break screen for the state's next slot, if one is due, WITHOUT
 * fetching that slot yet.
 *
 * Order matters here: serve_slot() stamps the clock on first fetch, so deciding
 * "does this need a break screen" has to happen from state()'s next_stage
 * look-ahead (a read of the already-drawn run_slots row) rather than from
 * getSlot()'s response — fetching early just to check would start the next
 * question's timer behind the very screen meant to give the player a breath
 * before it.
 *
 * @param {object} state Whatever start()/submit()/resume returned.
 * @return {boolean} true if the break screen is now showing (caller stops here).
 */
function maybeShowStageBreak( state ) {
	const next = state && state.next_stage;
	if (
		! next ||
		! state.next_slot ||
		Nera_SAW_QUIZ_LADDER !== next.quiz_method ||
		! next.is_first_of_stage ||
		stageBreakShownFor === next.stage_no
	) {
		return false;
	}

	stageBreakShownFor = next.stage_no;
	pendingSlotData = state.next_slot;
	stageNo.value = next.stage_no;
	stageCount.value = next.stage_count;
	slot.level_label = next.level_label;
	slot.level_text_color = next.level_text_color;
	stageRewardLabel.value = next.reward_label;
	phase.value = 'stage-break';
	return true;
}

function continueFromStageBreak() {
	const slotNo = pendingSlotData;
	pendingSlotData = null;
	if ( slotNo ) {
		loadSlot( slotNo );
	}
}

async function start() {
	retryAction = start;
	try {
		const state = await api.startRun( props.competitionId, props.tier, props.startToken, chosenLanguage.value );
		applyState( state );
		breadcrumb.save( props.competitionId, runId.value );
		if ( state.status === 'finalized' ) {
			quizSessionActive = false;
			return toEnd();
		}
		quizSessionActive = true;
		armNavigationGuard();
		if ( maybeShowStageBreak( state ) ) {
			return;
		}
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
	clearHold();
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
		// The head-bar's "Stage N of M" reads these on every slot, not only the
		// first of a stage — maybeShowStageBreak() sets them for the transition,
		// but a plain loadSlot() (stage 1, or the second+ question within a stage)
		// never goes through there and must not be left showing a stale stage.
		stageNo.value = data.stage_no || stageNo.value;
		stageCount.value = data.stage_count || stageCount.value;

		// Reset only now that the replacement question is in hand. Clearing earlier
		// would blank the reveal — highlights gone, countdown at zero, options live
		// again — for however long the fetch takes, on the screen the player is
		// still looking at. locked must drop before the expired-slot path below, or
		// its submit('') would no-op against the previous answer's lock.
		locked.value = false;
		submitting.value = false;
		advancing.value = false;
		selectedAnswer.value = null;
		revealCorrectIndex.value = -1;
		revealSecondsLeft.value = 0;
		revealIsFinal.value = false;

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
			if ( ! locked.value ) {
				submit( '' );
			}
		}
	}, 1000 );
}

// Reference design screen 26: no separate Submit step — picking an option answers
// it immediately, and the reveal (screen 27) is the same layout with colours and a
// banked-line applied, not a new screen. selectedAnswer is kept only for the
// brief "submitting" highlight between click and the server's reply.
function selectOption( index ) {
	if ( locked.value || submitting.value ) {
		return;
	}
	selectedAnswer.value = index;
	submit( index );
}

function advanceAfterAnswer( state ) {
	if ( state.status === 'finalized' || ! state.next_slot ) {
		toEnd();
	} else if ( ! maybeShowStageBreak( state ) ) {
		loadSlot( state.next_slot );
	}
}

async function submit( option ) {
	if ( locked.value ) {
		return;
	}
	locked.value = true;
	submitting.value = true;
	clearTicker();
	retryAction = () => submit( option );
	try {
		const state = await api.answer( runId.value, slot.slot_no, option );
		applyState( state );
		if ( state.result ) {
			Object.assign( lastResult, state.result );
		}
		submitting.value = false;
		if ( ! isAnswerFeedbackEnabled() ) {
			return advanceAfterAnswer( state );
		}
		// Only the server knows which option was right (ADR 0017); it arrives with
		// this response, so the reveal can only be painted now.
		revealCorrectIndex.value = Number.isInteger( lastResult.correct_index ) ? lastResult.correct_index : -1;
		revealIsFinal.value = ( 'finalized' === state.status || ! state.next_slot );
		phase.value = 'reveal';
		startHold( state );
	} catch ( e ) {
		submitting.value = false;
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
		slotResults.value = Array.isArray( summary.slot_results ) ? summary.slot_results : [];
		drawDate.value = summary.draw_date || '';
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
const competitionsListUrl = ( window.NeraSAW && window.NeraSAW.competitionsUrl ) || '';
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

function pickLanguage( code ) {
	chosenLanguage.value = code;
	setupNavigationGuards();
	start();
}

onMounted( () => {
	if ( hasLanguageChoice ) {
		// Screen 24: the player picks before anything is drawn, so the run itself
		// is created in the chosen language — see pickLanguage() -> start(). No
		// navigation guard yet; nothing paid-for exists to protect until they choose.
		phase.value = 'language';
		return;
	}
	setupNavigationGuards();
	start();
} );
onUnmounted( () => {
	clearTicker();
	releaseNavigationGuard();
	if ( typeof document !== 'undefined' ) {
		document.body.classList.remove( 'saw-quiz-dialog-open' );
		document.body.classList.remove( 'saw-quiz-takeover' );
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

		<div
			v-else-if="phase === 'language' || phase === 'stage-break' || phase === 'question' || phase === 'reveal'"
			class="saw-play"
		>
			<div class="saw-stagebar" :style="{ background: slot.level_text_color || 'var(--saw-brand)' }">
				<div class="saw-stagebar__row">
					<span class="saw-stagebar__pill">{{ fmt('stageOf', 'Stage %1$d of %2$d · %3$s', stageNo, stageCount, slot.level_label) }}</span>
					<span class="saw-stagebar__tickets">
						<span class="saw-stagebar__ticketslabel">{{ t('ticketsLabel', 'Tickets') }}</span>
						<span class="saw-stagebar__ticketsvalue">{{ spinsSoFar }}</span>
					</span>
				</div>
				<!-- No slot has loaded yet on the language screen, so totalSlots/slot.slot_no
				     aren't known — the progress dots would just be an empty bar; skip them
				     there rather than render something meaningless. -->
				<div v-if="phase !== 'language'" class="saw-stagebar__dots">
					<span
						v-for="n in totalSlots"
						:key="n"
						class="saw-stagebar__dot"
						:class="{ 'is-filled': n <= slot.slot_no }"
					></span>
				</div>
			</div>

			<div v-if="phase === 'language'" class="saw-langcard">
				<h2 class="saw-langcard__title">{{ t('quizLanguageTitle', 'Quiz language') }}</h2>
				<p class="saw-langcard__intro">{{ t('quizLanguageIntro', 'Questions and answers will appear in this language') }}</p>
				<div class="saw-langcard__options">
					<button
						v-for="opt in languageOptions"
						:key="opt.code"
						type="button"
						class="saw-langcard__btn"
						@click="pickLanguage(opt.code)"
					>{{ opt.name }}</button>
				</div>
			</div>

			<div v-else-if="phase === 'stage-break'" class="saw-stagebreak">
				<div class="saw-stagebreak__badge" :style="{ background: slot.level_text_color || 'var(--saw-brand)' }">{{ stageNo }}</div>
				<h2 class="saw-stagebreak__title">{{ t('stageBreakHeadline_' + stageNo, slot.level_label) }}</h2>
				<p class="saw-stagebreak__sub">{{ fmt('stageOf', 'Stage %1$d of %2$d · %3$s', stageNo, stageCount, slot.level_label) }} · {{ stageRewardLabel }}</p>
				<button type="button" class="saw-stagebreak__continue" @click="continueFromStageBreak">
					{{ t('continueLabel', 'Continue') }}
				</button>
			</div>

			<div v-else class="saw-qbody" :class="{ 'is-revealing': phase === 'reveal' }" :style="levelStyle">
				<div class="saw-qbody__head">
					<div>
						<p class="saw-qbody__count">{{ fmt('questionOf', 'Question %1$d of %2$d', slot.slot_no, totalSlots) }}</p>
						<span class="saw-qbody__worth">{{ slot.reward === 1 ? fmt('worthTicket', 'Worth %d ticket', slot.reward) : fmt('worthTickets', 'Worth %d tickets', slot.reward) }}</span>
					</div>
					<div
						class="saw-qtimer"
						:class="{ 'is-urgent': timerUrgent, 'is-spent': phase === 'reveal' }"
						:style="{ '--saw-qtimer-pct': timerPct }"
					>
						<svg viewBox="0 0 44 44" width="44" height="44" aria-hidden="true">
							<circle class="saw-qtimer__track" cx="22" cy="22" r="19"></circle>
							<circle class="saw-qtimer__fill" cx="22" cy="22" r="19"></circle>
						</svg>
						<span class="saw-qtimer__num">{{ Math.max(0, slot.seconds_left) }}</span>
					</div>
				</div>

				<h1 class="saw-question">{{ slot.question }}</h1>
				<p class="saw-sr-only" role="status" aria-live="polite">{{ revealAnnouncement }}</p>

				<div class="saw-answers">
					<button
						v-for="a in slot.answers"
						:key="a.index"
						type="button"
						class="saw-answer"
						:class="optionClass(a)"
						:disabled="locked || submitting"
						:aria-pressed="selectedAnswer === a.index"
						@click="selectOption(a.index)"
					>{{ a.text }}</button>
				</div>

				<div v-if="phase === 'reveal'" class="saw-banked" :class="{ 'is-zero': !lastResult.correct }">
					{{ lastResult.correct
						? fmt('bankedLine', '+%1$d banked. %2$d tickets total.', lastResult.spins_awarded, spinsSoFar)
						: ( lastResult.timed_out
							? fmt('timeUpLine', "Time's up. %d tickets total.", spinsSoFar)
							: fmt('wrongLine', 'Not this time. %d tickets total.', spinsSoFar) )
					}}
					<button
						type="button"
						class="saw-banked__next"
						:disabled="advancing"
						@click="endHold"
					>{{ revealIsFinal ? t('seeResults', 'See my results') : t('nextQuestion', 'Next question') }} ({{ Math.max(0, revealSecondsLeft) }})</button>
				</div>
			</div>
		</div>

		<div v-else-if="phase === 'end'" class="saw-results">
			<p class="saw-results__eyebrow">{{ t('runComplete', 'Run complete') }}</p>
			<h1 class="saw-results__title">{{ resultsTitle }}</h1>
			<p class="saw-results__subtitle">{{ resultsSubtitle }}</p>

			<div class="saw-results__card">
				<div class="saw-results__row">
					<span class="saw-results__label">{{ t('scoreLabel', 'Score') }}</span>
					<span class="saw-results__value">{{ fmt('scoreValue', '%1$d of %2$d correct', correctCount, totalSlots) }}</span>
				</div>
				<div class="saw-results__row">
					<span class="saw-results__label">{{ t('ticketsEarnedLabel', 'Tickets earned') }}</span>
					<span class="saw-results__value saw-results__value--big" :class="{ 'is-zero': !hasTickets }">{{ finalSpins }}</span>
				</div>

				<div class="saw-results__chips">
					<span
						v-for="c in resultChips"
						:key="c.key"
						class="saw-chip"
						:class="'is-' + c.kind"
					>{{ c.text }}</span>
				</div>

				<template v-if="hasTickets">
					<div class="saw-results__divider"></div>
					<p class="saw-results__label">{{ t('yourEntryNumbers', 'Your entry numbers') }}</p>
					<div class="saw-results__numbers">
						<span v-for="num in visibleTicketNumbers" :key="num" class="saw-numchip">{{ num }}</span>
						<span v-if="extraTicketCount > 0" class="saw-numchip saw-numchip--more">{{ fmt('moreNumbers', '+%d more', extraTicketCount) }}</span>
					</div>
					<p class="saw-results__note">{{ t('numbersPoolNote', "Numbers are allocated at random from this draw's pool.") }}</p>
					<div class="saw-results__divider"></div>
					<p class="saw-results__fineprint">
						<template v-if="drawDate">{{ fmt('drawDatePrefix', 'Draw: %s', drawDate) }}. </template>{{ t('drawInfoWithEntry', "Random draw, independently witnessed. You'll be notified either way.") }}
					</p>
				</template>
				<template v-else>
					<div class="saw-results__divider"></div>
					<p class="saw-results__fineprint">{{ t('noEntryNote', 'No tickets were earned, so there is no entry in this draw, and no refund is due.') }}</p>
					<p v-if="drawDate" class="saw-results__fineprint saw-results__fineprint--muted">{{ fmt('drawClosesPrefix', 'The draw closes %s.', drawDate) }}</p>
				</template>

				<div v-if="ticketsLoading" class="saw-minting" role="status" aria-live="polite">
					<span class="saw-spinner" aria-hidden="true"></span>
					<span>{{ t('mintingTickets', 'Adding your tickets to the draw…') }}</span>
				</div>
				<div v-else-if="ticketsError" class="saw-mint-error">
					<p>{{ ticketsError }}</p>
					<button type="button" class="saw-btn saw-btn-ghost" @click="fetchRunComplete">{{ t('retryTickets', 'Try again') }}</button>
				</div>

				<p v-if="runsRemainingLine" class="saw-runs-left">{{ runsRemainingLine }}</p>
			</div>

			<div class="saw-results__actions" :class="{ 'is-muted': !hasTickets }">
				<a
					v-if="runsRemainingTotal > 0"
					class="saw-results__primary"
					:class="{ 'is-ghost': !hasTickets }"
					:href="playAgainUrl"
				>{{ t('playAnotherRun', 'Play another run') }}</a>
				<a
					class="saw-results__secondary"
					:class="{ 'is-link': !hasTickets }"
					:href="competitionsListUrl || competitionUrl"
				>{{ t('backToCompetitions', 'Back to competitions') }}</a>
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
				<!-- No clock during the reveal: this question is already scored and the
				     hold is paused, so there is nothing counting down to show. -->
				<div v-if="phase !== 'reveal'" class="saw-leave__clock" :class="{ 'is-urgent': timerUrgent }" aria-hidden="true">
					<span class="saw-leave__clock-num">{{ Math.max(0, slot.seconds_left) }}</span>
					<span class="saw-leave__clock-label">sec left</span>
				</div>
				<h2 id="saw-leave-title" class="saw-leave__title">{{ t('leaveDialogTitle', 'Leave the quiz?') }}</h2>
				<p v-if="phase === 'reveal'" class="saw-leave__body">{{ t('leaveDialogBodyReveal', 'This answer is already saved. If you leave now, every question you have not reached scores zero.') }}</p>
				<p v-else class="saw-leave__body">{{ t('leaveDialogBody', 'The countdown keeps running on the server while you are away. Any question you have not submitted scores zero.') }}</p>
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
	--saw-ok: #16a34a;
	--saw-ok-soft: #dcfce7;
	/* Height of a single-line answer option: 16px padding x2 + 2px border x2 +
	   the ~20px line box at 16px. The mobile Submit / Next button matches it, so
	   the two stay in step if this option's padding or font size ever changes. */
	--saw-answer-min-h: 56px;
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
/* Difficulty Level, in its ladder colour. --saw-level is set per slot from the
   server and is already contrast-corrected, so it is safe on 14px text. */
.saw-level { color: var(--saw-level, var(--saw-text-muted)); font-weight: 700; }
.saw-tallywrap { display: inline-flex; align-items: center; gap: 8px; }
.saw-tally { font-weight: 700; color: var(--saw-brand); }
/* Tickets just earned, held beside the running total for the length of the reveal. */
.saw-delta {
	font-weight: 800; font-variant-numeric: tabular-nums; font-size: 13px; line-height: 1;
	padding: 4px 8px; border-radius: 999px;
	background: var(--saw-ok-soft); color: var(--saw-ok);
	animation: saw-delta-in .28s ease-out;
}
@keyframes saw-delta-in {
	from { opacity: 0; transform: translateY(-4px) scale(.9); }
	to   { opacity: 1; transform: none; }
}
.saw-timerbar { height: 6px; background: var(--saw-surface); border-radius: 6px; overflow: hidden; margin-bottom: 6px; transition: background-color .25s ease; }
.saw-timerbar.is-urgent { background: var(--saw-danger-soft); }
.saw-timerfill { height: 100%; background: var(--saw-brand); transition: width 1s linear, background-color .25s ease; }
.saw-timerbar.is-urgent .saw-timerfill { background: var(--saw-danger); }
.saw-count { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; margin-bottom: 14px; color: var(--saw-brand); transition: color .25s ease; }
.saw-count.is-urgent { color: var(--saw-danger); }
/* Reveal: the question timer is over. Both readouts freeze where the player
   answered and go grey, so neither reads as time still available. */
.saw-timerbar.is-spent .saw-timerfill { background: var(--saw-text-muted); }
.saw-count.is-spent { color: var(--saw-text-muted); }
.saw-sr-only {
	position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
	overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}
/* Question text carries the difficulty colour, through the Answer reveal too: the
   outcome lives in the option boxes, the level lives here. */
.saw-question { font-size: 20px; line-height: 1.35; font-weight: 600; margin-bottom: 8px; color: var(--saw-level, var(--saw-text)); }
.saw-hint { font-size: 14px; color: var(--saw-text-muted); margin: 0 0 14px; }
.saw-hint.is-hidden { visibility: hidden; }
.saw-options { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 480px) { .saw-options { grid-template-columns: 1fr; } }
.saw-option {
	display: flex; align-items: center; gap: 12px; text-align: left; padding: 16px;
	min-height: var(--saw-answer-min-h);
	background: #fff; border: 2px solid var(--saw-surface); border-radius: 12px;
	cursor: pointer; font-size: 16px; color: var(--saw-text);
	transition: border-color .12s, transform .06s, box-shadow .12s, background .12s;
}
.saw-option:hover:not(:disabled) { border-color: color-mix(in srgb, var(--saw-brand) 55%, var(--saw-surface)); box-shadow: 0 4px 14px -8px color-mix(in srgb, var(--saw-brand) 30%, transparent); }
.saw-option.is-selected { border-color: var(--saw-brand); background: color-mix(in srgb, var(--saw-brand) 6%, #fff); box-shadow: 0 0 0 1px var(--saw-brand); }
.saw-option:active:not(:disabled) { transform: scale(0.99); }
.saw-option:disabled { opacity: .6; cursor: default; }
/* Answer reveal. Options are disabled throughout the hold, so these must beat the
   :disabled dimming above or the highlight the whole feature rests on washes out. */
.saw-option.is-correct,
.saw-option.is-wrong { opacity: 1; }
.saw-option.is-correct {
	border-color: var(--saw-ok);
	background: color-mix(in srgb, var(--saw-ok) 10%, #fff);
	box-shadow: 0 0 0 1px var(--saw-ok);
}
.saw-option.is-wrong {
	border-color: var(--saw-danger);
	background: color-mix(in srgb, var(--saw-danger) 8%, #fff);
	box-shadow: 0 0 0 1px var(--saw-danger);
}
/* Everything neither picked nor correct recedes further, so the two marked
   options carry the eye. */
.saw-play.is-revealing .saw-option:not(.is-correct):not(.is-wrong) { opacity: .45; }
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
/* Reveal: the Submit button becomes Next in place, carrying the hold countdown, so
   the layout does not shift under the player at the moment they are reading. */
.saw-btn-next { gap: 10px; }
.saw-next-count {
	display: inline-flex; align-items: center; justify-content: center;
	min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px;
	background: rgba(255, 255, 255, .22);
	font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums;
}
@media (max-width: 480px) {
	.saw-submitwrap { justify-content: stretch; }
	/* Full-width and the same height as an answer, so the action reads as the last
	   item in the stack rather than an afterthought below it. The type steps up to
	   the options' 16px and the radius to their 12px for the same reason: at this
	   width the button is the same shape of thing, so it should look like one. */
	.saw-btn-submit {
		width: 100%;
		min-width: 0;
		min-height: var(--saw-answer-min-h);
		padding: 12px 18px;
		font-size: 16px;
		border-radius: 12px;
	}
}
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
	.saw-delta { animation: none; }
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

/* =====================================================================
 * Reference design (strikeawin-elements.pages.dev, screens 24–29): the
 * language pick, the stage-shelled question/reveal, the stage-break, and the
 * two results layouts. Everything below is new; the rules above this point
 * belong to the pre-reference default skin and are left in place rather than
 * pruned, since nothing here still uses their class names.
 * ===================================================================== */

/* A single white-card shell. The language pick now shares the same
   .saw-play-wrapped stage-bar header as the stage-break/question/reveal
   screens (reference design screen 24), so .saw-langcard is always nested
   inside .saw-play and only needs its own inner padding below — the shell
   itself (sizing, background, radius, shadow) lives on .saw-play alone so it
   isn't doubled up. */
.saw-play {
	width: 100%;
	max-width: 560px;
	margin: 0 auto;
	background: var(--saw-card, #fffaf4);
	border-radius: 20px;
	box-shadow: 0 20px 50px -24px color-mix(in srgb, var(--saw-brand) 35%, transparent);
	overflow: hidden;
}

/* ---- quiz language (screen 24) ------------------------------------- */
.saw-langcard { padding: 48px 32px; text-align: center; }
.saw-langcard__title { margin: 0 0 8px; font-size: 22px; font-weight: 800; color: var(--saw-text); }
.saw-langcard__intro { margin: 0 0 22px; color: var(--saw-text-muted); font-size: 14px; }
.saw-langcard__options { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; }
.saw-langcard__btn {
	padding: 14px 26px; min-width: 140px;
	background: #fff; border: 1.5px solid var(--saw-surface); border-radius: 12px;
	font-size: 16px; font-weight: 700; color: var(--saw-text); cursor: pointer;
	transition: border-color .12s, box-shadow .12s;
}
.saw-langcard__btn:hover { border-color: var(--saw-brand); box-shadow: 0 4px 14px -8px color-mix(in srgb, var(--saw-brand) 30%, transparent); }

/* ---- the stage bar, shared by stage-break and question/reveal (25/26/27) --
   Background colour comes straight off slot.level_text_color: a value already
   corrected to sit safely under white text (Nera_SAW_Constants::level_text_color()
   darkens a level's colour until it passes contrast against white) — the same
   arithmetic works unchanged whichever of the two colours plays "background". */
.saw-stagebar { padding: 18px 22px 14px; color: var(--saw-on-dark, #fff); }
.saw-stagebar__row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.saw-stagebar__pill {
	display: inline-block; padding: 5px 12px; border-radius: 999px;
	background: rgba(0, 0, 0, .18); font-size: 13px; font-weight: 700;
}
.saw-stagebar__tickets { text-align: right; }
.saw-stagebar__ticketslabel { display: block; font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; opacity: .8; }
.saw-stagebar__ticketsvalue { display: block; font-size: 22px; font-weight: 800; line-height: 1.1; }
.saw-stagebar__dots { display: flex; gap: 5px; }
.saw-stagebar__dot { flex: 1 1 0; height: 5px; border-radius: 4px; background: rgba(255, 255, 255, .3); }
.saw-stagebar__dot.is-filled { background: rgba(255, 255, 255, .95); }

/* ---- stage break (screen 25) ---------------------------------------- */
.saw-stagebreak { padding: 60px 32px; text-align: center; }
.saw-stagebreak__badge {
	width: 64px; height: 64px; margin: 0 auto 22px; border-radius: 50%;
	display: grid; place-items: center; color: var(--saw-on-dark, #fff);
	font-size: 26px; font-weight: 800;
}
.saw-stagebreak__title { margin: 0 0 8px; font-size: 20px; font-weight: 800; color: var(--saw-text); }
.saw-stagebreak__sub { margin: 0 0 26px; color: var(--saw-text-muted); font-size: 14px; }
.saw-stagebreak__continue {
	padding: 12px 30px; border: 0; border-radius: 12px;
	background: var(--saw-brand); color: #fff; font-size: 15px; font-weight: 700; cursor: pointer;
}
.saw-stagebreak__continue:hover { background: color-mix(in srgb, var(--saw-brand) 90%, #000); }

/* ---- question / reveal (26 / 27) ------------------------------------ */
.saw-qbody { padding: 22px 24px 26px; }
.saw-qbody__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 14px; }
.saw-qbody__count { margin: 0 0 6px; font-size: 13px; color: var(--saw-text-muted); }
.saw-qbody__worth {
	display: inline-block; padding: 4px 12px; border-radius: 999px;
	border: 1.5px solid var(--saw-level, var(--saw-brand)); color: var(--saw-level, var(--saw-brand));
	font-size: 12px; font-weight: 700;
}

/* The circular per-question countdown, replacing the old horizontal bar to
   match the reference. A ring drawn twice — a faint full track, and a solid
   arc whose length is set by --saw-qtimer-pct via stroke-dasharray, animated
   the same way the old bar animated its width. */
.saw-qtimer { position: relative; width: 44px; height: 44px; flex: 0 0 auto; }
.saw-qtimer svg { transform: rotate(-90deg); }
.saw-qtimer__track { fill: none; stroke: var(--saw-surface); stroke-width: 4; }
.saw-qtimer__fill {
	fill: none; stroke: var(--saw-level, var(--saw-brand)); stroke-width: 4; stroke-linecap: round;
	stroke-dasharray: 119.4; /* 2 * PI * r(19) */
	stroke-dashoffset: calc(119.4 - (119.4 * var(--saw-qtimer-pct, 100) / 100));
	transition: stroke-dashoffset 1s linear, stroke .25s ease;
}
.saw-qtimer.is-urgent .saw-qtimer__fill { stroke: var(--saw-danger); }
.saw-qtimer.is-spent .saw-qtimer__fill { stroke: var(--saw-text-muted); }
.saw-qtimer__num {
	position: absolute; inset: 0; display: grid; place-items: center;
	font-size: 15px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--saw-text);
}

.saw-play .saw-question { margin: 0 0 18px; font-size: 21px; line-height: 1.35; font-weight: 700; color: var(--saw-text); }

/* Single column, click-to-answer: no separate Submit step (see selectOption()
   in the script) — picking an option is answering it. */
.saw-answers { display: flex; flex-direction: column; gap: 12px; }
.saw-answer {
	display: block; width: 100%; text-align: left; padding: 16px 18px;
	background: var(--saw-tint, #fcf4ec); border: 1.5px solid var(--saw-control, var(--saw-surface));
	border-radius: 12px; font-size: 16px; font-weight: 600; color: var(--saw-text); cursor: pointer;
	transition: border-color .12s, background .12s, box-shadow .12s, opacity .12s;
}
.saw-answer:hover:not(:disabled) { border-color: var(--saw-brand); }
.saw-answer:disabled { cursor: default; }
.saw-answer.is-selected { border-color: var(--saw-brand); box-shadow: 0 0 0 1px var(--saw-brand); }
.saw-answer.is-correct { background: var(--saw-ok); border-color: var(--saw-ok); color: #fff; }
.saw-answer.is-wrong { background: var(--saw-danger); border-color: var(--saw-danger); color: #fff; opacity: 1; }
.saw-play .is-revealing .saw-answer:not(.is-correct):not(.is-wrong) { opacity: .55; }

/* The reveal's outcome line — replaces the old Submit/Next button chrome. The
   whole banner doubles as the "next question" control (click anywhere on it),
   with the countdown that was in .saw-next-count now printed inline. */
.saw-banked {
	margin-top: 14px; padding: 14px 16px; border-radius: 12px;
	background: color-mix(in srgb, var(--saw-ok) 12%, #fff); color: var(--saw-ok);
	font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
}
.saw-banked.is-zero { background: var(--saw-tint, #fcf4ec); color: var(--saw-text-muted); }
.saw-banked__next {
	border: 0; background: transparent; color: inherit; font: inherit; font-weight: 800;
	text-decoration: underline; text-underline-offset: 3px; cursor: pointer; white-space: nowrap;
}
.saw-banked__next:disabled { opacity: .5; cursor: default; }

/* ---- results (28 / 29) ----------------------------------------------- */
.saw-results { width: 100%; max-width: 560px; margin: 0 auto; text-align: center; }
.saw-results__eyebrow { margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--saw-text-muted); }
.saw-results__title { margin: 0 0 8px; font-size: 26px; font-weight: 800; color: var(--saw-text); }
.saw-results__subtitle { margin: 0 0 22px; color: var(--saw-text-muted); font-size: 15px; }

.saw-results__card {
	text-align: left; padding: 22px 24px; margin-bottom: 18px;
	background: var(--saw-card, #fffaf4); border-radius: 18px;
	box-shadow: 0 16px 40px -22px color-mix(in srgb, var(--saw-brand) 30%, transparent);
}
.saw-results__row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
.saw-results__label { font-size: 13px; color: var(--saw-text-muted); }
.saw-results__value { font-size: 15px; font-weight: 800; color: var(--saw-text); }
.saw-results__value--big { font-size: 26px; color: var(--saw-ok); }
.saw-results__value--big.is-zero { color: var(--saw-text); }

.saw-results__chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 14px 0 4px; }
.saw-chip {
	min-width: 34px; padding: 5px 8px; border-radius: 8px; text-align: center;
	font-size: 12px; font-weight: 800; font-variant-numeric: tabular-nums;
}
.saw-chip.is-correct { background: color-mix(in srgb, var(--saw-ok) 14%, #fff); color: var(--saw-ok); }
.saw-chip.is-wrong { background: color-mix(in srgb, var(--saw-danger) 12%, #fff); color: var(--saw-danger); }
.saw-chip.is-timeout { background: var(--saw-tint, #fcf4ec); color: var(--saw-text-muted); }

.saw-results__divider { height: 1px; background: var(--saw-rule, var(--saw-surface)); margin: 16px 0; }
.saw-results__numbers { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 8px; }
.saw-numchip {
	padding: 6px 12px; border-radius: 999px; background: var(--saw-tint, #fcf4ec);
	border: 1px solid var(--saw-control, var(--saw-surface)); font-size: 13px; font-weight: 700; color: var(--saw-text);
}
.saw-numchip--more { color: var(--saw-brand); border-color: transparent; background: transparent; }
.saw-results__note { margin: 0; font-size: 12px; color: var(--saw-text-muted); }
.saw-results__fineprint { margin: 0; font-size: 13px; line-height: 1.6; color: var(--saw-text); }
.saw-results__fineprint--muted { color: var(--saw-text-muted); margin-top: 4px; }

.saw-results__actions { display: flex; flex-direction: column; gap: 10px; }
.saw-results__primary {
	display: block; padding: 15px; border-radius: 12px; text-align: center;
	background: var(--saw-brand); color: #fff; font-weight: 700; font-size: 15px; text-decoration: none;
}
.saw-results__primary:hover { background: color-mix(in srgb, var(--saw-brand) 90%, #000); color: #fff; }
.saw-results__primary.is-ghost { background: #fff; color: var(--saw-text); border: 1.5px solid var(--saw-surface); }
.saw-results__secondary {
	display: block; padding: 15px; border-radius: 12px; text-align: center;
	background: #fff; color: var(--saw-text); font-weight: 700; font-size: 15px; text-decoration: none;
	border: 1.5px solid var(--saw-surface);
}
.saw-results__secondary:hover { border-color: var(--saw-brand); color: var(--saw-brand); }
/* Zero-tickets: the emphasis reverses — "play again" is quiet, "back" is a bare
   link — so the screen does not read as a nudge to spend more right after a loss. */
.saw-results__actions.is-muted .saw-results__secondary.is-link {
	border: 0; background: transparent; font-weight: 600; color: var(--saw-brand); text-decoration: underline; text-underline-offset: 3px;
}

@media (max-width: 480px) {
	.saw-langcard { padding: 32px 20px; }
	.saw-langcard__options { flex-direction: column; }
	.saw-qbody { padding: 18px 16px 20px; }
	.saw-results__card { padding: 18px; }
}
</style>

<style>
body.saw-quiz-dialog-open { overflow: hidden; }
body.saw-quiz-takeover .saw-hero,
body.saw-quiz-takeover .saw-spec,
body.saw-quiz-takeover .saw-result-teaser,
body.saw-quiz-takeover .saw-footnote,
body.saw-quiz-takeover .saw-connector { display: none; }
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
