# Nera — Strike A Win

Skill-based prize-competition quiz for WooCommerce. A player buys an entry, plays a
timed quiz, and earns Lottery for WooCommerce ticket numbers for each correct
answer. Tickets are **earned, never bought** — that distinction is the plugin's
compliance basis (Gambling Act 2005 skill exemption) and nearly every design choice
here defends it.

| | |
|---|---|
| Slug / folder | `nera-strike-a-win-plugin` |
| Text domain | `nera-strikeawin` |
| Class prefix | `Nera_SAW_` |
| Requires | WordPress 6.0+, PHP 7.4+, WooCommerce, Lottery for WooCommerce |
| Version source of truth | the `Version:` header in `nera-strikeawin.php` |

Read [CONTEXT.md](CONTEXT.md) before using domain words in code or commits — Run,
Slot, Grant, Tier and Level all have narrow meanings here. Decisions with
consequences live in [docs/adr/](docs/adr/).

## Hard rules

1. **Every change lives inside this plugin.** No theme edits. The theme may not be
   present on a given site, and the plugin ships to several.
2. **Tickets are minted only by `Nera_SAW_Ticket_Award` at run finalize.** Purchase
   must never mint. `Nera_SAW_Integrations` actively blocks LFW from doing so; if a
   change makes tickets appear at checkout, that is a compliance failure, not a bug
   to smooth over.
3. **The answer key never leaves the server.** `serve_slot()` returns a question
   without its correct option; scoring happens in `submit_answer()`.
4. **The server owns every clock.** Deadlines are stamped server-side and scored
   server-side. Client timers are display only.
5. **Two modes must both keep working** — see Settings below. A change that only
   makes sense in one mode needs a guard, not an assumption.

## Layout

```
nera-strikeawin.php          bootstrap, constants, activation
includes/
  class-plugin.php           wiring: every init() is called from here
  class-constants.php        ladder, tiers, timer bounds, settings defaults
  class-database.php         custom tables (runs, run_slots, question_seen, …)
  class-competition-config.php  per-product config + effective tiers/rewards
  class-question-cpt.php     saw_question post type
  class-question-bank.php    the draw — picks slots for a run
  class-run.php              run engine: start/resume, serve, answer, finalize
  class-run-grants.php       purchase → per-tier run balance, FIFO consume
  class-ticket-award.php     mints LFW ticket numbers at finalize
  class-cart-entry.php       fixed-price tier entry in cart/checkout
  class-integrations.php     LFW suppression, email tailoring
  class-rest.php             /wp-json/nera-saw/v1/*
  class-frontend.php         [strikeawin_quiz] shortcode + Vue mount
  class-play-page.php        the play page and nera_saw_get_play_url()
  class-seeder.php           demo data (questions, competitions, plays)
  admin/                     settings, ladder, competition tab, reports, import
src/                         Vue 3 quiz app (App.vue, api.js)
assets/                      admin + frontend CSS, admin JS
dist/                        Vite build output (committed)
```

## Settings

Four settings gate behaviour, all resolved through `Nera_SAW_Mode` — never read
the option directly, because a competition may override one of them and each
resolves through a filter.

| Setting | Values | Default | Gates |
|---|---|---|---|
| **StrikeAWin Method** | `mix` · `standalone` | `mix` | Whether the plugin serves its own pages, and whether its products appear on the main site |
| **Quiz Method** | `random` · `ladder` | `random` | Slot order in `draw_for_run()`, and how the competition page and the run describe the quiz |
| **Language Scope** | `questions_only` · `whole_standalone` | `questions_only` | **Superseded by design, still in the code, and staying there.** Multilingual is building again ▶ (L1–L4 done, L5 remains) — see [docs/LANGUAGE-PLAN.md](docs/LANGUAGE-PLAN.md) and [ADR 0028](docs/adr/0028-polylang-owns-the-languages-strike-a-win-owns-the-reach.md) / [ADR 0029](docs/adr/0029-the-seeder-translates-what-it-generates.md). Nothing reads it — reach is `Nera_SAW_Language_Reach`, language is `Nera_SAW_Language`. **Do not delete it as tidying**: removing it is step L5 |
| **Resume policy** | `resume` · `close` | `resume` | Whether a run interrupted by a disconnect can be continued, or is closed for an administrator to review |

Every default is today's behaviour, **so that an existing install upgrades into
what it already does**. See [ADR 0019](docs/adr/0019-quiz-method-strategies.md).

Only `quiz_method` has a per-competition override. The other three are site-wide.

## Build and release

```bash
npm install
npm run build        # vite -> dist/ (commit the output)
./release.sh         # version from the plugin header; tags and pushes
```

`dist/` is committed because the plugin ships as a zip and sites do not run a
build step.

## Testing

There is no PHPUnit suite. Two things stand in for one:

- **Headless harness.** Load `wp-load.php` from a script, exercise the class
  directly, assert on the return. This is how the run engine, the draw and the
  grant ledger have been checked. Prefer it over clicking through the admin.
- **Playwright.** The front-end prototype ships 55 suites under `qa/` in
  `strikeawin-prototype-source.zip`; each assertion is an expected behaviour of the
  finished front end. They are the acceptance checklist for the standalone work.

When touching the run engine, test the failure paths first: an abandoned run, a
reconnect after the deadline, two tabs answering the same slot.

## Current state

The plugin is built and live. The standalone front-end rebuild is **in progress,
not planned-but-unstarted** — read [PROGRESS.md](PROGRESS.md) first; it is the
living, dated status doc and supersedes the paragraph below wherever they
disagree. The other three are point-in-time deliverables (a plan proposal and two
audit snapshots) — useful history, not living trackers:

| Document | What it is |
|---|---|
| [PROGRESS.md](PROGRESS.md) | **Living status, updated per session.** What works end to end today, where the original plan has been overridden by client decisions since, what to flag, what's next. Read this one. |
| [SUMMARY.md](SUMMARY.md) | Point-in-time findings, decisions, ETA from before the standalone build started. |
| [BUILD-PLAN.md](BUILD-PLAN.md) | The original 17-workstream plan proposal. Three of its workstreams were re-specified by client decisions after it was written — PROGRESS.md §3 says which, and why it is not rewritten in place. |
| [GAP-REPORT.md](GAP-REPORT.md) | A dated audit snapshot of the brief against the source as it stood then. |

### 🔴 Known live bug — fix before anything else

`Nera_SAW_Run::finalize_stale()` is hooked to `nera_saw_sweep_reservations`, but
nothing schedules that hook: `Nera_SAW_Reservations::init()` was emptied and its
body is now `$unused = null;`. A player who closes the tab mid-run therefore leaves
an `active` run **forever** — the grant is never resolved and **tickets already
earned are never minted**, because minting happens inside `finalize()`.

Scheduling the hook alone is not enough; the sweep query also treats a never-served
slot (`deadline_at IS NULL`) as "within deadline", so the common case never
matches. See [ADR 0020](docs/adr/0020-run-level-deadline.md).

## Conventions

- WordPress coding standards; tabs, Yoda conditions, `esc_*` on output,
  `$wpdb->prepare()` on every query.
- Static classes with an `init()` registering hooks; `Nera_SAW_Plugin` calls them.
- Custom tables via `Nera_SAW_Database::table( 'name' )` — never hardcode a prefix.
- **Templates declare nothing.** No `function`, no `class`, no `const`. WooCommerce
  includes a template as many times as it needs to — twice in one request when a
  checkout is submitted and the order review is rebuilt — and a declaration is a
  fatal on the second include. It has already cost one checkout that span forever on
  "Processing your order", because the fatal broke the AJAX response that would have
  dismissed the overlay. Helpers go on a class; templates only render.
- Comments explain **why**, not what. If a choice would surprise the next reader,
  write the ADR and cite it in the comment — that is the convention this codebase
  already follows, and [docs/adr/0000-lost-decision-records.md](docs/adr/0000-lost-decision-records.md)
  is what happens when the ADRs go missing but the citations remain.
