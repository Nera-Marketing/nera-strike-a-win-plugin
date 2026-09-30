# A question's clock starts when it is served, and an interrupted run is held

> **This supersedes part of [ADR 0020](0020-run-level-deadline.md)** — the sentences
> that chain each question's deadline from the moment the previous one ended, and that
> give a resumed player "whichever question is live, the ones they missed scoring
> zero". ADR 0020's run-level `expires_at`, its sweep, and the `errored` state from
> ADR 0013 (whose file is one of the lost records — see
> [ADR 0000](0000-lost-decision-records.md)) all still stand.

Two changes, made together because they share a clock.

## 1. Each question's 10 seconds start when it is shown

Slot 1's deadline used to be stamped when the run was created, and every later slot's
when the previous answer landed. So the language screen, the "Warming up" stage-break
screen and the answer reveal were all spent out of the question's own timer: a
player's first question appeared already at 7 (seen in a screen recording on
30 Sep 2026). `App.vue` already claimed "the hold costs the player no answering
time"; the server did not honour it.

Now `serve_slot()` stamps `served_at` and `deadline_at` together on first serve, and
never again. `start_clock()` no longer touches any slot, `chain_next_slot()` is gone,
and a lapsed-but-unserved slot no longer exists as a state.

The run-level clock had to change with it. `expires_at` was fixed at start as
`questions × timer + grace`, which assumed nothing sat between questions. It now rolls
forward on every serve: that question's deadline, plus a timer for each question still
to come, plus `BETWEEN_QUESTIONS_SLACK_SECONDS` (120) per question for the reveal and
stage-break screens. A player still cannot park a run indefinitely between questions —
which is what ADR 0020 rejected — but idling on a reveal screen no longer ends it.

## 2. "Let the player continue" holds the run instead of scoring it

Under *resume* an interrupted run used to be settled by the clock: whatever lapsed
scored zero, the player landed on the live question, and a tab close went through
`abandon()` and finalized the run on the spot. Now:

- While a run is on screen the client sends a **heartbeat** every
  `HEARTBEAT_SECONDS` (3), and one more on `pagehide`. The server keeps
  `runs.last_seen_at`.
- A run whose last heartbeat is older than `HEARTBEAT_STALE_SECONDS` (15) and younger
  than the window is **held**. This is derived from the two timestamps, never a stored
  state. The threshold is five beats so one dropped request is not an interruption.
- A held run cannot be served or answered (`saw_pending`). `resume()` gives the live
  question back `deadline − last_seen_at` — the time it had when the player vanished —
  and shifts `expires_at` by the time away. A fresh full timer was rejected: it would
  let a player see a question, close the tab, look the answer up and come back.
- The window is a Settings field, in minutes, no upper limit; blank means 5 (default
  shown: 10). Past it the run becomes `errored` — nothing minted, an administrator can
  Restore it — the same end state as *close*. Enforcement is lazy (on any page load
  that asks for the player's held runs, and on heartbeat/resume) and by the sweep, so
  it does not depend on WP-Cron firing on time.
- The player is offered **Resume** as a popup on the play page and the competition
  page, rendered from the server's answer. `localStorage` is not consulted: it does
  not follow the player to another device and cannot be trusted with a paid run.
  Resume goes straight to the interrupted question, with no language screen; the
  second button, **End run and see my results**, is a deliberate leave — the run
  finalizes, earned tickets are minted, unspent reserved stock is released, it cannot
  be restored. There is no "not now": the run is paid for and frozen, so there is
  nothing to postpone.

`start()` on a held run resumes it (no run consumed), and `active_run()` now ignores
`errored` runs — before, an errored run kept being "resumed" by the next Start and
blocked the player from using the run they still held.

**Close the run is unchanged**, except that an errored run now refuses to serve or
score (`saw_interrupted`), so a stale tab cannot keep playing a run an administrator
has been handed. Its tab-close beacon still abandons.

## Consequences and what was traded away

- **Freezing on the last heartbeat is generous to a player who goes offline on
  purpose.** Someone who cuts their connection on a question, looks the answer up and
  reconnects within the window gets the seconds they had left. That was the client's
  choice over the stricter alternatives (a fresh timer, or scoring the question zero on
  return). Mitigations: the seconds are the ones the player had, not more; the
  heartbeat keeps running in a hidden tab (browsers throttle timers but not below one
  a minute after five minutes, well inside the stale threshold's tolerance for
  ordinary tab-switching); and every resume is logged as `run_resume` so a pattern is
  visible in the Quiz Log.
- **A heartbeat is one small request every three seconds per player mid-run.**
- **Runs with no `last_seen_at`** (played before this change) keep the old
  `expires_at` rule in the sweep. New runs are stamped at start.
- **`finalize_stale( true )`**, used when an administrator switches policy, ignores
  the heartbeat filter so the backlog still settles.
