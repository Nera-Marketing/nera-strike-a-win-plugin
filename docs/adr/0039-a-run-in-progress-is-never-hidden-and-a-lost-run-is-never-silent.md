# 0039. A run in progress is never hidden, and a lost run is never silent

Status: accepted (client findings 37 and 42). **Amends [ADR 0030](0030-a-question-clock-starts-when-it-is-served-and-an-interrupted-run-is-held.md)**
in two places, and deliberately differs from the client's spec 06 (see "Spec 06").

## What was wrong

- The lobby, the competition page and the entries page counted only the run balance, so a
  player with a run live or held saw "N runs to play" and Play quiz (or, with no spare run,
  only Buy a run). The server already resumed the live run on Start, but no screen led back
  to it.
- Under "close", a run was only closed when its own clock ran out, minutes later, and the
  player was then shown nothing: "0 runs", "no runs left", and the run's tickets gone.
- ADR 0030 describes a client ping every 3 seconds. The client had stopped sending it
  (the quiz was moved to run in the browser and send its answers once, at the end), so
  `last_seen_at` stood still at the start of the run and any run older than 15 seconds
  looked interrupted, even while its player was answering.

## Decision

- **One question, one answer:** `Nera_SAW_Run::live_state( user, competition )` returns
  `none`, `live`, `held` or `closed`, settling a stale run first (lazily, and in the sweep).
  Every screen asks it.
- **Never a new run while one is live or held.** The play page, the competition page and
  the entries list show the run (`parts/live-run.php`) and hide Play quiz and Buy a run.
  `start()` still returns the live run for any tier.
- **Resume allowed:** "Continue your run" (and "End run and see my results") for a live or
  held run, held for the configured window. The popup is gone.
- **Resume not allowed:** a run that stops checking in is closed at once (`errored`,
  nothing minted, Restore as before) and the player is told so. Other runs stay playable.
- **A deliberate leave** (confirmation on back, refresh or tab close) still ends the run.
- **A heartbeat is back, as a ping only:** one empty request every 10 seconds while a run is
  on screen (`nera_saw_heartbeat_seconds`, 0 = off); the interrupted threshold is 35 seconds.
  It tells the server the player is here; the quiz itself is unchanged.
- **The quiz still runs in the browser.** Each answer is written to `localStorage` (no
  request): run id, answers, tickets, next question. "Continue your run" restores it, and
  the question the player was on comes back with a full clock and may be answered again.
  With no snapshot (another device, cleared storage) the same run restarts at question 1.
- The final submit of a closed run is refused, so a stale tab cannot finish a run an
  administrator has been handed. Order lines count interrupted runs separately.

## Spec 06

The client's "agreed behaviour" (clock never stops, run completes by expiry, no staff step)
replaces both policies. This ADR keeps both settings, as decided by the site owner, so the
clock is still frozen while a run is held. The difference should be confirmed with the client.

## Consequences

- About 10 small requests per run, measured at roughly 750 ms each on a development
  machine; the interval is a filter. A lighter endpoint is the next step if it matters.
- Timing is still reported by the browser, and a restored question has a fresh clock: a
  player can see a question, leave, look up the answer and continue. Accepted.
- Closed runs from before this change remain `errored` until an administrator restores them.
