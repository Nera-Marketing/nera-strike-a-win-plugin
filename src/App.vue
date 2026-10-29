<script setup>
import { ref, reactive, onMounted, onUnmounted, computed, nextTick, watch } from 'vue';
import { api, breadcrumb } from './api.js';

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
 * Russian's three plural forms (one/few/many) from a pipe-separated string —
 * identical to the client's own supplied `_plural_helper_js`
 * (languages/ru/02b-russian-strings-dev-build.json).
 */
function ruPlural( n, forms ) {
	const f = forms.split( '|' );
	const m10 = n % 10;
	const m100 = n % 100;
	if ( m10 === 1 && m100 !== 11 ) {
		return f[ 0 ];
	}
	if ( m10 >= 2 && m10 <= 4 && ( m100 < 12 || m100 > 14 ) ) {
		return f[ 1 ];
	}
	return f[ 2 ];
}

/**
 * Picks the right form out of a pipe-separated plural TEMPLATE
 * (`Nera_SAW_I18n::n_template()` on the PHP side) once the actual count is
 * known — which, for several of these strings (a per-question ticket
 * reward, a running tickets total), only happens client-side as the quiz
 * runs, well after the chrome strings table is built. Two forms is English's
 * own singular/plural rule (unchanged from what `_n()` already did at these
 * call sites); three is Russian's one/few/many via `ruPlural()`. A value
 * with no `|` was never plural-packed (nothing to pick) and is returned as
 * given.
 */
function pickPluralForm( n, template ) {
	const f = template.split( '|' );
	if ( f.length === 1 ) {
		return f[ 0 ];
	}
	if ( f.length === 2 ) {
		return 1 === n ? f[ 0 ] : f[ 1 ];
	}
	return ruPlural( n, template );
}

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

// "Quiz n of N · Premium ×10": n is this run's place among the runs the same
// purchase bought for this tier, N how many it bought (also "Quiz 1 of 1").
// The multiplier is only shown above 1.
const quizOfLabel = computed( () => {
	const tiers = competition.tiers && 'object' === typeof competition.tiers ? competition.tiers : {};
	const tier = tiers[ tierKey.value ] || null;
	const label = tier ? tier.label : tierKey.value;
	const mult = tier ? Number( tier.multiplier ) || 1 : 1;
	const head = fmt( 'quizOf', 'Quiz {n} of {total}', runIndex.value, runTotal.value );
	return label ? head + ' · ' + label + ( mult > 1 ? ' ×' + mult : '' ) : head;
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
const ticketsInDraw = ref( 0 ); // the player's live tickets on this competition after this run.
const nextRunNo = ref( 0 ); // "Play quiz 2 of 6": 0 when the tier has no run left.
const nextRunTotal = ref( 0 );
const summaryReady = ref( false ); // the run summary has arrived: the draw line and actions use its data.
const shownTickets = ref( 0 ); // the tickets-earned figure as it counts up.

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
		return { no: s.slot_no, reward: s.reward, state };
	} );
} );

// The progress line under the quiz bar: answered / total.
const progressPct = computed( () =>
	totalSlots.value ? Math.min( 100, ( answeredCount.value / totalSlots.value ) * 100 ) : 0
);

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

// Resume the run, then re-read the live question so its clock is the server's.
async function resumeThen( slotNo ) {
	try {
		await api.resumeRun( runId.value );
	} catch ( e ) {
		return fail( e );
	}
	return loadSlot( slotNo );
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
				await resumeThen( slot.slot_no );
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

// Tickets earned counts up from 0 over about a second; at once with reduced motion.
let countFrame = 0;
let countTarget = null;
function countUpTickets( to ) {
	cancelAnimationFrame( countFrame );
	countTarget = to || 0;
	if ( ! to || prefersReducedMotion() ) {
		shownTickets.value = to || 0;
		return;
	}
	const from = performance.now();
	const step = ( now ) => {
		const p = Math.min( 1, ( now - from ) / 1000 );
		shownTickets.value = Math.round( to * ( 1 - Math.pow( 1 - p, 3 ) ) );
		if ( p < 1 ) {
			countFrame = requestAnimationFrame( step );
		}
	};
	shownTickets.value = 0;
	countFrame = requestAnimationFrame( step );
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

	phase.value = 'question';
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
		ticketsInDraw.value = Number( summary.tickets_in_draw ) || 0;
		nextRunNo.value = Number( summary.next_run_no ) || 0;
		nextRunTotal.value = Number( summary.next_run_total ) || 0;
		summaryReady.value = true;
		// The count started from the run's own tally; only a different confirmed total restarts it.
		if ( countTarget !== finalSpins.value ) {
			countUpTickets( finalSpins.value );
		}
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
	// Counts up from the run's own tally while the tickets are minted.
	countUpTickets( finalSpins.value );
	phase.value = 'end';
	await fetchRunComplete();
}

const competitionUrl = computed( () => ( competitionId.value ? `/?p=${ competitionId.value }` : '#' ) );
const competitionsListUrl = ( window.NeraSAW && window.NeraSAW.competitionsUrl ) || '';
const competitionPageUrl = ( window.NeraSAW && window.NeraSAW.competitionPermalink ) || '';
const myTicketsUrl = ( window.NeraSAW && window.NeraSAW.myTicketsUrl ) || '';
const responsiblePlayUrl = ( window.NeraSAW && window.NeraSAW.responsiblePlayUrl ) || '';
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
	clearTicker();
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

				<div class="saw-qcard">
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
							@click="selectOption(a.index)"
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

		<div v-else-if="phase === 'end'" class="saw-results">
			<p class="saw-results__kicker">{{ t('resultKicker', 'Challenge completed') }}</p>
			<p class="saw-results__headline">{{ resultsHeadline }}</p>
			<p v-if="competition.name" class="saw-results__name">{{ competition.name }}</p>

			<div class="saw-results__stats">
				<div class="saw-results__stat">
					<small>{{ t('scoreLabel', 'Score') }}</small>
					<b>{{ correctCount }}<i>/{{ totalSlots || slotResults.length }}</i></b>
					<span>{{ t('correctAnswers', 'correct answers') }}</span>
				</div>
				<div class="saw-results__stat saw-results__stat--tickets" :class="{ 'is-zero': !hasTickets }">
					<small>{{ t('ticketsEarnedLabel', 'Tickets earned') }}</small>
					<b>{{ hasTickets ? '+' + formatCount( shownTickets ) : '0' }}</b>
					<span>{{ hasTickets ? t('inThisDraw', 'in this draw') : '—' }}</span>
				</div>
			</div>

			<div v-if="resultChips.length" class="saw-qlad saw-results__chips" aria-hidden="true">
				<i v-for="c in resultChips" :key="c.key" :class="'is-' + c.kind">{{ c.text }}</i>
			</div>

			<div v-if="ticketsLoading" class="saw-minting" role="status" aria-live="polite">
				<span class="saw-spinner" aria-hidden="true"></span>
				<span>{{ t('mintingTickets', 'Adding your tickets to the draw…') }}</span>
			</div>
			<div v-else-if="ticketsError" class="saw-mint-error">
				<p>{{ ticketsError }}</p>
				<button type="button" class="saw-btn saw-btn-ghost" @click="fetchRunComplete">{{ t('retryTickets', 'Try again') }}</button>
			</div>
			<template v-else>
				<div v-if="hasTickets" class="saw-results__draw">
					<div>{{ t('ticketsInDraw', 'Your tickets in this draw') }}: <b>{{ ticketsWord( ticketsInDraw ) }}</b></div>
				</div>
				<div v-else class="saw-results__draw is-neutral">{{ t('noTicketsThisQuiz', 'No tickets from this quiz.') }}<div v-if="ticketsInDraw > 0" class="saw-results__drawsub">{{ t('ticketsInDraw', 'Your tickets in this draw') }}: <b>{{ ticketsWord( ticketsInDraw ) }}</b></div></div>
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
					<p v-if="drawDate" class="saw-results__fineprint saw-results__fineprint--muted">{{ fmt('drawClosesPrefix', 'The draw closes %s.', drawDate) }}</p>
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

@media (max-width: 480px) {
	.saw-langcard { padding: 32px 20px; }
	.saw-langcard__options { flex-direction: column; }
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
