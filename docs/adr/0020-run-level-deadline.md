# A run has its own deadline, and the sweep runs on that

> **Partly superseded by [ADR 0030](0030-a-question-clock-starts-when-it-is-served-and-an-interrupted-run-is-held.md):**
> a question's deadline is now stamped when it is served, not chained from the
> previous answer, and "let the player continue" holds an interrupted run for Resume
> instead of scoring the lapsed questions zero. The run-level `expires_at`, the sweep
> and the `errored` state below still stand.

A run is stamped with `expires_at` when it starts — its question count times the
per-question timer, plus slack. The sweeper that finalizes abandoned runs selects on
that column. On reconnect, the live question is computed from elapsed time: slots
whose window has passed are scored as timeouts, the player lands on whichever
question is current, and tickets already earned stay banked.

The hook is scheduled on activation, with a `wp_next_scheduled` guard on init, at a
five-minute interval.

**Why:** the sweeper has never run. `Nera_SAW_Run::finalize_stale()` is hooked to
`nera_saw_sweep_reservations` at `class-plugin.php:127`, but
`Nera_SAW_Reservations::init()` was emptied — its body is `$unused = null;` — when
reservation moved to payment time. Across the whole plugin the hook string appears
exactly twice: the constant, and the `add_action`. Nothing ever scheduled it.

The effect on a live site is not cosmetic. A player who closes the tab mid-run
leaves an `active` run **forever**: the grant is never resolved, `max_possible_spins`
stays reserved and distorts the remaining-ticket figures, and — because minting
happens inside `finalize()` — **tickets the player actually earned are never
minted**. `requirements.md` L38 describes the opposite as current behaviour:
"tickets already earned stay banked, which matches how Abandoned and Expired runs
award tickets today." They do not.

**Scheduling the hook is not the fix.** The existing query asks for active runs with
no unanswered slot still within its deadline, and counts `deadline_at IS NULL` — a
slot that was never served — as within deadline:

```sql
AND ( s.deadline_at IS NULL OR s.deadline_at >= %s )
```

A player who stops at question 5 leaves slots 6–10 unserved, so the run never
qualifies. The only runs that query can ever close are those where every slot was
served and none answered, which is close to nobody. A per-slot deadline cannot
express "this run is over" because the slots that would prove it are exactly the
ones that were never reached.

**Why the strict reading of reconnect.** As built, the clock starts only when the
client asks for a slot, so a disconnected player is effectively paused and can
return tomorrow to an unexpired question. `requirements.md` L38 asks for the
opposite — "the run clock to keep running on the server question by question… missed
questions score zero" — and that was reaffirmed. A run-level deadline delivers it
and repairs the sweep in the same change.

**Alternatives rejected:**

- *Auto-serve the next slot when the current one lapses,* chaining deadlines
  forward. Keeps per-slot timing authoritative, but requires a process to do the
  serving, which is the cron this plugin has just been shown not to run.
- *A long run-level expiry (24h) with lenient per-slot behaviour.* Fixes the ledger
  without delivering what was asked for, and leaves an exploit: a player may stop
  between questions indefinitely, so the time pressure the product sells exists only
  within a question and not across the run.

**Two related defects fixed in the same change.** Both are in the resume path and
both are silent:

- `redraw_run_slots()` marks the **new** question set seen but never un-marks the
  discarded one, so each redraw permanently burns questions from that player's bank.
  Fixed by marking a question seen when a slot is **served**, not when it is drawn —
  which also fixes every other abandoned-draw case. This matters most where the bank
  is thin, and the Russian bank will start thin.
- `redraw_run_slots()` deletes every slot and re-inserts with no transaction; a
  failure between leaves a run with zero slots and a consumed grant.

**Not a defect:** two tabs cannot double-score. `submit_answer()` guards on
`$slot->answered_at` and `owned_run()`. Recorded here so it is not "fixed".

## An interruption is not always something to play through

A setting, **Resume policy**, decides what an interruption costs. The default,
`resume`, is everything described above: the clock ran, the questions that lapsed
score zero, the player continues from whichever is live.

Under `close`, an interrupted run is not continued. It is marked **errored** and
left for an administrator.

`errored` rather than a new state, because the mechanism already exists: ADR 0013
made `errored` the "stuck, a human should look" marker, it deliberately does not
finalize, the Report surfaces it, and Restore refunds the run and voids it. Under
a no-resume policy that is the fair outcome — the player did not get the run they
paid for, so they get the run back rather than a partial score. Nothing is minted.

Two paths reach it, and they are not the same event:

- **The player confirms the leave dialog.** Unchanged by this setting. That is a
  decision, the dialog says what it costs, and the run is **abandoned** — which
  still mints what was earned, under either policy.
- **The connection drops, or the browser closes.** No decision was recorded, so
  under `close` the run is errored and logged as `run_interrupted`.

A reload *inside* the live question is not an interruption and does not trigger
this. Nothing lapsed, so there is nothing to review — punishing a refresh would
turn every flaky mobile connection into a support ticket.

**The sweep must skip errored runs.** An errored run keeps `status = 'active'` by
design, because that is how the Report finds it. The sweep selects on
`status = 'active'`, so without an explicit exclusion it would close the run, mint
its tickets and erase the marker before any administrator saw it. This did not
matter while the sweep never ran. It does now.

**Consequence:** under `close`, every player who closes their browser mid-quiz
creates a row for someone to look at. On a busy site that is real work, and it is
the cost of the policy rather than a defect in it. The default stays `resume` for
that reason.

**Consequence:** `runs` gains an `expires_at` column and an upgrade migration.
Existing `active` runs have no value for it; back-fill from `started_at` plus the
snapshot's timer and question count so the first sweep closes the backlog rather
than skipping it. Expect that first sweep to finalize a large number of long-dead
runs and mint their tickets — which is correct, and should be announced before it
happens rather than discovered in the Report.
