<script setup>
import { ref, reactive, onMounted, onUnmounted, computed, nextTick, watch } from 'vue';
import { api, breadcrumb } from './api.js';
import { toBlob } from 'html-to-image';
import { pickPluralForm, agreeNoun } from './plural.js';

const props = defineProps( {
	competitionId: { type: Number, required: true },
	tier: { type: String, default: '' },
	startToken: { type: String, default: '' },
	// Set by the interrupted-run popup. Resume skips the language screen (the run
	// already has its language); leaveNow ends the run and goes to the results.
	resume: { type: Boolean, default: false },
	leaveNow: { type: Boolean, default: false },
} );

const strings = ( window.NeraSAW && window.NeraSAW.strings ) || {};
const t = ( key, fallback ) => strings[ key ] || fallback;

/**
 * Substitutes a string's placeholders from `args`, in the order each
 * placeholder first appears — whichever style the resolved string actually
 * uses. English strings here still use PHP's `%1$d` / plain `%d` / `%s`
 * (unchanged, pre-existing); the client's supplied Russian uses `{name}`
 * instead, so args[0] fills the first `{…}` encountered, args[1] the second,
 * and so on — the two styles never both appear in the same string, so one
 * shared running index is enough.
 *
 * When the resolved value is pipe-separated (a plural template), the right
 * form is chosen via `pickPluralForm()` first, keyed on `args[0]` — the
 * supplied strings always put the deciding count first.
 *
 * @param {string} key
 * @param {string} fallback
 * @param {...*}   args
 */
function fmt( key, fallback, ...args ) {
	let raw = t( key, fallback );

	/*
	 * Named form — fmt( key, fallback, count, { n: …, tier: … } ) —
	 * substitutes `{name}` by NAME rather than by order of appearance.
	 * Needed wherever a translation reorders placeholders relative to the
	 * English source: runsRemainingCombined's Russian few/many forms put
	 * `{tier}` before `{n2}`, the opposite of the English template's own
	 * order, so position-based substitution silently swapped them.
	 */
	if ( 2 === args.length && null !== args[ 1 ] && 'object' === typeof args[ 1 ] ) {
		const [ count, vars ] = args;
		if ( raw.indexOf( '|' ) !== -1 ) {
			raw = pickPluralForm( count, raw );
		}
		return raw.replace( /\{(\w+)\}/g, ( _m, name ) => {
			const v = vars[ name ];
			return v === undefined || v === null ? '' : String( v );
		} );
	}

	if ( raw.indexOf( '|' ) !== -1 ) {
		raw = pickPluralForm( args[ 0 ], raw );
	}

	let i = 0;
	return raw
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
		} )
		// `{name}` — the client's supplied Russian strings' own placeholder style.
		.replace( /\{\w+\}/g, () => {
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

// Manual Next button during the reveal (admin setting, off by default).
const showNextButton = !! ( window.NeraSAW && window.NeraSAW.revealNextButton === true );

const phase = ref( 'loading' ); // language | loading | question | reveal | end | error
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
// The quiz bar: the competition (name, prize photo, market value, tiers),
// localised once per page load in class-frontend.php's enqueue_app().
const competition = ( window.NeraSAW && window.NeraSAW.competition ) || {};
// The run's tier key: the Start/Resume state's tier_key once it lands (a
// resumed run or "End run" may arrive without a tier prop).
const tierKey = ref( props.tier || '' );

// "Quiz n of N · Gold": n is this run's place among the runs the same
// purchase bought for this tier, N how many it bought (also "Quiz 1 of 1").
const quizOfLabel = computed( () => {
	const tiers = competition.tiers && 'object' === typeof competition.tiers ? competition.tiers : {};
	const tier = tiers[ tierKey.value ] || null;
	const label = tier ? tier.label : tierKey.value;
	const head = fmt( 'quizOf', 'Quiz {n} of {total}', runIndex.value, runTotal.value );
	return label ? head + ' · ' + label : head;
} );

// Pre-run stage count, seeded before the language-choice screen
// (reference design screen 24). Localised server-side from the
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

const runId = ref( 0 );
const competitionId = ref( 0 );
const totalSlots = ref( 0 );
const spinsSoFar = ref( 0 );
const spinsFinal = ref( null );
// "Run X of N" (client finding #36 / requirements.md's own "Run 1 of 3"):
// N is how many runs THIS purchase bought for this tier, X is this run's
// own place among them — see Nera_SAW_Run::run_ordinal_for_order_tier().
// runTotal defaults to 1, not 0, so "Run 1 of 1" (the common case, one run
// bought) never flashes as "Run 1 of 0" before the first state response.
const runIndex = ref( 1 );
const runTotal = ref( 1 );

// EXPERIMENTAL (see Nera_SAW_Run::submit_all_experimental() docblock): the
// run-wide timer (CMS config, not per-run state) and the XOR seed Start/Resume
// send once, plus the answers accumulated locally and reported in a single
// submit-all call at the end instead of one /answer call per question.
const timerSeconds = ref( 0 );
const obfuscationSeed = ref( 0 );
const experimentalAnswers = [];

const selectedAnswer = ref( null );
const submitting = ref( false ); // answer POST in flight (reveal can't paint until it lands).
const revealCorrectIndex = ref( -1 ); // -1 = nothing to reveal (unusable snapshot).
const revealSecondsLeft = ref( 0 );
const revealIsFinal = ref( false ); // last slot: the hold leads to the results screen.
const advancing = ref( false ); // past the reveal, waiting on the next slot / results.
const ticketsLoading = ref( false );
const ticketsError = ref( '' );
const ticketNumbers = ref( [] );
const showAllTicketNumbers = ref( false ); // client finding: "+N more" did nothing when tapped.
const runsRemainingTotal = ref( null );
const runsRemainingTier = ref( null );
const tierLabel = ref( '' );
const slotResults = ref( [] ); // [{ slot_no, outcome, spins_awarded }] — the results chip row.
const drawDate = ref( '' );
const drawCloses = ref( '' );
const ticketsInDraw = ref( 0 ); // the player's live tickets on this competition after this run.
const drawCap = ref( 0 ); // the draw's ticket cap (paid runs).
const nextRunNo = ref( 0 ); // "Play quiz 2 of 6": 0 when the tier has no run left.
const nextRunTotal = ref( 0 );
const summaryReady = ref( false ); // the run summary has arrived: the draw line and actions use its data.
const shownTickets = ref( 0 ); // the tickets-earned figure as it counts up.
const shownScore = ref( 0 ); // the score figure as it counts up.

const slot = reactive( {
	slot_no: 0,
	level: '',
	// Pre-seeded from the server's pre-run stage preview (see seedPreRunStageCount
	// above) so the language screen has real stage-1 info before
	// any slot has loaded; serve_slot()'s real values overwrite these the moment
	// the run actually starts.
	level_label: ( window.NeraSAW && window.NeraSAW.firstStageLabel ) || '',
	level_text_color: ( window.NeraSAW && window.NeraSAW.firstStageColor ) || '', // ladder colour, already contrast-corrected server-side — used here as a background fill (see .saw-stagebar), which is the same contrast rule read the other way round.
	question: '',
	answers: [], // [ { index, text } ]
	timer_seconds: 0,
	seconds_left: 0,
	reward: 0, // tickets this question is worth if answered correctly.
	// Server-combined "Stage n of total · band · reward" sentence — empty
	// until a real slot loads.
	stage_label: '',
} );

const lastResult = reactive( { correct: false, timed_out: false, spins_awarded: 0, correct_index: -1 } );

// The whole run's content (question, answers, level/stage metadata — never a
// correct-answer flag), fetched once from Start/Resume instead of once per
// question. Keyed by slot_no; loadSlot() applies a slot's entry from here
// before it arms that slot's clock, so switching screens never has to wait on
// a fetch for content the client already holds.
const slotContentCache = new Map();
// The ladder chips: every slot's reward (already times the tier multiplier) and
// its obfuscated key, in slot order. Reactive copy of the cache, which is not.
const ladderSlots = ref( [] );
function cacheSlotContent( slots ) {
	if ( ! Array.isArray( slots ) ) {
		return;
	}
	for ( const entry of slots ) {
		if ( entry && Number.isInteger( entry.slot_no ) ) {
			slotContentCache.set( entry.slot_no, entry );
		}
	}
	ladderSlots.value = [ ...slotContentCache.values() ]
		.sort( ( a, b ) => a.slot_no - b.slot_no )
		.map( ( e ) => ( { slot_no: e.slot_no, reward: Number( e.reward ) || 0, key: e.answer_key } ) );
}

// How many answers experimentalAnswers holds; a plain array is not reactive, so
// every change to it sets this too.
const answeredCount = ref( 0 );

// The chip answered right just before the current question loaded pops once (is-just).
// Set from submit() and handed over when the next slot loads, so a resumed run or a
// reloaded slot never pops.
const justSlot = ref( null );
let pendingJust = null;

// One chip per question: answered right (is-ok), answered wrong or timed out
// (is-no), the current one (is-cur), later ones plain. Built from the answers
// themselves, so a resumed run (answers restored, no per-question results)
// shows the same chips as one played straight through.
const ladderChips = computed( () => {
	const chosen = new Map( experimentalAnswers.slice( 0, answeredCount.value ).map( ( a ) => [ a.slot_no, a.chosen_index ] ) );
	return ladderSlots.value.map( ( s ) => {
		let state = '';
		if ( chosen.has( s.slot_no ) ) {
			const pick = chosen.get( s.slot_no );
			state = -1 !== pick && pick === ( s.key ^ obfuscationSeed.value ) ? 'is-ok' : 'is-no';
		} else if ( s.slot_no === slot.slot_no ) {
			state = 'is-cur';
		}
		if ( 'is-ok' === state && s.slot_no === justSlot.value ) {
			state += ' is-just';
		}
		return { no: s.slot_no, reward: s.reward, state };
	} );
} );

// The progress line under the quiz bar: questions before the current one / total, so it
// steps when the next question loads rather than while the answer is revealed.
const progressPct = computed( () => {
	if ( ! totalSlots.value ) {
		return 0;
	}
	const done = ( 'question' === phase.value || 'reveal' === phase.value ) && slot.slot_no > 0 ? slot.slot_no - 1 : answeredCount.value;
	return Math.min( 100, ( done / totalSlots.value ) * 100 );
} );

let ticker = null;
let holdTicker = null;
// The countdown ring's arc is redrawn every frame between the whole-second
// ticks, so it empties smoothly; ringAnchor is when the last tick landed.
const ringArc = ref( null );
const RING_LENGTH = 163.4; // 2 * PI * r(26)
let ringFrame = 0;
let ringAnchor = 0;
let holdAdvance = null; // closure that advances past the reveal (button or timeout).
// Reactive: the template's disabled states (options, Submit) depend on it, so a
// plain let would only repaint by luck, whenever some other ref happened to change.
const locked = ref( false );
// Tap-through guard (selectOption()): when the current question went on screen, when
// the latest press on the answers began, and when that press lifted (page clock, ms).
let questionShownAt = 0;
let answerPressAt = -1;
let answerLiftAt = -1;
const LIFT_EVENTS = [ 'pointerup', 'pointercancel', 'touchend', 'touchcancel' ];
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
// reload); pagehide (not beforeunload) so a cancelled native prompt never acts
// on a run the player stayed on. Always a deliberate leave now, under either
// policy — a page that is gone has nowhere left to show a Resume prompt to,
// so there is nothing to hold for (see onVisibilityChange()/checkAfterReturn()
// for the interruption this used to also cover: backgrounding the tab, which
// is not the page going away).
function onPageHide() {
	if ( abandonSent || ! runId.value || ! shouldBlockLeave() ) {
		return;
	}
	abandonSent = true;
	api.abandonBeacon( runId.value );
}

/* --- Interruption detection -------------------------------------------------
 * No periodic ping. The server learns a player may have been interrupted only
 * from real events — tab backgrounded then foregrounded again — checked once,
 * on return, rather than guessed at on a timer while nothing may have
 * happened at all. Losing network (`offline`) and a hard crash get no
 * client-side handling of their own; both fall through to the server's own
 * wall-clock sweep (finalize_stale()), exactly as an undetected drop always
 * has. See the grilling session this design came out of for why: any
 * periodic heartbeat short enough to matter against a 10-second question
 * timer stopped being worth its own request volume, and every attempt to
 * detect an interruption from client-held state (localStorage, the client's
 * own clock) can be defeated by a player who simply changes their system
 * clock — only the server's clock is not the player's to set.
 * ------------------------------------------------------------------------- */
const resumePolicy = ( window.NeraSAW && window.NeraSAW.resumePolicy ) || 'resume';

// A small "still here" ping while a run is on screen. The quiz still runs and scores
// in the browser and sends its answers once, at the end; this carries nothing but the
// run id and only lets the server tell a player who is here from one who has gone.
// One request in flight at a time, and never queued behind a slow one.
const heartbeatSeconds = Number( ( window.NeraSAW && window.NeraSAW.heartbeatSeconds ) || 0 );
let pingTimer = null;
let pingInFlight = false;

async function pingOnce() {
	if ( pingInFlight || ! runId.value || ! quizSessionActive ) {
		return;
	}
	pingInFlight = true;
	try {
		const r = await api.heartbeat( runId.value );
		// The server saw a gap (a long network stall, a sleeping laptop): handle it the
		// way a tab regaining focus is handled.
		if ( r && ( r.held || r.errored ) ) {
			pingInFlight = false;
			stopPing();
			await checkAfterReturn();
		}
	} catch ( e ) {
		// A missed ping is not an error: the next one tries again.
	} finally {
		pingInFlight = false;
	}
}

function startPing() {
	if ( pingTimer || heartbeatSeconds < 1 || typeof window === 'undefined' ) {
		return;
	}
	pingTimer = window.setInterval( pingOnce, heartbeatSeconds * 1000 );
}

function stopPing() {
	if ( pingTimer ) {
		window.clearInterval( pingTimer );
		pingTimer = null;
	}
}

// Resume the run and carry on from the screen the player is on: the question keeps the
// seconds it has left and a reveal its hold (onLeaveStay()). Reloading the slot here would
// put an answered question back on screen, or give a live one a fresh full timer.
async function resumeThen() {
	try {
		await api.resumeRun( runId.value );
	} catch ( e ) {
		return fail( e );
	}
	startPing();
}

// Tab regained focus: ask the server, once, whether anything actually
// happened while it was away — never assumed from how long the client thinks
// it was gone. A trivial glance away that never lapsed anything gets no
// prompt at all; only a real, server-confirmed gap does.
async function checkAfterReturn() {
	if ( ! runId.value || ! quizSessionActive ) {
		return;
	}
	try {
		const r = await api.heartbeat( runId.value );
		if ( r && r.errored ) {
			const err = new Error( t( 'runClosed', 'This run was closed after it was interrupted. Please contact support and we will restore it.' ) );
			err.code = 'saw_interrupted';
			fail( err );
			return;
		}
		if ( r && r.held ) {
			const leave = await openLeaveDialog();
			if ( leave ) {
				await proceedLeave( toEnd );
			} else {
				await resumeThen();
			}
		}
		// Neither: nothing lapsed, continue exactly as if nothing happened.
	} catch ( e ) {
		// Best-effort — a failed check just tries again next time focus returns.
	}
}

function onVisibilityChange() {
	if ( typeof document !== 'undefined' && 'visible' === document.visibilityState ) {
		checkAfterReturn();
	}
	// Going hidden: deliberately does nothing — no request at all, which is the
	// whole point of not polling.
}

// The quiz bar's ×: the same Leave dialog; leaving ends the run and shows the results.
function onQuizBarLeave() {
	requestLeavePrompt( toEnd );
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

function drawRing() {
	const el = ringArc.value;
	if ( ! el ) {
		return;
	}
	const total = slot.timer_seconds || 1;
	const into = ticker ? Math.min( 1, ( performance.now() - ringAnchor ) / 1000 ) : 0;
	const left = Math.max( 0, slot.seconds_left - into );
	const share = Math.min( 1, left / total );
	el.setAttribute( 'stroke-dashoffset', ( RING_LENGTH * ( 1 - share ) ).toFixed( 2 ) );
}

function ringLoop() {
	drawRing();
	ringFrame = requestAnimationFrame( ringLoop );
}

// Draws the arc where it stands (frozen on an answer, empty on a timeout).
function stopRing() {
	if ( ringFrame ) {
		cancelAnimationFrame( ringFrame );
		ringFrame = 0;
	}
	drawRing();
}

const timerUrgent = computed( () => {
	if ( phase.value !== 'question' ) {
		return false;
	}
	const left = slot.seconds_left;
	return left > 0 && left <= timerWarnThreshold();
} );

const finalSpins = computed( () =>
	spinsFinal.value !== null && spinsFinal.value !== undefined ? spinsFinal.value : spinsSoFar.value
);

// Client's supplied Russian combines both counts into one sentence
// ("{n} run left for this competition · {n2} run left on {tier}", plural on
// {n} only — see runsRemainingCombined in class-frontend.php). No tier to
// report falls back to the solo English-only line (not in the supplied
// files in that shape).
const runsRemainingLine = computed( () => {
	if ( runsRemainingTotal.value === null ) {
		return '';
	}
	const total = runsRemainingTotal.value;
	if ( runsRemainingTier.value !== null && tierLabel.value ) {
		return fmt(
			'runsRemainingCombined',
			'{n} run left for this competition · {n2} run left on {tier}',
			total,
			{ n: total, n2: runsRemainingTier.value, tier: tierLabel.value }
		);
	}
	return fmt( 'runsRemaining', '%d runs left for this competition', total );
} );

/* --- Results screen (28 / 29) --------------------------------------------
 * Two layouts sharing one card: with tickets, or zero. Which one shows is
 * finalSpins > 0 — the same number the head-bar's TICKETS counter tallied live,
 * not a re-derivation from slotResults, so the two can never disagree.
 * ------------------------------------------------------------------------- */
const correctCount = computed( () => slotResults.value.filter( ( r ) => 'correct' === r.outcome ).length );
const hasTickets = computed( () => finalSpins.value > 0 );

// The headline under the kicker, by how much of the run was right (the scale is
// out of ten whatever the run's length; only a full run is "perfect").
const resultsHeadline = computed( () => {
	const total = totalSlots.value || slotResults.value.length;
	const right = correctCount.value;
	if ( right < 1 ) {
		return t( 'resultHeadNone', 'No correct answers this time.' );
	}
	if ( total > 0 && right >= total ) {
		return t( 'resultHeadPerfect', 'Perfect score!' );
	}
	const tenth = total > 0 ? Math.min( 9, Math.round( right / total * 10 ) ) : 0;
	if ( tenth >= 7 ) {
		return t( 'resultHeadHigh', 'Great score!' );
	}
	if ( tenth >= 4 ) {
		return t( 'resultHeadMid', 'Nice work.' );
	}
	return t( 'resultHeadLow', 'Challenge done.' );
} );

// One chip per question with what it was worth on this tier: right ones filled,
// wrong or missed ones struck.
const resultChips = computed( () =>
	slotResults.value.map( ( r ) => ( {
		key: r.slot_no,
		text: String( r.reward ?? r.spins_awarded ?? 0 ),
		kind: 'correct' === r.outcome ? 'ok' : 'no',
	} ) )
);

// Optional blocks the admin switched on for this competition.
const resultDisplay = Array.isArray( window.NeraSAW && window.NeraSAW.resultDisplay ) ? window.NeraSAW.resultDisplay : [ 'draw_line' ];
const showBlock = ( key ) => resultDisplay.indexOf( key ) !== -1;

const formatCount = ( n ) => Number( n || 0 ).toLocaleString( ( typeof document !== 'undefined' && document.documentElement.lang ) || undefined );
const ticketsWord = ( n ) => fmt( 'ticketsCount', '{n} ticket|{n} tickets', n, { n: formatCount( n ) } );

// The player's chance in a full draw, as "1 in X" (X rounded up).
// Shown only when the theme supplies the texts (window.NeraSAW.resultOdds); its ticketForms
// ("ticket|tickets", Russian one|few|many) puts the noun after {cap} in the form the number needs.
const oddsTexts = ( window.NeraSAW && window.NeraSAW.resultOdds && ! Array.isArray( window.NeraSAW.resultOdds ) ) ? window.NeraSAW.resultOdds : {};
const fillVars = ( s, vars ) => String( s || '' ).replace( /\{(\w+)\}/g, ( m, k ) => ( k in vars ? vars[ k ] : m ) );
const oddsRows = computed( () => {
	const n = ticketsInDraw.value;
	if ( ! oddsTexts.full || n < 1 || drawCap.value < 1 ) {
		return [];
	}
	const oneIn = ( d ) => fillVars( oddsTexts.oneIn || '1 in {x}', { x: formatCount( Math.ceil( d / n ) ) } );
	return [ { key: 'full', label: fillVars( agreeNoun( oddsTexts.full, 'cap', drawCap.value, oddsTexts.ticketForms ), { cap: formatCount( drawCap.value ) } ), value: oneIn( drawCap.value ) } ];
} );

const prefersReducedMotion = () =>
	typeof window !== 'undefined' && window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

/* --- Ticket fly ------------------------------------------------------------
 * On a correct answer a "+n" pill rises from the picked answer and flies into
 * the Tickets counter. The counter keeps the old total while it flies, then
 * shows the new one and pops (is-landed). Without the Web Animations API or
 * with reduced motion the counter updates and pops at once.
 * ------------------------------------------------------------------------- */
const appEl = ref( null );
const tixHeld = ref( null );
const tixLanded = ref( false );
let flyEl = null;
let flyAnim = null;
let landTimer = 0;
const TICKET_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z"/><path d="M10 6v12" stroke-dasharray="2 2"/></svg>';

function landTickets() {
	tixHeld.value = null;
	tixLanded.value = true;
	clearTimeout( landTimer );
	landTimer = setTimeout( () => {
		tixLanded.value = false;
	}, 350 );
}

// Drops a pill still in flight (slot change, run end, unmount) without landing it.
function removeTicketFly() {
	if ( flyAnim ) {
		flyAnim.onfinish = null;
		flyAnim.cancel();
		flyAnim = null;
	}
	if ( flyEl ) {
		flyEl.remove();
		flyEl = null;
	}
	tixHeld.value = null;
}

function flyTicket( option, before, gain ) {
	removeTicketFly();
	const root = appEl.value;
	const pos = Array.isArray( slot.answers ) ? slot.answers.findIndex( ( a ) => a.index === option ) : -1;
	const from = root && pos > -1 ? root.querySelectorAll( '.saw-answer' )[ pos ] : null;
	const to = root ? root.querySelector( '.saw-qmeta__tix b' ) : null;
	if ( ! from || ! to || typeof from.animate !== 'function' || prefersReducedMotion() ) {
		landTickets();
		return;
	}
	tixHeld.value = before;
	const a = from.getBoundingClientRect();
	const b = to.getBoundingClientRect();
	const x0 = a.left + a.width / 2;
	const y0 = a.top + a.height / 2;
	const dx = b.left + b.width / 2 - x0;
	const dy = b.top + b.height / 2 - y0;
	const el = document.createElement( 'div' );
	el.className = 'saw-tkfly';
	el.setAttribute( 'aria-hidden', 'true' );
	el.innerHTML = TICKET_ICON + '<b>+' + Number( gain ) + '</b>';
	el.style.left = x0 + 'px';
	el.style.top = y0 + 'px';
	document.body.appendChild( el );
	flyEl = el;
	const done = () => {
		flyAnim = null;
		removeTicketFly();
		landTickets();
	};
	try {
		flyAnim = el.animate(
			[
				{ transform: 'translate(-50%,-50%) scale(.85)', opacity: 0 },
				{ transform: 'translate(-50%,-90%) scale(1.08)', opacity: 1, offset: 0.28 },
				{ transform: 'translate(calc(-50% + ' + dx + 'px),calc(-50% + ' + dy + 'px)) scale(.55)', opacity: 0.85 },
			],
			{ duration: 620, easing: 'cubic-bezier(.3,.7,.3,1)' }
		);
		flyAnim.onfinish = done;
	} catch ( e ) {
		done();
	}
}

// Score and tickets earned count up from 0 (650ms, ease-out cubic); the final figures at once with reduced motion.
const countFrames = { score: 0, tickets: 0 };
let countDelay = 0;
function stopCountUp() {
	cancelAnimationFrame( countFrames.score );
	cancelAnimationFrame( countFrames.tickets );
	clearTimeout( countDelay );
}
function countUp( key, shown, to, delay ) {
	if ( ! to || prefersReducedMotion() ) {
		shown.value = to || 0;
		return;
	}
	// Set to 0 in the same tick the result renders, so the first figure painted is 0, never a partial total.
	shown.value = 0;
	const run = () => {
		let from = 0;
		const step = ( now ) => {
			from = from || now;
			const p = Math.min( 1, ( now - from ) / 650 );
			shown.value = Math.round( to * ( 1 - Math.pow( 1 - p, 3 ) ) );
			if ( p < 1 ) {
				countFrames[ key ] = requestAnimationFrame( step );
			}
		};
		countFrames[ key ] = requestAnimationFrame( step );
	};
	if ( delay ) {
		countDelay = setTimeout( run, delay );
	} else {
		run();
	}
}
function countUpResult() {
	stopCountUp();
	countUp( 'score', shownScore, correctCount.value, 0 );
	// On the free result the tickets wait until the score card has scaled in.
	countUp( 'tickets', shownTickets, finalSpins.value, isFree ? 450 : 0 );
}

const visibleTicketNumbers = computed( () =>
	showAllTicketNumbers.value ? ticketNumbers.value : ticketNumbers.value.slice( 0, 6 )
);
const extraTicketCount = computed( () => Math.max( 0, ticketNumbers.value.length - 6 ) );
// "+N more" opens the whole list and the same button closes it again ("Show less"):
// it used to be one-way, so a player with a few hundred numbers could not get the
// results card back to its normal height.
function toggleAllTicketNumbers() {
	showAllTicketNumbers.value = ! showAllTicketNumbers.value;
}

function isPlaying() {
	return quizSessionActive && ( phase.value === 'loading' || phase.value === 'question' || phase.value === 'reveal' );
}

function shouldBlockLeave() {
	return isPlaying() && ! leaveBypass;
}

function clearTicker() {
	stopRing();
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
	// A question that times out behind the Leave dialog starts a new reveal; it must wait
	// for Stay too, or every later question would be served and lapse behind the dialog.
	if ( phase.value !== 'reveal' || ! holdAdvance || leaveDialogOpen.value ) {
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
		return fmt( 'revealCorrect', 'Correct. %d ticket earned.|Correct. %d tickets earned.', lastResult.spins_awarded );
	}
	const answer = correctAnswerText.value;
	if ( lastResult.timed_out ) {
		return answer
			? t( 'revealTimeout', 'Time up, no answer counted. The correct answer was: %s' ).replace( '%s', answer )
			: t( 'revealTimeoutBare', 'Time up. No answer counted.' );
	}
	return answer
		? t( 'revealWrong', 'Wrong. The correct answer was: %s' ).replace( '%s', answer )
		: t( 'revealWrongBare', 'Wrong. No tickets.' );
} );

// The line under the question card once an answer is scored.
const feedbackLine = computed( () => {
	if ( phase.value !== 'reveal' ) {
		return '';
	}
	if ( lastResult.correct ) {
		return fmt( 'feedbackRight', 'Right: +{k} ticket|Right: +{k} tickets', lastResult.spins_awarded );
	}
	return lastResult.timed_out
		? t( 'feedbackTimeout', 'Time’s up: 0 tickets' )
		: t( 'feedbackWrong', 'Wrong: 0 tickets' );
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
	startPing();
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
	document.addEventListener( 'visibilitychange', onVisibilityChange );
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
	document.removeEventListener( 'visibilitychange', onVisibilityChange );
}

function releaseNavigationGuard() {
	// Every teardown path (error, results, confirmed leave) funnels through here,
	// so this is where a pending reveal advance is dropped. Without it a hold that
	// expires after we have abandoned would try to serve the next slot of a run
	// that is already finalized.
	clearHold();
	stopPing();
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

// Share my score: a sheet with a 9:16 story card of this run and the run's
// public link. The link is fetched on the first open and kept; a failed fetch
// is not kept, so reopening tries again.
const shareCardData = ( window.NeraSAW && window.NeraSAW.shareCard ) || {};
const shareBrand = shareCardData.brand || 'Strike & Win';
const shareBrandParts = shareBrand.split( /\s*&\s*/ ).map( ( part ) => part.toUpperCase() );
const shareHost = window.location.host;
const shareOpen = ref( false );
const shareUrl = ref( '' );
const shareLoading = ref( false );
const shareError = ref( false );
const shareNote = ref( '' );
const shareButtonRef = ref( null );
const shareGoRef = ref( null );
const shareCloseRef = ref( null );
const shareCardRef = ref( null );
let shareRequest = null;
let shareImage = null;
const SHARE_IMAGE_NAME = 'strike-and-win-score.png';

const shareTotal = computed( () => totalSlots.value || slotResults.value.length );
const shareText = computed( () => fmt(
	'shareText',
	'I scored {r}/{n} on Strike & Win. Can you beat my score?',
	correctCount.value,
	{ r: correctCount.value, n: shareTotal.value }
) );
const shareTixWord = computed( () => fmt( 'shareTixEarned', 'ticket earned|tickets earned', finalSpins.value ) );
const shareChallenge = computed( () => t( 'shareChallenge', 'Can you beat\nmy score?' ).split( '\n' ) );
const shareCardLabel = computed( () => t( 'shareMy', 'My score' ) + ': ' + correctCount.value + '/' + shareTotal.value + ', ' + ticketsWord( finalSpins.value ) );

function loadShareLink() {
	if ( shareUrl.value || shareRequest || ! runId.value ) {
		return shareRequest || Promise.resolve();
	}
	shareError.value = false;
	shareLoading.value = true;
	shareRequest = api.shareRun( runId.value )
		.then( ( r ) => {
			if ( r && r.url ) {
				shareUrl.value = r.url;
			} else {
				shareError.value = true;
			}
		} )
		.catch( () => {
			shareError.value = true;
		} )
		.finally( () => {
			shareLoading.value = false;
			shareRequest = null;
		} );
	return shareRequest;
}

function onShareKeydown( e ) {
	if ( e.key === 'Escape' ) {
		e.preventDefault();
		closeShareSheet();
	}
}

function openShareSheet() {
	shareNote.value = '';
	shareOpen.value = true;
	document.addEventListener( 'keydown', onShareKeydown );
	nextTick( () => ( shareGoRef.value || shareCloseRef.value )?.focus() );
	loadShareLink().then( () => {
		if ( shareOpen.value && shareUrl.value ) {
			nextTick( () => shareGoRef.value?.focus() );
		}
	} );
	// Made while the sheet is open, so Share can hand it over straight away:
	// share menus refuse a call that comes too long after the tap.
	nextTick( makeShareImage );
}

// The story card as a 1080 x 1920 PNG, drawn from the card in the sheet.
// The card never changes after the run, so one image is kept. Any failure
// (a font or photo that can't be embedded, an old browser) resolves to null
// and Share falls back to text and link.
function makeShareImage() {
	if ( shareImage ) {
		return shareImage;
	}
	const card = shareCardRef.value;
	if ( ! card ) {
		return Promise.resolve( null );
	}
	const photo = card.querySelector( '.saw-sharesheet__photo img' );
	const photoReady = photo && ! photo.complete
		? new Promise( ( done ) => {
			photo.addEventListener( 'load', done, { once: true } );
			photo.addEventListener( 'error', done, { once: true } );
		} )
		: Promise.resolve();
	const fontsReady = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
	shareImage = Promise.all( [ photoReady, fontsReady ] )
		.then( () => shareFontCss().catch( () => '' ) )
		.then( ( fontEmbedCSS ) => toBlob( card, { pixelRatio: 1, canvasWidth: 1080, canvasHeight: 1920, fontEmbedCSS } ) )
		.then( ( blob ) => blob || null )
		.catch( () => null );
	shareImage.then( ( blob ) => {
		if ( ! blob ) {
			shareImage = null;
		}
	} );
	return shareImage;
}

// The @font-face rules of the fonts this page has actually loaded, with their
// files inlined, for the card image. Built here rather than by html-to-image,
// which logs an error for every cross-origin stylesheet (hosted web fonts) it
// can't read, and inlines every subset of a family instead of the ones in use.
const fontKey = ( family, range, style ) => [
	String( family || '' ).replace( /["']/g, '' ).trim().toLowerCase(),
	String( range || 'U+0-10FFFF' ).toUpperCase().replace( /\s/g, '' ).replace( /[0-9A-F?]+/g, ( h ) => h.replace( /^0+(?=.)/, '' ) ),
	String( style || 'normal' ).trim().toLowerCase(),
].join( '|' );

function toDataUrl( url ) {
	return fetch( url )
		.then( ( r ) => ( r.ok ? r.blob() : Promise.reject( new Error( String( r.status ) ) ) ) )
		.then( ( blob ) => new Promise( ( done, fail ) => {
			const reader = new FileReader();
			reader.onload = () => done( reader.result );
			reader.onerror = fail;
			reader.readAsDataURL( blob );
		} ) );
}

async function shareFontCss() {
	const loaded = new Set();
	document.fonts.forEach( ( f ) => {
		if ( 'loaded' === f.status ) {
			loaded.add( fontKey( f.family, f.unicodeRange, f.style ) );
		}
	} );
	const sheets = await Promise.all( Array.from( document.styleSheets ).map( ( sheet ) => {
		const base = sheet.href || document.baseURI;
		try {
			return { base, text: Array.from( sheet.cssRules ).map( ( r ) => r.cssText ).join( '\n' ) };
		} catch ( e ) {
			return sheet.href
				? fetch( sheet.href ).then( ( r ) => ( r.ok ? r.text() : '' ) ).catch( () => '' ).then( ( text ) => ( { base, text } ) )
				: { base, text: '' };
		}
	} ) );
	const pick = ( rule, prop ) => ( rule.match( new RegExp( '(?:^|[;{\\s])' + prop + '\\s*:\\s*([^;}]+)', 'i' ) ) || [] )[ 1 ];
	const rules = [];
	sheets.forEach( ( { base, text } ) => {
		( text.match( /@font-face\s*\{[^}]*\}/gi ) || [] ).forEach( ( rule ) => {
			if ( loaded.has( fontKey( pick( rule, 'font-family' ), pick( rule, 'unicode-range' ), pick( rule, 'font-style' ) ) ) ) {
				rules.push( { base, rule } );
			}
		} );
	} );
	const css = await Promise.all( rules.map( async ( { base, rule } ) => {
		const urls = rule.match( /url\(\s*(['"]?)[^'")]+\1\s*\)/g ) || [];
		let out = rule;
		for ( const u of urls ) {
			const raw = u.replace( /^url\(\s*['"]?|['"]?\s*\)$/g, '' );
			if ( raw.startsWith( 'data:' ) ) {
				continue;
			}
			const data = await toDataUrl( new URL( raw, base ).href ).catch( () => null );
			if ( data ) {
				out = out.replace( u, 'url("' + data + '")' );
			}
		}
		return out;
	} ) );
	return css.join( '\n' );
}

function downloadBlob( blob, name ) {
	const href = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = href;
	link.download = name;
	link.style.display = 'none';
	document.body.appendChild( link );
	link.click();
	link.remove();
	setTimeout( () => URL.revokeObjectURL( href ), 30000 );
}

function closeShareSheet() {
	shareOpen.value = false;
	document.removeEventListener( 'keydown', onShareKeydown );
	nextTick( () => shareButtonRef.value?.focus() );
}

// Copy through a selected textarea inside the sheet, for pages without the
// clipboard API (plain http). Focus goes back where it was.
function copyBySelection( text ) {
	const back = document.activeElement;
	const area = document.createElement( 'textarea' );
	area.value = text;
	area.setAttribute( 'readonly', '' );
	area.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;';
	( document.querySelector( '.saw-sharesheet__panel' ) || document.body ).appendChild( area );
	area.select();
	let ok = false;
	try {
		ok = document.execCommand( 'copy' );
	} catch ( e ) {
		ok = false;
	}
	area.remove();
	back?.focus?.();
	return ok;
}

function copyText( text ) {
	const done = () => {
		shareNote.value = t( 'shareCopied', 'Copied to clipboard' );
	};
	const fallback = () => {
		if ( copyBySelection( text ) ) {
			done();
		} else {
			shareNote.value = t( 'shareCopyFail', 'Copy this: {x}' ).replace( '{x}', text );
		}
	};
	if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
		navigator.clipboard.writeText( text ).then( done, fallback );
		return;
	}
	fallback();
}

// Share: the story card image with the text and link through the phone's share
// menu; text and link only where files can't be shared; with no share menu the
// image is downloaded and the text and link are copied. A cancelled menu is silent.
async function shareGo() {
	const text = shareText.value;
	const url = shareUrl.value;
	const blob = await makeShareImage();
	if ( typeof navigator.share === 'function' ) {
		const file = blob ? new File( [ blob ], SHARE_IMAGE_NAME, { type: 'image/png' } ) : null;
		const withFile = file && typeof navigator.canShare === 'function' && navigator.canShare( { files: [ file ] } );
		try {
			await navigator.share( withFile ? { files: [ file ], title: shareBrand, text, url } : { title: shareBrand, text, url } );
			return;
		} catch ( e ) {
			if ( e && e.name === 'AbortError' ) {
				return;
			}
		}
	} else if ( blob ) {
		downloadBlob( blob, SHARE_IMAGE_NAME );
	}
	copyText( text + ' ' + url );
}

function shareCopy() {
	copyText( shareUrl.value );
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
	const takeover = p === 'loading' || p === 'language' || p === 'question' || p === 'reveal' || p === 'end';
	document.body.classList.toggle( 'saw-quiz-takeover', takeover );
}, { immediate: true } );

function applyState( state ) {
	runId.value = state.run_id || runId.value;
	competitionId.value = state.competition_id || competitionId.value;
	totalSlots.value = state.total_slots || totalSlots.value;
	spinsSoFar.value = state.spins_so_far || 0;
	if ( state.run_index ) {
		runIndex.value = state.run_index;
	}
	if ( state.run_total ) {
		runTotal.value = state.run_total;
	}
	if ( state.tier_key ) {
		tierKey.value = state.tier_key;
	}
	if ( state.spins_final !== null && state.spins_final !== undefined ) {
		spinsFinal.value = state.spins_final;
	}
	// Only Start/Resume send this (the whole run's content, once); every other
	// call to applyState() (answer, etc.) simply has nothing here to cache.
	cacheSlotContent( state.slots );
	if ( state.timer_seconds ) {
		timerSeconds.value = state.timer_seconds;
	}
	if ( state.obfuscation_seed !== undefined && state.obfuscation_seed !== null ) {
		obfuscationSeed.value = state.obfuscation_seed;
	}
}

// The reference design's constant, mirrored from Nera_SAW_Mode::QUIZ_LADDER —
// kept as one literal here rather than threaded through localisation for a
// single string comparison.
const Nera_SAW_QUIZ_LADDER = 'ladder';

async function start() {
	retryAction = start;
	justSlot.value = null;
	pendingJust = null;
	try {
		const state = await api.startRun( props.competitionId, props.tier, props.startToken, chosenLanguage.value );
		applyState( state );
		// The same run as the one this browser was playing: pick the answers up where the
		// player left them. Anything else (a new run, another device, cleared storage) starts
		// from question 1 with nothing answered.
		const saved = breadcrumb.read( props.competitionId );
		const resumeFrom = ( saved && saved.run_id === runId.value && Array.isArray( saved.answers ) && saved.answers.length ) ? saved : null;
		if ( resumeFrom ) {
			experimentalAnswers.splice( 0, experimentalAnswers.length, ...resumeFrom.answers );
			spinsSoFar.value = Number( resumeFrom.spins ) || 0;
		} else {
			experimentalAnswers.splice( 0, experimentalAnswers.length );
			breadcrumb.save( props.competitionId, runId.value );
		}
		answeredCount.value = experimentalAnswers.length;
		if ( state.status === 'finalized' ) {
			quizSessionActive = false;
			return toEnd();
		}
		quizSessionActive = true;
		startPing();
		armNavigationGuard();
		if ( props.leaveNow ) {
			return leaveNowFlow();
		}
		if ( resumeFrom ) {
			// The question the player was on comes back with a full clock and may be answered
			// again: it was never recorded. Questions already answered stay answered.
			const nextSlot = Math.max( 1, Number( resumeFrom.next_slot ) || ( resumeFrom.answers.length + 1 ) );
			return advanceAfterAnswer( buildExperimentalAdvanceState( nextSlot ) );
		}
		await loadSlot( state.next_slot || 1 );
	} catch ( e ) {
		// Expired/invalid Start token: the play session lapsed. Show a friendly
		// notice and reload the Overview to issue a fresh token (no run consumed).
		if ( e && e.code === 'saw_bad_token' ) {
			errorMsg.value = t( 'sessionExpired', 'Your play session expired. Refreshing…' );
			phase.value = 'error';
			if ( typeof window !== 'undefined' ) {
				window.setTimeout( function () { window.location.reload(); }, 1600 );
			}
			return;
		}
		fail( e );
	}
}

// "End run" from the interrupted-run popup: the run is finalized as a deliberate
// leave - earned tickets are minted, the rest of the reserved stock goes back, it
// cannot be restored - and the player lands on the results.
async function leaveNowFlow() {
	abandonSent = true;
	try {
		await api.abandonRun( runId.value );
	} catch ( e ) {
		return fail( e );
	}
	return toEnd();
}

async function loadSlot( slotNo ) {
	clearTicker();
	clearHold();
	retryAction = () => loadSlot( slotNo );
	// Content first, from the cache Start/Resume already filled — never a
	// fetch for this part. Missing only if the cache was somehow never
	// populated (shouldn't happen; Start/Resume always sends it), in which
	// case the fields below simply keep whatever the slot object last held.
	const cached = slotContentCache.get( slotNo );
	if ( cached ) {
		Object.assign( slot, cached );
	}

	// EXPERIMENTAL (see Nera_SAW_Run::submit_all_experimental() docblock): no
	// per-slot fetch any more — the clock is armed from the one run-wide
	// timer_seconds Start/Resume sent, reset to full every time (the accepted
	// tradeoff for not tracking a server-side per-question deadline here).
	// const data = await api.getSlot( runId.value, slotNo );
	// if ( data.status === 'finalized' ) { applyState( data ); return toEnd(); }
	// Object.assign( slot, data );
	slot.slot_no = slotNo;
	slot.timer_seconds = timerSeconds.value;
	slot.seconds_left = timerSeconds.value;
	// The slot's stage, read on every slot so it never shows a stale one.
	stageNo.value = ( cached && cached.stage_no ) || stageNo.value;
	stageCount.value = ( cached && cached.stage_count ) || stageCount.value;

	locked.value = false;
	submitting.value = false;
	advancing.value = false;
	selectedAnswer.value = null;
	revealCorrectIndex.value = -1;
	revealSecondsLeft.value = 0;
	revealIsFinal.value = false;
	removeTicketFly();
	justSlot.value = pendingJust;
	pendingJust = null;

	phase.value = 'question';
	questionShownAt = performance.now();
	startCountdown();
}

function startCountdown() {
	clearTicker();
	ringAnchor = performance.now();
	ticker = setInterval( () => {
		slot.seconds_left -= 1;
		ringAnchor = performance.now();
		if ( slot.seconds_left <= 0 ) {
			clearTicker();
			if ( ! locked.value ) {
				submit( '' );
			}
		}
	}, 1000 );
	ringLoop();
}

// Reference design screen 26: no separate Submit step — picking an option answers
// it immediately, and the reveal (screen 27) is the same layout with colours and a
// banked-line applied, not a new screen. selectedAnswer is kept only for the
// brief "submitting" highlight between click and the server's reply.
function eventTime( e ) {
	// Some engines stamp events with epoch time instead of the page clock.
	return e.timeStamp > 1e12 ? e.timeStamp - performance.timeOrigin : e.timeStamp;
}

// Recorded on the window in the capture phase: during the reveal the answer's spot
// may be covered (the flying ticket) or the answer disabled, and that press must
// still count. Presses elsewhere (Leave, Stay, the page) are not answer presses, so a
// later click with no press of its own (assistive tech) is not judged against them.
function noteAnswerPress( e ) {
	if ( ! ( e.target instanceof Element ) || ! e.target.closest( '.saw-answers' ) ) {
		return;
	}
	answerPressAt = eventTime( e );
	answerLiftAt = -1;
}

function noteAnswerLift( e ) {
	if ( answerPressAt >= 0 && answerLiftAt < 0 ) {
		answerLiftAt = eventTime( e );
	}
}

function selectOption( index, e ) {
	if ( locked.value || submitting.value ) {
		return;
	}
	// Tap-through: the click ends a press whose finger came down before this question
	// was on screen (on the reveal, or on the previous question's answer in the same
	// spot), so the player never saw what it answers. Keyboard clicks (detail 0) and
	// clicks with no recorded press have no press to judge and count. A press that
	// lifted before the question showed is over: this click is not its tap.
	const pressAt = answerPressAt;
	const liftAt = answerLiftAt;
	answerPressAt = -1;
	answerLiftAt = -1;
	const liftedBefore = liftAt >= 0 && liftAt < questionShownAt;
	if ( e && e.detail > 0 && pressAt >= 0 && pressAt < questionShownAt && ! liftedBefore ) {
		return;
	}
	selectedAnswer.value = index;
	submit( index );
}

function advanceAfterAnswer( state ) {
	if ( state.status === 'finalized' || ! state.next_slot ) {
		toEnd();
	} else {
		// No break between levels: the next question follows straight on.
		loadSlot( state.next_slot );
	}
}

// EXPERIMENTAL: same shape advanceAfterAnswer() expects
// from the server's per-answer response, built instead from the slot content
// Start/Resume already cached — nothing here is fetched.
function buildExperimentalAdvanceState( nextSlotNo ) {
	if ( nextSlotNo > totalSlots.value ) {
		return { status: 'finalized', next_slot: null };
	}
	const next = slotContentCache.get( nextSlotNo );
	return {
		status: 'active',
		next_slot: nextSlotNo,
		next_stage: next
			? {
				quiz_method: next.quiz_method,
				is_first_of_stage: next.is_first_of_stage,
				stage_no: next.stage_no,
				stage_count: next.stage_count,
				reward_label: next.stage_reward_label,
				// The stage-break sub-line ("Stage 2 of 4 · Moderate · 2 tickets per correct
				// answer") reads this. The server's own look-ahead carries it; this client-built
				// copy did not, so every stage after the first showed a blank sub-line.
				stage_label: next.stage_label,
				level_label: next.level_label,
				level_text_color: next.level_text_color,
			}
			: null,
	};
}

async function submit( option ) {
	if ( locked.value ) {
		return;
	}
	locked.value = true;
	submitting.value = true;
	clearTicker();
	retryAction = () => submit( option );

	// EXPERIMENTAL (see Nera_SAW_Run::submit_all_experimental() docblock): score
	// locally against the obfuscated answer key already in slotContentCache,
	// accumulate the answer, and only hit the server once — at the very end
	// (fetchRunComplete()'s submit-all call) — instead of one /answer per question.
	// const state = await api.answer( runId.value, slot.slot_no, option );
	// applyState( state );
	const cached = slotContentCache.get( slot.slot_no );
	const correctIndex = cached ? ( cached.answer_key ^ obfuscationSeed.value ) : -1;
	const chosenIndex = ( '' === option ) ? -1 : option;
	const isCorrect = ( -1 !== chosenIndex && chosenIndex === correctIndex );
	const awarded = isCorrect ? ( slot.reward || 0 ) : 0;
	const spinsBefore = spinsSoFar.value || 0;

	pendingJust = isCorrect && ! prefersReducedMotion() ? slot.slot_no : null;
	experimentalAnswers.push( { slot_no: slot.slot_no, chosen_index: chosenIndex } );
	answeredCount.value = experimentalAnswers.length;
	spinsSoFar.value = ( spinsSoFar.value || 0 ) + awarded;
	// Kept in this browser (no request) so Continue your run can restore it.
	breadcrumb.save( props.competitionId, runId.value, {
		answers: experimentalAnswers.slice(),
		spins: spinsSoFar.value,
		next_slot: slot.slot_no + 1,
	} );
	Object.assign( lastResult, {
		correct: isCorrect,
		timed_out: ( '' === option ),
		spins_awarded: awarded,
		correct_index: correctIndex,
	} );

	const state = buildExperimentalAdvanceState( slot.slot_no + 1 );
	submitting.value = false;
	if ( ! isAnswerFeedbackEnabled() ) {
		return advanceAfterAnswer( state );
	}
	revealCorrectIndex.value = correctIndex;
	revealIsFinal.value = ( 'finalized' === state.status || ! state.next_slot );
	phase.value = 'reveal';
	startHold( state );
	if ( awarded > 0 && -1 !== chosenIndex ) {
		flyTicket( chosenIndex, spinsBefore, awarded );
	}
}

async function fetchRunComplete() {
	if ( ! runId.value ) {
		return;
	}
	ticketsLoading.value = true;
	ticketsError.value = '';
	summaryReady.value = false;
	stopCountUp();
	try {
		// EXPERIMENTAL: one call carrying every locally-accumulated answer,
		// instead of a separate /answer per question plus this /complete call.
		// const summary = await api.completeRun( runId.value );
		const summary = await api.submitAll( runId.value, experimentalAnswers );
		if ( summary.spins_final !== null && summary.spins_final !== undefined ) {
			spinsFinal.value = summary.spins_final;
		}
		ticketNumbers.value = Array.isArray( summary.ticket_numbers ) ? summary.ticket_numbers : [];
		showAllTicketNumbers.value = false;
		runsRemainingTotal.value = summary.runs_remaining_total ?? 0;
		runsRemainingTier.value = summary.runs_remaining_tier ?? 0;
		tierLabel.value = summary.tier_label || summary.tier_key || '';
		slotResults.value = Array.isArray( summary.slot_results ) ? summary.slot_results : [];
		drawDate.value = summary.draw_date || '';
		drawCloses.value = summary.draw_closes || '';
		ticketsInDraw.value = Number( summary.tickets_in_draw ) || 0;
		drawCap.value = Number( summary.cap ) || 0;
		nextRunNo.value = Number( summary.next_run_no ) || 0;
		nextRunTotal.value = Number( summary.next_run_total ) || 0;
		summaryReady.value = true;
		countUpResult();
	} catch ( e ) {
		ticketsError.value = e && e.message ? e.message : t( 'ticketsMintError', 'We could not add your tickets right now.' );
	} finally {
		ticketsLoading.value = false;
	}
}

async function toEnd() {
	clearTicker();
	removeTicketFly();
	quizSessionActive = false;
	breadcrumb.clear( props.competitionId );
	releaseNavigationGuard();
	phase.value = 'end';
	await fetchRunComplete();
}

const competitionUrl = computed( () => ( competitionId.value ? `/?p=${ competitionId.value }` : '#' ) );
const competitionsListUrl = ( window.NeraSAW && window.NeraSAW.competitionsUrl ) || '';
const competitionPageUrl = ( window.NeraSAW && window.NeraSAW.competitionPermalink ) || '';
const myTicketsUrl = ( window.NeraSAW && window.NeraSAW.myTicketsUrl ) || '';
const responsiblePlayUrl = ( window.NeraSAW && window.NeraSAW.responsiblePlayUrl ) || '';
// Free competition: its own result layout (score, share, follow links, end links).
const isFree = !! competition.is_free;
const social = ( window.NeraSAW && window.NeraSAW.social ) || {};
const homeUrl = ( window.NeraSAW && window.NeraSAW.homeUrl ) || '/';
// Free result: bonus challenge card (invite friends with a personal link).
const referral = ( window.NeraSAW && window.NeraSAW.referral ) || {};
const inviteOn = isFree && !! Number( referral.enabled ) && !! referral.link;
const inviteCap = Math.max( 1, Number( referral.cap ) || 5 );
const inviteDone = Math.min( inviteCap, Math.max( 0, Number( referral.rewarded ) || 0 ) );
const inviteLeft = inviteCap - inviteDone;
const inviteLink = referral.link || '';
const inviteCanShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';
const inviteOpen = ref( false );
const inviteCopied = ref( false );
let inviteCopiedTimer = 0;
const inviteLeftText = computed( () => {
	if ( 0 === inviteLeft ) {
		return t( 'invLeft0', 'All bonus tickets earned' );
	}
	if ( 1 === inviteLeft ) {
		return t( 'invLeft1', '1 bonus ticket still available' );
	}
	return fmt( 'invLeft', '{n} bonus tickets still available', inviteLeft, { n: inviteLeft } );
} );
const inviteText = computed( () => fmt(
	'inviteText',
	'I scored {r}/10 on the free Strike & Win quiz. Can you beat my score?',
	correctCount.value,
	{ r: correctCount.value }
) );
const inviteWhatsApp = computed( () => 'https://wa.me/?text=' + encodeURIComponent( inviteText.value + ' ' + inviteLink ) );
const inviteTelegram = computed( () => 'https://t.me/share/url?url=' + encodeURIComponent( inviteLink ) + '&text=' + encodeURIComponent( inviteText.value ) );

function inviteShare() {
	navigator.share( { text: inviteText.value, url: inviteLink } ).catch( () => {} );
}

function inviteCopy() {
	const done = () => {
		inviteCopied.value = true;
		clearTimeout( inviteCopiedTimer );
		inviteCopiedTimer = setTimeout( () => {
			inviteCopied.value = false;
		}, 2500 );
	};
	const fallback = () => {
		if ( copyBySelection( inviteLink ) ) {
			done();
		}
	};
	if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
		navigator.clipboard.writeText( inviteLink ).then( done, fallback );
		return;
	}
	fallback();
}
const freeDrawLine = computed( () => fmt( 'freeDrawLine', 'Entered in the prize draw · Live draw: {d}', drawDate.value || t( 'drawDateTbc', 'date to be confirmed' ) ) );
const hasNextRun = computed( () => ( runsRemainingTier.value || 0 ) > 0 && nextRunNo.value > 0 );
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
	window.addEventListener( 'pointerdown', noteAnswerPress, true );
	window.addEventListener( 'touchstart', noteAnswerPress, { capture: true, passive: true } );
	for ( const type of LIFT_EVENTS ) {
		window.addEventListener( type, noteAnswerLift, { capture: true, passive: true } );
	}
	if ( hasLanguageChoice && ! props.resume && ! props.leaveNow ) {
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
	window.removeEventListener( 'pointerdown', noteAnswerPress, true );
	window.removeEventListener( 'touchstart', noteAnswerPress, true );
	for ( const type of LIFT_EVENTS ) {
		window.removeEventListener( type, noteAnswerLift, true );
	}
	clearTicker();
	stopCountUp();
	removeTicketFly();
	clearTimeout( landTimer );
	releaseNavigationGuard();
	if ( typeof document !== 'undefined' ) {
		document.body.classList.remove( 'saw-quiz-dialog-open' );
		document.body.classList.remove( 'saw-quiz-takeover' );
	}
} );
</script>

<template>
	<div ref="appEl" class="saw-app">
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
			v-else-if="phase === 'language' || phase === 'question' || phase === 'reveal'"
			class="saw-play"
		>
			<!-- The quiz bar: competition, "Quiz n of N · tier" and leave. The language
			     card comes before the run exists, so it shows the name alone. -->
			<div class="saw-qbar">
				<div class="saw-qbar__wrap">
					<div class="saw-qbar__t">{{ competition.name }}<span v-if="phase !== 'language'">{{ quizOfLabel }}</span></div>
					<button
						v-if="phase !== 'language'"
						type="button"
						class="saw-qbar__x"
						:aria-label="t('leaveLabel', 'Leave')"
						@click="onQuizBarLeave"
					><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
				</div>
			</div>
			<div v-if="phase !== 'language'" class="saw-qprog" aria-hidden="true"><i :style="{ width: progressPct + '%' }"></i></div>

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

			<div v-else class="saw-qmain" :class="{ 'is-revealing': phase === 'reveal' }">
				<div class="saw-qlad" aria-hidden="true"><i v-for="c in ladderChips" :key="c.no" :class="c.state">{{ c.reward }}</i></div>

				<div class="saw-qmeta">
					<div class="saw-qmeta__level">{{ fmt('questionOf', 'Question {n} of {total}', slot.slot_no, totalSlots) }}<template v-if="slot.level_label"> · {{ slot.level_label }}</template><span>{{ fmt('worthTicket', 'Worth {k} ticket|Worth {k} tickets', slot.reward) }}</span></div>
					<div class="saw-qmeta__tix" :class="{ 'is-landed': tixLanded }"><small>{{ t('ticketsLabel', 'Tickets') }}</small><b>{{ tixHeld !== null ? tixHeld : spinsSoFar }}</b></div>
				</div>

				<div v-if="competition.name" class="saw-qprize" aria-hidden="true">
					<div class="saw-qprize__photo"><img v-if="competition.photo" :src="competition.photo" alt=""></div>
					<div><small>{{ t('playingFor', 'Playing for') }}</small><b v-if="competition.value">{{ competition.value }}</b><span>{{ competition.name }}</span></div>
				</div>

				<div :key="slot.slot_no" class="saw-qcard">
					<div class="saw-qtop">
						<h1 class="saw-question">{{ slot.question }}</h1>
						<div
							class="saw-qtimer"
							:class="{ 'is-urgent': timerUrgent, 'is-spent': phase === 'reveal' }"
						>
							<svg viewBox="0 0 62 62" width="62" height="62" aria-hidden="true">
								<circle class="saw-qtimer__track" cx="31" cy="31" r="26"></circle>
								<circle ref="ringArc" class="saw-qtimer__fill" cx="31" cy="31" r="26" stroke-dashoffset="0" transform="rotate(-90 31 31)"></circle>
							</svg>
							<span class="saw-qtimer__num">{{ Math.max(0, slot.seconds_left) }}</span>
						</div>
					</div>
					<p class="saw-sr-only" role="status" aria-live="polite">{{ revealAnnouncement }}</p>

					<div class="saw-answers">
						<button
							v-for="(a, i) in slot.answers"
							:key="a.index"
							type="button"
							class="saw-answer"
							:class="optionClass(a)"
							:disabled="locked || submitting"
							:aria-pressed="selectedAnswer === a.index"
							@click="selectOption(a.index, $event)"
						><em aria-hidden="true">{{ 'ABCDEFGHIJ'.charAt(i) }}</em>{{ a.text }}</button>
					</div>
				</div>

				<div
					class="saw-qfb"
					:class="{ 'is-good': phase === 'reveal' && lastResult.correct, 'is-bad': phase === 'reveal' && !lastResult.correct }"
					aria-hidden="true"
				>{{ feedbackLine }}</div>
				<button
					v-if="phase === 'reveal' && showNextButton"
					type="button"
					class="saw-banked__next"
					:disabled="advancing"
					@click="endHold"
				>{{ revealIsFinal
					? t('seeResults', 'See my results') + ' (' + Math.max(0, revealSecondsLeft) + ')'
					: fmt('nextQuestion', 'Next question ({s})', Math.max(0, revealSecondsLeft)) }}</button>
			</div>
		</div>

		<div v-else-if="phase === 'end'" class="saw-results" :class="{ 'is-free': isFree }">
			<template v-if="isFree">
				<section class="saw-fres__win">
					<p class="saw-fres__kicker">{{ t('resultKicker', 'Challenge completed') }}</p>
					<template v-if="summaryReady">
					<div class="saw-fres__score"><b aria-hidden="true">{{ shownScore }}</b><span aria-hidden="true">/{{ shareTotal }}</span><span class="saw-sr-only">{{ t('freeScore', 'Your score') + ': ' + correctCount + '/' + shareTotal }}</span></div>
					<p class="saw-fres__verdict">{{ resultsHeadline }}</p>
					<div class="saw-fres__dots" aria-hidden="true">
						<i v-for="( r, k ) in slotResults" :key="r.slot_no" :class="{ 'is-ok': 'correct' === r.outcome }" :style="{ '--d': k }"></i>
					</div>
					<p v-if="! hasTickets" class="saw-fres__none">{{ t('freeNone', 'No quiz tickets this time, so no draw entries were added.') }}</p>
					<div v-else class="saw-fres__tix">
						<div class="saw-fres__tixl"><small>{{ t('freeTickets', 'Tickets earned') }}</small><b aria-hidden="true"><span>+</span><span>{{ formatCount( shownTickets ) }}</span></b><span class="saw-sr-only">+{{ formatCount( finalSpins ) }}</span></div>
						<div class="saw-fres__added"><span class="saw-fres__ck" aria-hidden="true"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10" /></svg></span>{{ t('freeAdded', 'Added to your account') }}</div>
					</div>
					</template>
					<p v-if="summaryReady" class="saw-fres__draw">{{ freeDrawLine }}</p>
				</section>

				<div v-if="ticketsLoading" class="saw-minting" role="status" aria-live="polite">
					<span class="saw-spinner" aria-hidden="true"></span>
					<span>{{ t('mintingTickets', 'Adding your tickets to the draw…') }}</span>
				</div>
				<div v-else-if="ticketsError" class="saw-mint-error">
					<p>{{ ticketsError }}</p>
					<button type="button" class="saw-btn saw-btn-ghost" @click="fetchRunComplete">{{ t('retryTickets', 'Try again') }}</button>
				</div>

				<section v-if="summaryReady && runId" class="saw-fres__share">
					<h2 class="saw-fres__h">{{ t('freeShareTitle', 'Think your friends can beat your score?') }}</h2>
					<p class="saw-fres__sub">{{ t('freeShareSub', 'Challenge them. Your score link invites them to play free.') }}</p>
					<span class="saw-fres__gowrap"><button type="button" class="saw-fres__go" ref="shareButtonRef" aria-haspopup="dialog" @click="openShareSheet"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v13M7 8l5-5 5 5" /><path d="M5 14v5h14v-5" /></svg>{{ t('shareMyScore', 'Share my score') }}</button></span>
					<button type="button" class="saw-fres__prev" aria-haspopup="dialog" @click="openShareSheet">
						<span class="saw-fres__thumb" aria-hidden="true">
							<span class="saw-sharesheet__card">
								<div class="saw-sharesheet__glow" aria-hidden="true"></div>
								<span class="saw-sharesheet__amp" aria-hidden="true">&amp;</span>
								<div class="saw-sharesheet__in" aria-hidden="true">
									<div class="saw-sharesheet__top">
										<img v-if="shareCardData.logo" class="saw-sharesheet__logo" :src="shareCardData.logo" :alt="shareBrand">
										<span v-else class="saw-sharesheet__logo"><template v-for="( part, i ) in shareBrandParts" :key="i"><span v-if="i" class="saw-sharesheet__logo-amp">&amp;</span><span v-if="part">{{ part }}</span></template></span>
										<span class="saw-sharesheet__kicker">{{ isFree ? t('shareFreeKicker', 'Free quiz') : t('shareKicker', 'Prize quiz') }}</span>
									</div>
									<div class="saw-sharesheet__mid">
										<small class="saw-sharesheet__label">{{ t('shareMy', 'My score') }}</small>
										<div class="saw-sharesheet__score">{{ correctCount }}<span>/{{ shareTotal }}</span></div>
										<div class="saw-sharesheet__bars">
											<i v-for="r in slotResults" :key="r.slot_no" class="saw-sharesheet__bar" :class="{ 'is-correct': 'correct' === r.outcome }"></i>
										</div>
										<div class="saw-sharesheet__tix"><b>+{{ formatCount( finalSpins ) }}</b> {{ shareTixWord }}</div>
									</div>
									<div class="saw-sharesheet__prize">
										<div class="saw-sharesheet__photo"><img v-if="competition.photo" :src="competition.photo" alt=""></div>
										<div>
											<small>{{ isFree ? t('shareFreeFor', 'Free competition') : t('sharePlayingFor', 'Playing for') }}</small>
											<b>{{ competition.name }}</b>
											<span v-if="! isFree && competition.value">{{ competition.value }}</span>
										</div>
									</div>
									<div class="saw-sharesheet__ch"><template v-for="( line, i ) in shareChallenge" :key="i"><br v-if="i">{{ line }}</template></div>
									<div class="saw-sharesheet__foot">
										<div><span>{{ t('sharePlayFree', 'Play free at') }}</span><b>{{ shareHost }}</b></div>
										<em>{{ t('shareAge', '18+ · Skill-based prize competitions') }}</em>
									</div>
								</div>
							</span>
						</span>
						<span class="saw-fres__ptx"><b>{{ t('freePreview', 'Preview your story') }}</b><small>{{ t('freePreviewSub', 'Full 9:16 card for Stories and chats') }}</small></span>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
					</button>
				</section>

				<section v-if="summaryReady && inviteOn" class="saw-invite" aria-labelledby="saw-invite-title">
					<span class="saw-invite__badge"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z" /><path d="M10 6v12" stroke-dasharray="2 2" /></svg>{{ t('invKicker', 'Bonus challenge') }}</span>
					<h2 id="saw-invite-title" class="saw-invite__title">{{ fmt('invTitle', 'Invite {n} friends. Earn up to {n} extra tickets.', inviteCap, { n: inviteCap }) }}</h2>
					<p class="saw-invite__sub">{{ t('invSub', 'And your friends get a bonus ticket too.') }}</p>
					<div class="saw-invite__tiles">
						<div class="saw-invite__tile"><small>{{ t('invYou', 'You get') }}</small><b>+1</b><span>{{ t('invUnit', 'Bonus ticket') }}</span></div>
						<div class="saw-invite__tile"><small>{{ t('invFriend', 'Your friend gets') }}</small><b>+1</b><span>{{ t('invUnit', 'Bonus ticket') }}</span></div>
					</div>
					<div class="saw-invite__prog">
						<div class="saw-invite__pl"><b>{{ fmt('invProg', '{x} / {m} friends rewarded', inviteDone, { x: inviteDone, m: inviteCap }) }}</b><span>{{ inviteLeftText }}</span></div>
						<div class="saw-invite__slots" aria-hidden="true">
							<div v-for="i in inviteCap" :key="i" class="saw-invite__slot" :class="{ 'is-on': i <= inviteDone }" :title="fmt('invSlot', 'Friend {n}', i, { n: i })"><b>+1</b><small>{{ i }}</small></div>
						</div>
					</div>
					<p class="saw-invite__how">{{ t('invHow', 'Your friend joins, verifies their email and completes the free quiz. You both receive a bonus ticket once eligible.') }}</p>
					<span class="saw-invite__gowrap"><button type="button" class="saw-invite__go" :aria-expanded="inviteOpen ? 'true' : 'false'" aria-controls="saw-invite-opts" @click="inviteOpen = ! inviteOpen">{{ t('invGo', 'Invite friends now') }}<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg></button></span>
					<div v-if="inviteOpen" id="saw-invite-opts" class="saw-invite__opts">
						<span class="saw-invite__label">{{ t('invLink', 'Your personal link') }}</span>
						<span class="saw-invite__link">{{ inviteLink.replace( /^https?:\/\//, '' ) }}</span>
						<div class="saw-invite__grid">
							<button v-if="inviteCanShare" type="button" class="saw-invite__btn" @click="inviteShare"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v13M7 8l5-5 5 5" /><path d="M5 14v5h14v-5" /></svg>{{ t('invShare', 'Share') }}</button>
							<a class="saw-invite__btn" :href="inviteWhatsApp" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 0 1-11.9 7L4 20l1.1-4A8 8 0 1 1 20 12z" /></svg>WhatsApp</a>
							<a class="saw-invite__btn" :href="inviteTelegram" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.5 3.5L2.5 10.8l6.2 2.3 2.4 6.4 3.4-4.2 5.3 3.9z" /></svg>Telegram</a>
							<button type="button" class="saw-invite__btn" @click="inviteCopy"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" /><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" /></svg>{{ inviteCopied ? t('invCopied', 'Copied') : t('invCopy', 'Copy link') }}</button>
						</div>
						<p class="saw-sr-only" aria-live="polite">{{ inviteCopied ? t('invCopied', 'Copied') : '' }}</p>
					</div>
				</section>

				<section v-if="social.instagram || social.telegram" class="saw-fres__follow">
					<h2 class="saw-fres__h">{{ t('freeFollowTitle', 'More free prizes are coming.') }}</h2>
					<p class="saw-fres__sub">{{ t('freeFollowSub', 'Follow us so you don’t miss the next free competition.') }}</p>
					<div class="saw-fres__fbtns">
						<a v-if="social.instagram" class="saw-fres__fbtn" :href="social.instagram" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /></svg>{{ t('followInstagram', 'Follow on Instagram') }}</a>
						<a v-if="social.telegram" class="saw-fres__fbtn" :href="social.telegram" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.5 3.5L2.5 10.8l6.2 2.3 2.4 6.4 3.4-4.2 5.3 3.9z" /></svg>{{ t('followTelegram', 'Join our Telegram channel') }}</a>
					</div>
				</section>

				<div class="saw-fres__end">
					<a class="saw-fres__link" :href="myTicketsUrl || competitionsListUrl">{{ t('myTickets', 'My tickets') }}</a>
					<a class="saw-fres__link" :href="homeUrl">{{ t('backToHome', 'Back to home') }}</a>
				</div>
			</template>
			<template v-else>
			<p class="saw-results__kicker">{{ t('resultKicker', 'Challenge completed') }}</p>
			<p v-if="summaryReady" class="saw-results__headline">{{ resultsHeadline }}</p>
			<p v-if="competition.name" class="saw-results__name">{{ competition.name }}<span v-if="tierLabel" class="saw-results__tier"> · {{ tierLabel }}</span></p>

			<template v-if="summaryReady">
			<div class="saw-results__stats">
				<div class="saw-results__stat">
					<small>{{ t('scoreLabel', 'Score') }}</small>
					<b aria-hidden="true">{{ shownScore }}<i>/{{ totalSlots || slotResults.length }}</i></b>
					<span class="saw-sr-only">{{ correctCount }}/{{ totalSlots || slotResults.length }}</span>
					<span>{{ t('correctAnswers', 'correct answers') }}</span>
				</div>
				<div class="saw-results__stat saw-results__stat--tickets" :class="{ 'is-zero': !hasTickets }">
					<small>{{ t('ticketsEarnedLabel', 'Tickets earned') }}</small>
					<b aria-hidden="true">{{ hasTickets ? '+' + formatCount( shownTickets ) : '0' }}</b>
					<span class="saw-sr-only">{{ hasTickets ? '+' + formatCount( finalSpins ) : '0' }}</span>
					<span>{{ t('inThisDraw', 'in this draw') }}</span>
				</div>
			</div>

			<div v-if="resultChips.length" class="saw-qlad saw-results__chips" aria-hidden="true">
				<i v-for="c in resultChips" :key="c.key" :class="'is-' + c.kind">{{ c.text }}</i>
			</div>
			</template>

			<div v-if="ticketsLoading" class="saw-minting" role="status" aria-live="polite">
				<span class="saw-spinner" aria-hidden="true"></span>
				<span>{{ t('mintingTickets', 'Adding your tickets to the draw…') }}</span>
			</div>
			<div v-else-if="ticketsError" class="saw-mint-error">
				<p>{{ ticketsError }}</p>
				<button type="button" class="saw-btn saw-btn-ghost" @click="fetchRunComplete">{{ t('retryTickets', 'Try again') }}</button>
			</div>
			<template v-else-if="summaryReady">
				<div v-if="hasTickets" class="saw-results__draw">
					<div>{{ t('ticketsInDraw', 'Your tickets in this draw') }}: <b>{{ ticketsWord( ticketsInDraw ) }}</b></div>
					<template v-for="row in oddsRows" :key="row.key"><div class="saw-results__oddsk">{{ row.label }}</div><b class="saw-results__oddsv">{{ row.value }}</b></template><span v-if="oddsRows.length && oddsTexts.note" class="saw-results__oddsnote">{{ oddsTexts.note }}</span>
				</div>
				<div v-else class="saw-results__draw is-neutral">{{ t('noTicketsThisQuiz', 'No tickets from this quiz.') }}<div v-if="ticketsInDraw > 0" class="saw-results__drawsub">{{ t('ticketsInDraw', 'Your tickets in this draw') }}: <b>{{ ticketsWord( ticketsInDraw ) }}</b></div><template v-for="row in oddsRows" :key="row.key"><div class="saw-results__oddsk">{{ row.label }}</div><b class="saw-results__oddsv">{{ row.value }}</b></template><span v-if="oddsRows.length && oddsTexts.note" class="saw-results__oddsnote">{{ oddsTexts.note }}</span></div>
			</template>

			<p v-if="summaryReady && showBlock('draw_line')" class="saw-results__drawline">{{ fmt('resultDrawLine', 'Your tickets are entered in this prize draw · Live draw: {d}', drawDate || t('drawDateTbc', 'date to be confirmed')) }}</p>

			<div v-if="showBlock('entry_numbers') && hasTickets && ticketNumbers.length" class="saw-results__entries">
				<p class="saw-results__label">{{ t('yourEntryNumbers', 'Your entry numbers') }}</p>
				<div class="saw-results__numbers">
					<span v-for="num in visibleTicketNumbers" :key="num" class="saw-numchip">{{ num }}</span>
					<button
						v-if="extraTicketCount > 0"
						type="button"
						class="saw-numchip saw-numchip--more"
						:aria-expanded="showAllTicketNumbers"
						@click="toggleAllTicketNumbers"
					>{{ showAllTicketNumbers ? t('showLess', 'Show less') : fmt('moreNumbers', '+{n} more', extraTicketCount) }}</button>
				</div>
				<p class="saw-results__note">{{ t('numbersPoolNote', "Numbers are issued in order as tickets are earned. The draw itself is random and independently witnessed.") }}</p>
			</div>

			<div v-if="showBlock('fine_print')" class="saw-results__fine">
				<p v-if="hasTickets" class="saw-results__fineprint">
					<template v-if="drawDate">{{ fmt('drawDatePrefix', 'Draw: %s', drawDate) }}. </template>{{ t('drawInfoWithEntry', "Random draw, independently witnessed. You'll be notified either way.") }}
				</p>
				<template v-else>
					<p class="saw-results__fineprint">{{ t('noEntryNote', 'No tickets were earned, so there is no entry in this draw, and no refund is due.') }}</p>
					<p v-if="drawCloses" class="saw-results__fineprint saw-results__fineprint--muted">{{ fmt('drawClosesPrefix', 'The draw closes %s.', drawCloses) }}</p>
				</template>
			</div>

			<p v-if="showBlock('runs_left') && runsRemainingLine" class="saw-runs-left">{{ runsRemainingLine }}</p>

			<div v-if="summaryReady" class="saw-results__actions">
				<span v-if="hasNextRun" class="saw-results__ctawrap">
					<a class="saw-results__primary" :href="playAgainUrl">{{ fmt('playQuizOf', 'Play quiz {i} of {n}', nextRunNo, nextRunTotal) }}<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg></a>
				</span>
				<a
					class="saw-results__secondary"
					:class="{ 'is-primary': !hasNextRun }"
					:href="myTicketsUrl || competitionsListUrl"
				>{{ t('myTickets', 'My tickets') }}</a>
				<button
					v-if="runId"
					type="button"
					class="saw-results__share saw-share-btn"
					ref="shareButtonRef"
					aria-haspopup="dialog"
					@click="openShareSheet"
				><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v13M7 8l5-5 5 5" /><path d="M5 14v5h14v-5" /></svg>{{ t('shareMyScore', 'Share my score') }}</button>
				<a
					v-if="! hasTickets && responsiblePlayUrl"
					class="saw-results__back"
					:href="responsiblePlayUrl"
				>{{ t('responsiblePlay', 'Responsible play') }}</a>
				<a
					v-else
					class="saw-results__back"
					:href="competitionPageUrl || competitionUrl"
				>{{ t('backToCompetition', 'Back to the competition') }}</a>
			</div>
			</template>
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

	<Teleport to="body">
		<div
			v-if="shareOpen"
			class="saw-sharesheet"
			role="dialog"
			aria-modal="true"
			aria-labelledby="saw-sharesheet-title"
		>
			<button type="button" class="saw-sharesheet__backdrop" tabindex="-1" :aria-label="t('shareClose', 'Close')" @click="closeShareSheet"></button>
			<div class="saw-sharesheet__panel">
				<div class="saw-sharesheet__head">
					<div>
						<h2 id="saw-sharesheet-title" class="saw-sharesheet__title">{{ t('shareMyScore', 'Share my score') }}</h2>
						<span class="saw-sharesheet__sub">{{ t('shareStory', 'Story format · 9:16') }}</span>
					</div>
					<button type="button" class="saw-sharesheet__close" ref="shareCloseRef" :aria-label="t('shareClose', 'Close')" @click="closeShareSheet"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
				</div>
				<div class="saw-sharesheet__prev">
					<div class="saw-sharesheet__card" ref="shareCardRef" role="img" :aria-label="shareCardLabel">
						<div class="saw-sharesheet__glow" aria-hidden="true"></div>
						<span class="saw-sharesheet__amp" aria-hidden="true">&amp;</span>
						<div class="saw-sharesheet__in" aria-hidden="true">
							<div class="saw-sharesheet__top">
								<img v-if="shareCardData.logo" class="saw-sharesheet__logo" :src="shareCardData.logo" :alt="shareBrand">
								<span v-else class="saw-sharesheet__logo"><template v-for="( part, i ) in shareBrandParts" :key="i"><span v-if="i" class="saw-sharesheet__logo-amp">&amp;</span><span v-if="part">{{ part }}</span></template></span>
								<span class="saw-sharesheet__kicker">{{ isFree ? t('shareFreeKicker', 'Free quiz') : t('shareKicker', 'Prize quiz') }}</span>
							</div>
							<div class="saw-sharesheet__mid">
								<small class="saw-sharesheet__label">{{ t('shareMy', 'My score') }}</small>
								<div class="saw-sharesheet__score">{{ correctCount }}<span>/{{ shareTotal }}</span></div>
								<div class="saw-sharesheet__bars">
									<i v-for="r in slotResults" :key="r.slot_no" class="saw-sharesheet__bar" :class="{ 'is-correct': 'correct' === r.outcome }"></i>
								</div>
								<div class="saw-sharesheet__tix"><b>+{{ formatCount( finalSpins ) }}</b> {{ shareTixWord }}</div>
							</div>
							<div class="saw-sharesheet__prize">
								<div class="saw-sharesheet__photo"><img v-if="competition.photo" :src="competition.photo" alt=""></div>
								<div>
									<small>{{ isFree ? t('shareFreeFor', 'Free competition') : t('sharePlayingFor', 'Playing for') }}</small>
									<b>{{ competition.name }}</b>
									<span v-if="! isFree && competition.value">{{ competition.value }}</span>
								</div>
							</div>
							<div class="saw-sharesheet__ch"><template v-for="( line, i ) in shareChallenge" :key="i"><br v-if="i">{{ line }}</template></div>
							<div class="saw-sharesheet__foot">
								<div><span>{{ t('sharePlayFree', 'Play free at') }}</span><b>{{ shareHost }}</b></div>
								<em>{{ t('shareAge', '18+ · Skill-based prize competitions') }}</em>
							</div>
						</div>
					</div>
				</div>
				<p v-if="shareError" class="saw-sharesheet__error" role="alert">{{ t('shareError', "We couldn't make your link. Close this and try again.") }}</p>
				<div v-else-if="shareUrl" class="saw-sharesheet__acts">
					<span class="saw-sharesheet__gowrap">
						<button type="button" class="saw-sharesheet__go" ref="shareGoRef" @click="shareGo"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v13M7 8l5-5 5 5" /><path d="M5 14v5h14v-5" /></svg>{{ t('shareGo', 'Share') }}</button>
					</span>
					<button type="button" class="saw-sharesheet__copy" @click="shareCopy"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" /><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" /></svg>{{ t('shareCopy', 'Copy link') }}</button>
				</div>
				<p v-if="shareLoading || shareNote" class="saw-sharesheet__status" role="status">{{ shareLoading ? t('shareMaking', 'Making your link…') : shareNote }}</p>
			</div>
		</div>
	</Teleport>
</template>

<style>
.saw-app {
	/* Client finding #30/#32: brand orange fallback, not the starter theme's
	   navy placeholder (--color-primary has never been repointed — a theme
	   fix, out of this plugin's scope; still preferred first so a later
	   theme fix changes nothing here). */
	--saw-brand: var(--color-primary, #eb580c);
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
/* (hover: hover) so a tap on a touchscreen never leaves this stuck on — mobile
   browsers apply :hover on tap and only clear it on the next tap elsewhere. */
@media (hover: hover) and (pointer: fine) {
	.saw-btn-submit:hover:not(:disabled) {
		background: color-mix(in srgb, var(--saw-brand) 92%, #000);
		transform: translateY(-1px);
		box-shadow: 0 8px 20px -10px color-mix(in srgb, var(--saw-brand) 55%, transparent);
	}
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
/* (hover: hover) — see .saw-btn-submit:hover above for why. */
@media (hover: hover) and (pointer: fine) {
	a.saw-btn:hover, button.saw-btn:hover:not(:disabled) {
		color: #fff;
		text-decoration: none;
		background: color-mix(in srgb, var(--saw-brand) 90%, #000);
		transform: translateY(-1px);
	}
}
.saw-btn-ghost { background: transparent; color: var(--saw-brand); border: 2px solid color-mix(in srgb, var(--saw-brand) 35%, transparent); box-shadow: none; }
@media (hover: hover) and (pointer: fine) {
	a.saw-btn.saw-btn-ghost:hover, button.saw-btn.saw-btn-ghost:hover:not(:disabled) { color: var(--saw-brand); background: color-mix(in srgb, var(--saw-brand) 8%, #fff); }
}
.saw-notice { text-align: center; }
.saw-error { color: #dc2626; }
.saw-error__msg { margin: 0 0 14px; font-weight: 600; }
.saw-error__actions { display: flex; justify-content: center; margin-bottom: 12px; }
.saw-error__help { color: var(--saw-text-muted); font-size: 14px; margin: 0; }
.saw-loading { text-align: center; opacity: .7; color: var(--saw-text-muted); }

/* =====================================================================
 * Reference design (strikeawin-elements.pages.dev, screens 24–29): the
 * language pick, the question/reveal, and the two results layouts. Everything below is new; the rules above this point
 * belong to the pre-reference default skin and are left in place rather than
 * pruned, since nothing here still uses their class names.
 * ===================================================================== */

/* The run's screen: the quiz bar across the top, a progress line under it,
   then one centred column (ladder, meta, prize, question card, feedback). The
   language pick shares the bar. Neutral layout only; a theme adds the look. */
.saw-play {
	width: 100%;
	display: flex;
	flex-direction: column;
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
@media (hover: hover) and (pointer: fine) {
	.saw-langcard__btn:hover { border-color: var(--saw-brand); box-shadow: 0 4px 14px -8px color-mix(in srgb, var(--saw-brand) 30%, transparent); }
}

/* ---- quiz bar and progress line --------------------------------------- */
.saw-qbar { border-bottom: 1px solid var(--saw-surface); }
.saw-qbar__wrap {
	box-sizing: border-box; display: flex; align-items: center; justify-content: space-between;
	gap: 12px; min-height: 60px; padding: 0 16px;
}
.saw-qbar__t { min-width: 0; font-size: 13px; font-weight: 800; line-height: 1.3; }
.saw-qbar__t span { display: block; font-weight: 600; color: var(--saw-text-muted); }
.saw-qbar__x {
	flex: none; width: 44px; height: 44px; padding: 0; border-radius: 50%;
	border: 1px solid var(--saw-surface); background: transparent; color: inherit;
	display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
}
.saw-qprog { position: sticky; top: 0; z-index: 5; height: 4px; background: var(--saw-surface); }
.saw-qprog > i { display: block; height: 100%; background: var(--saw-brand); }

/* ---- question / reveal (26 / 27) ------------------------------------ */
.saw-qmain {
	box-sizing: border-box; width: 100%; max-width: 680px; margin: 0 auto;
	padding: 18px 16px 40px; display: flex; flex-direction: column; gap: 16px;
}

/* The ladder: one chip per question with its tickets. */
.saw-qlad { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 4px; }
.saw-qlad > i {
	height: 32px; border-radius: 8px; border: 1.5px solid var(--saw-surface);
	display: flex; align-items: center; justify-content: center;
	font-size: 12px; font-style: normal; font-weight: 800; color: var(--saw-text-muted);
}
.saw-qlad > i.is-cur { border-color: var(--saw-brand); color: var(--saw-text); }
.saw-qlad > i.is-ok { background: var(--saw-brand); border-color: var(--saw-brand); color: #fff; }
.saw-qlad > i.is-no { text-decoration: line-through; }

.saw-qmeta { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
.saw-qmeta__level { font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--saw-text-muted); }
.saw-qmeta__level span { display: block; margin-top: 5px; font-size: 14px; letter-spacing: 0; text-transform: none; color: var(--saw-text); }
.saw-qmeta__tix { text-align: right; }
.saw-qmeta__tix small { display: block; font-size: 10px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--saw-text-muted); }
.saw-qmeta__tix b { display: block; margin-top: 4px; font-size: 28px; line-height: 1; transition: transform .3s; }
.saw-qmeta__tix.is-landed b { transform: scale(1.18); }
/* Neutral look; the theme sets colours and type. */
.saw-tkfly {
	position: fixed; z-index: 200; pointer-events: none;
	display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px; border-radius: 999px;
	background: #fff; color: #111; border: 1px solid rgba(0, 0, 0, .15); box-shadow: 0 8px 18px -10px rgba(0, 0, 0, .5);
	font-size: 15px; font-weight: 800; line-height: 1; white-space: nowrap;
	transform: translate(-50%, -50%);
}
.saw-tkfly svg { width: 16px; height: 16px; flex: none; }
@media (prefers-reduced-motion: reduce) {
	.saw-qmeta__tix.is-landed b { transform: none; }
}

.saw-qprize {
	display: flex; align-items: center; gap: 14px; padding: 8px 14px;
	border-radius: 18px; border: 1px solid var(--saw-surface);
}
.saw-qprize__photo { flex: none; width: 52px; height: 72px; display: flex; align-items: center; justify-content: center; }
.saw-qprize__photo img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; }
.saw-qprize small,
.saw-qprize b,
.saw-qprize span { display: block; }
.saw-qprize small { font-size: 9px; letter-spacing: .14em; text-transform: uppercase; color: var(--saw-text-muted); }
.saw-qprize b { margin-top: 5px; font-size: 20px; }
.saw-qprize span { margin-top: 3px; font-size: 13px; color: var(--saw-text-muted); }

.saw-qcard { padding: 22px 18px; border-radius: 20px; border: 1.5px solid var(--saw-surface); background: #fff; }
.saw-qtop { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; }
.saw-qtop .saw-question { margin: 0; min-width: 0; font-size: 22px; line-height: 1.25; font-weight: 800; color: var(--saw-text); }

/* The per-question countdown ring: a faint full track and an arc that starts
   at 12 o'clock and empties; the script sets the arc's dash offset every frame. */
.saw-qtimer { position: relative; width: 62px; height: 62px; flex: 0 0 auto; }
.saw-qtimer svg { display: block; }
.saw-qtimer__track { fill: none; stroke: var(--saw-surface); stroke-width: 5; }
.saw-qtimer__fill {
	fill: none; stroke: var(--saw-level, var(--saw-brand)); stroke-width: 5; stroke-linecap: round;
	stroke-dasharray: 163.4;
	transition: stroke .3s ease;
}
.saw-qtimer.is-urgent .saw-qtimer__fill { stroke: var(--saw-danger); }
.saw-qtimer.is-spent .saw-qtimer__fill { stroke: var(--saw-text-muted); }
.saw-qtimer__num {
	position: absolute; inset: 0; display: grid; place-items: center;
	font-size: 15px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--saw-text);
}

/* Single column, click-to-answer: no separate Submit step (see selectOption()
   in the script) — picking an option is answering it. */
.saw-answers { margin-top: 20px; display: flex; flex-direction: column; gap: 10px; }
.saw-answer {
	display: flex; align-items: center; gap: 12px; width: 100%; min-height: 60px; text-align: left; padding: 8px 18px;
	background: var(--saw-tint, #fcf4ec); border: 1.5px solid var(--saw-control, var(--saw-surface));
	border-radius: 12px; font-size: 16px; font-weight: 600; color: var(--saw-text); cursor: pointer;
	transition: border-color .12s, background .12s, box-shadow .12s, opacity .12s;
}
/* (hover: hover) so tapping an option on a touchscreen never leaves its border
   stuck highlighted — mobile browsers apply :hover on tap and only clear it on
   the next tap elsewhere, which read as the option staying "selected" forever. */
@media (hover: hover) and (pointer: fine) {
	.saw-answer:hover:not(:disabled) { border-color: var(--saw-brand); }
}
.saw-answer:disabled { cursor: default; }
.saw-answer.is-selected { border-color: var(--saw-brand); box-shadow: 0 0 0 1px var(--saw-brand); }
.saw-answer.is-correct { background: var(--saw-ok); border-color: var(--saw-ok); color: #fff; }
.saw-answer.is-wrong { background: var(--saw-danger); border-color: var(--saw-danger); color: #fff; opacity: 1; }
.saw-answer em {
	flex: none; width: 30px; height: 30px; border-radius: 50%; border: 1.5px solid var(--saw-surface);
	display: inline-flex; align-items: center; justify-content: center;
	font-size: 13px; font-style: normal; font-weight: 800; color: var(--saw-text-muted);
}
.saw-answer.is-correct em,
.saw-answer.is-wrong em { border-color: currentColor; color: inherit; }
.saw-qmain.is-revealing .saw-answer:not(.is-correct):not(.is-wrong) { opacity: .55; }

/* The reveal's outcome line, then the Next button. Empty but kept in place
   while a question shows, so the reveal does not move the page. */
.saw-qfb {
	min-height: 22px; align-self: center; text-align: center; padding: 7px 16px;
	border-radius: 999px; font-size: 15px; font-weight: 800; line-height: 1.4;
}
.saw-qfb.is-good { color: var(--saw-ok); background: var(--saw-ok-soft); }
.saw-qfb.is-bad { color: var(--saw-danger); background: var(--saw-danger-soft); }
.saw-banked__next {
	align-self: center; min-height: 44px; padding: 0 8px;
	border: 0; background: transparent; color: inherit; font: inherit; font-weight: 800;
	text-decoration: underline; text-underline-offset: 3px; cursor: pointer; white-space: nowrap;
}
.saw-banked__next:disabled { opacity: .5; cursor: default; }

/* ---- results ------------------------------------------------------------ */
/* Neutral layout only: the theme decides the look. */
.saw-results { box-sizing: border-box; width: 100%; max-width: 620px; margin: 0 auto; padding: 24px 20px; text-align: center; }
.saw-results__kicker { margin: 0; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--saw-text-muted); }
.saw-results__headline { margin: 4px 0 0; font-size: 16px; font-weight: 800; color: var(--saw-text); }
.saw-results__name { margin: 4px 0 0; font-size: 15px; font-weight: 800; color: var(--saw-text); }
.saw-results__stats { margin-top: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.saw-results__stat { display: flex; flex-direction: column; gap: 3px; padding: 14px 12px; border-radius: 18px; border: 1px solid var(--saw-surface); }
.saw-results__stat small { font-size: 10.5px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: var(--saw-text-muted); }
.saw-results__stat b { font-size: 40px; line-height: 1; font-weight: 900; color: var(--saw-text); font-variant-numeric: tabular-nums; }
.saw-results__stat b i { font-style: normal; font-size: .5em; color: var(--saw-text-muted); margin-left: 2px; }
.saw-results__stat span { font-size: 12.5px; color: var(--saw-text-muted); }
.saw-results__stat--tickets b { color: var(--saw-brand); }
.saw-results__stat--tickets.is-zero b { color: var(--saw-text-muted); }
.saw-results__chips { margin: 18px auto 0; max-width: 380px; }
.saw-results .saw-minting, .saw-results .saw-mint-error { margin: 18px 0 0; }
.saw-results__draw { margin-top: 18px; padding: 14px; border-radius: 16px; border: 1px solid var(--saw-surface); font-size: 14px; line-height: 1.5; }
.saw-results__draw b { font-size: 16px; }
.saw-results__drawsub { margin-top: 6px; }
.saw-results__oddsk { margin-top: 8px; }
.saw-results__draw b.saw-results__oddsv { font-size: 22px; }
.saw-results__oddsnote { display: block; margin-top: 8px; font-size: 12px; color: var(--saw-text-muted); }
.saw-results__drawline { margin: 12px 0 0; font-size: 13px; color: var(--saw-text-muted); }
.saw-results__entries, .saw-results__fine { margin-top: 16px; text-align: left; }
.saw-results__label { margin: 0; font-size: 13px; color: var(--saw-text-muted); }
.saw-results__numbers { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 8px; }
.saw-numchip { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--saw-surface); font-size: 13px; font-weight: 700; color: var(--saw-text); }
.saw-numchip--more { color: var(--saw-brand); border-color: transparent; background: transparent; font: inherit; cursor: pointer; }
.saw-results__note { margin: 0; font-size: 12px; color: var(--saw-text-muted); }
.saw-results__fineprint { margin: 0; font-size: 13px; line-height: 1.6; color: var(--saw-text); }
.saw-results__fineprint--muted { color: var(--saw-text-muted); margin-top: 4px; }
.saw-results .saw-runs-left { margin: 12px 0 0; }

.saw-results__actions { margin-top: 20px; display: flex; flex-direction: column; gap: 10px; }
.saw-results__ctawrap { display: block; }
.saw-results__primary,
.saw-results__secondary {
	box-sizing: border-box; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;
	min-height: 52px; padding: 0 24px; border-radius: 12px; text-align: center;
	font-weight: 800; font-size: 15px; text-decoration: none;
}
.saw-results__primary { background: var(--saw-brand); color: #fff; }
.saw-results__secondary { border: 1.5px solid currentColor; color: var(--saw-text); }
.saw-results__secondary.is-primary { border-width: 2px; }
.saw-results__back { align-self: center; min-height: 44px; display: inline-flex; align-items: center; padding: 0 6px; font-size: 14px; color: var(--saw-text-muted); text-decoration: underline; }
/* Free competition result: neutral layout only; the theme gives it its look. */
.saw-fres__win { padding: 20px 16px 16px; border-radius: 18px; border: 1px solid var(--saw-surface); }
.saw-fres__kicker { margin: 0; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
.saw-fres__score { margin-top: 8px; display: flex; justify-content: center; align-items: baseline; gap: 4px; font-variant-numeric: tabular-nums; }
.saw-fres__score b { font-size: 64px; line-height: .9; font-weight: 900; }
.saw-fres__score span { font-size: 24px; font-weight: 900; color: var(--saw-text-muted); }
.saw-fres__verdict { margin: 6px 0 0; font-size: 17px; font-weight: 800; }
.saw-fres__dots { margin: 12px auto 0; max-width: 300px; display: grid; grid-template-columns: repeat( 10, 1fr ); gap: 5px; }
.saw-fres__dots i { height: 7px; border-radius: 99px; background: var(--saw-surface); }
.saw-fres__dots i.is-ok { background: var(--saw-brand); }
.saw-fres__tix { margin-top: 14px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 12px 14px; border-radius: 16px; border: 1px solid var(--saw-surface); text-align: left; }
.saw-fres__tixl small, .saw-fres__tixl b { display: block; }
.saw-fres__tixl small { font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.saw-fres__tixl b { margin-top: 2px; font-size: 34px; line-height: 1; font-weight: 900; color: var(--saw-brand); }
.saw-fres__added { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 800; }
.saw-fres__ck { width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--saw-ok); color: #fff; }
.saw-fres__none { margin: 14px 0 0; font-size: 13px; font-weight: 700; }
.saw-fres__draw { margin: 10px 0 0; font-size: 12px; color: var(--saw-text-muted); }
.saw-fres__share, .saw-fres__follow { margin-top: 16px; }
.saw-fres__h { margin: 0; font-size: 20px; line-height: 1.2; font-weight: 800; }
.saw-fres__sub { margin: 6px auto 12px; max-width: 440px; font-size: 13px; color: var(--saw-text-muted); }
.saw-fres__gowrap { display: block; }
.saw-fres__go, .saw-fres__fbtn { box-sizing: border-box; width: 100%; min-height: 48px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0 16px; border-radius: 999px; font: inherit; font-weight: 800; text-decoration: none; cursor: pointer; }
.saw-fres__go { border: 0; background: var(--saw-brand); color: #fff; }
.saw-fres__prev { box-sizing: border-box; margin-top: 10px; width: 100%; display: flex; align-items: center; gap: 12px; padding: 8px 12px 8px 8px; border-radius: 16px; border: 1px solid var(--saw-surface); background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer; }
.saw-fres__thumb { flex: none; width: 44px; pointer-events: none; }
.saw-fres__thumb .saw-sharesheet__card { display: block; width: 44px; border-radius: 7px; }
.saw-fres__ptx { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.saw-fres__ptx small { color: var(--saw-text-muted); }
.saw-fres__fbtns { margin-top: 12px; display: grid; grid-template-columns: 1fr; gap: 8px; }
@media ( min-width: 600px ) { .saw-fres__fbtns { grid-template-columns: 1fr 1fr; } }
.saw-fres__fbtn { border: 1.5px solid currentColor; color: var(--saw-text); }
.saw-fres__end { margin-top: 16px; display: flex; justify-content: center; flex-wrap: wrap; gap: 18px; }
.saw-fres__link { min-height: 44px; display: inline-flex; align-items: center; padding: 0 6px; font-size: 14px; color: var(--saw-text-muted); text-decoration: underline; }

.saw-invite { margin-top: 16px; padding: 16px; border-radius: 16px; border: 1px solid var(--saw-surface); text-align: left; }
.saw-invite__badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.saw-invite__title { margin: 8px 0 0; font-size: 20px; line-height: 1.2; font-weight: 800; }
.saw-invite__sub { margin: 6px 0 0; font-weight: 700; }
.saw-invite__tiles { margin-top: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.saw-invite__tile { padding: 12px 10px; border-radius: 14px; border: 1px solid var(--saw-surface); text-align: center; }
.saw-invite__tile small, .saw-invite__tile b, .saw-invite__tile span { display: block; }
.saw-invite__tile b { margin-top: 4px; font-size: 36px; line-height: 1; }
.saw-invite__prog { margin-top: 12px; }
.saw-invite__pl { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 4px 10px; }
.saw-invite__slots { margin-top: 10px; display: grid; grid-template-columns: repeat( 5, minmax( 0, 1fr ) ); gap: 6px; }
.saw-invite__slot { position: relative; height: 44px; display: flex; align-items: center; justify-content: center; border-radius: 10px; border: 1.5px dashed currentColor; opacity: .5; }
.saw-invite__slot.is-on { border-style: solid; opacity: 1; background: var(--saw-brand); }
.saw-invite__slot small { position: absolute; top: 3px; right: 5px; font-size: 9px; }
.saw-invite__how { margin: 12px 0 0; font-size: 13px; }
.saw-invite__gowrap { display: block; margin-top: 14px; }
.saw-invite__go { box-sizing: border-box; width: 100%; min-height: 48px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0 16px; border: 0; border-radius: 999px; background: var(--saw-brand); color: #fff; font: inherit; font-weight: 800; cursor: pointer; }
.saw-invite__opts { margin-top: 10px; padding: 12px; border-radius: 14px; border: 1px solid var(--saw-surface); }
.saw-invite__label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; }
.saw-invite__link { display: block; margin-top: 4px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; color: inherit; text-decoration: none; }
.saw-invite__grid { margin-top: 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.saw-invite__btn { box-sizing: border-box; min-width: 0; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0 12px; border-radius: 999px; border: 1.5px solid currentColor; background: transparent; color: inherit; font: inherit; font-weight: 800; text-decoration: none; cursor: pointer; }

@media (max-width: 480px) {
	.saw-langcard { padding: 32px 20px; }
	.saw-langcard__options { flex-direction: column; }
}
</style>

<style>
body.saw-quiz-dialog-open { overflow: hidden; }
/* Share my score: neutral layout only; a theme supplies the look. */
.saw-results__share {
	box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
	min-height: 46px; padding: 0 16px; border-radius: 999px;
	border: 1px solid #d0d5dd; background: #fff; color: #0d0d1b;
	font: inherit; font-weight: 700; cursor: pointer;
}
.saw-sharesheet {
	position: fixed; inset: 0; z-index: 100050;
	display: flex; align-items: center; justify-content: center; padding: 12px;
}
.saw-sharesheet__backdrop {
	position: absolute; inset: 0; margin: 0; padding: 0; border: 0;
	background: rgba(13, 13, 27, 0.55); cursor: default;
}
.saw-sharesheet__panel {
	position: relative; box-sizing: border-box; width: calc(100% - 24px); max-width: 420px;
	max-height: calc(100dvh - 24px); overflow: auto;
	padding: 16px 16px 18px; border-radius: 20px; background: #fff; color: #0d0d1b;
}
@media (min-width: 700px) { .saw-sharesheet__panel { max-width: 440px; } }
.saw-sharesheet__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.saw-sharesheet__title { margin: 0; font-size: 20px; line-height: 1.25; font-weight: 800; }
.saw-sharesheet__sub { font-size: 12.5px; line-height: 1.3; }
.saw-sharesheet__close {
	position: relative; flex: none; display: inline-flex; align-items: center; justify-content: center;
	width: 44px; height: 44px; padding: 0; border-radius: 50%;
	border: 1px solid #d0d5dd; background: #fff; color: inherit; cursor: pointer;
}
.saw-sharesheet__prev { margin-top: 12px; display: flex; justify-content: center; }
.saw-sharesheet__card {
	position: relative; box-sizing: border-box; container-type: inline-size;
	width: min(100%, calc((100dvh - 250px) * 9 / 16), 300px); aspect-ratio: 9 / 16;
	border-radius: 22px; overflow: hidden; background: #1f2433; color: #fff;
}
.saw-sharesheet__glow { position: absolute; inset: auto -20% 38% auto; width: 80%; aspect-ratio: 1; border-radius: 50%; pointer-events: none; }
.saw-sharesheet__amp { position: absolute; right: -14cqw; top: 6cqw; font-size: 118cqw; line-height: 1; opacity: 0.06; transform: rotate(-7deg); pointer-events: none; user-select: none; }
.saw-sharesheet__in { position: relative; height: 100%; box-sizing: border-box; display: flex; flex-direction: column; padding: 7cqw 7cqw 6cqw; }
.saw-sharesheet__top { display: flex; justify-content: space-between; align-items: center; gap: 3cqw; }
.saw-sharesheet__logo { display: block; max-width: 45%; height: auto; max-height: 9cqw; font-size: 4.6cqw; font-weight: 900; line-height: 1; }
span.saw-sharesheet__logo { display: inline-flex; align-items: center; gap: 0.34em; max-width: none; max-height: none; white-space: nowrap; }
.saw-sharesheet__kicker { font-size: 2.7cqw; font-weight: 800; line-height: 1; text-transform: uppercase; padding: 1.6cqw 2.8cqw; border-radius: 99px; border: 1px solid rgba(255, 255, 255, 0.3); }
.saw-sharesheet__mid { margin: auto 0; display: flex; flex-direction: column; align-items: flex-start; }
.saw-sharesheet__label { font-size: 3.2cqw; font-weight: 800; text-transform: uppercase; }
.saw-sharesheet__score { margin-top: 2.4cqw; font-size: 40cqw; font-weight: 900; line-height: 0.86; }
.saw-sharesheet__score span { font-size: 0.36em; margin-left: 1cqw; }
.saw-sharesheet__bars { margin-top: 5cqw; width: 100%; display: grid; grid-template-columns: repeat(10, 1fr); gap: 1.4cqw; }
.saw-sharesheet__bar { height: 2.6cqw; border-radius: 99px; background: rgba(255, 255, 255, 0.2); }
.saw-sharesheet__bar.is-correct { background: #fff; }
.saw-sharesheet__tix { margin-top: 4.4cqw; font-size: 4.2cqw; line-height: 1.2; }
.saw-sharesheet__prize { margin-top: 6cqw; display: flex; align-items: center; gap: 3.4cqw; padding: 3.2cqw; border-radius: 5cqw; border: 1px solid rgba(255, 255, 255, 0.2); }
.saw-sharesheet__photo { flex: none; width: 13cqw; height: 13cqw; }
.saw-sharesheet__photo img { width: 100%; height: 100%; object-fit: contain; }
.saw-sharesheet__prize small, .saw-sharesheet__prize b, .saw-sharesheet__prize span { display: block; }
.saw-sharesheet__prize small { font-size: 2.5cqw; text-transform: uppercase; }
.saw-sharesheet__prize b { margin-top: 0.6cqw; font-size: 4.2cqw; line-height: 1.15; }
.saw-sharesheet__prize span { margin-top: 0.4cqw; font-size: 3.2cqw; }
.saw-sharesheet__ch { margin-top: 7cqw; font-size: 7.4cqw; font-weight: 900; line-height: 1.02; text-transform: uppercase; }
.saw-sharesheet__foot { margin-top: 6cqw; padding-top: 4cqw; border-top: 1px solid rgba(255, 255, 255, 0.2); display: flex; justify-content: space-between; align-items: flex-end; gap: 3cqw; }
.saw-sharesheet__foot span, .saw-sharesheet__foot b { display: block; }
.saw-sharesheet__foot span { font-size: 2.9cqw; }
.saw-sharesheet__foot b { margin-top: 0.6cqw; font-size: 4.6cqw; line-height: 1; }
.saw-sharesheet__foot em { font-size: 2.4cqw; font-style: normal; text-align: right; max-width: 40%; }
.saw-sharesheet__error, .saw-sharesheet__status { margin: 14px 0 0; font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; }
.saw-sharesheet__error { padding: 12px 14px; border-radius: 12px; border: 1px solid #dc2626; }
.saw-sharesheet__acts { margin-top: 14px; display: grid; grid-template-columns: 1fr; gap: 8px; }
.saw-sharesheet__gowrap { display: block; }
.saw-sharesheet__go, .saw-sharesheet__copy {
	box-sizing: border-box; width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
	min-height: 46px; padding: 0 16px; border-radius: 999px;
	border: 1px solid #d0d5dd; background: #fff; color: #0d0d1b;
	font: inherit; font-weight: 700; cursor: pointer;
}
.saw-sharesheet__go { border-color: #0d0d1b; background: #0d0d1b; color: #fff; }
body.saw-quiz-takeover .saw-hero,
body.saw-quiz-takeover .saw-spec,
body.saw-quiz-takeover .saw-result-teaser,
body.saw-quiz-takeover .saw-footnote,
body.saw-quiz-takeover .saw-connector { display: none; }
.saw-leave {
	--saw-brand: var(--color-primary, #eb580c);
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
@media (hover: hover) and (pointer: fine) {
	.saw-leave__stay:hover { background: color-mix(in srgb, var(--saw-brand) 88%, #000); transform: translateY(-1px); }
}
.saw-leave__go {
	display: block; width: 100%; padding: 10px 12px; border: none; border-radius: 8px;
	background: transparent; color: var(--saw-text-muted); font-weight: 600; font-size: 14px;
	cursor: pointer; text-decoration: underline; text-underline-offset: 3px;
}
@media (hover: hover) and (pointer: fine) {
	.saw-leave__go:hover { color: var(--saw-text); background: #f8fafc; }
}
.saw-leave__stay:focus-visible, .saw-leave__go:focus-visible, .saw-leave__backdrop:focus-visible {
	outline: 2px solid var(--saw-brand);
	outline-offset: 2px;
}
</style>
