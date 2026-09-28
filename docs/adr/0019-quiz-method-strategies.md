# Quiz Method is a strategy, and `random` remains the shipped default

`draw_for_run()` no longer shuffles unconditionally. The slot order comes from a
strategy chosen by the **Quiz Method** setting:

- **`random`** — levels interleaved across the whole run, exactly as
  `shuffle( $bag )` does today. Slot 1 can be Expert and slot 10 Easy.
- **`ladder`** — levels ascending by their existing `rank`, questions shuffled only
  *within* a level, so the run has stage structure without being identical for every
  player.

Global default with a per-competition override, matching how the ladder's rewards,
the timer and the tiers already resolve. **The global default ships as `random`.**

**Why the setting rather than a fix.** The prototype is unambiguous: `BANDS` is
built in declaration order, `order` is the identity mapping and is never shuffled,
and `comps.py` always declares Easy → Medium → Hard → Expert → Final. The only thing
it shuffles is the four options inside a single question. It carries per-stage copy
— "Warm up", "Stepping up", "Getting tough", "Final question" — a stage interstitial
and a "Stage N of M" chip, none of which mean anything without ascending order.

The plugin does the opposite, and it does so *deliberately*: the comment at the
shuffle cites an ADR and a `CONTEXT.md` entry, both of which are lost
([ADR 0000](0000-lost-decision-records.md)). Meanwhile the plugin's own header
describes the product as a "timed **increasing-difficulty** quiz".

So the evidence points three ways at once: the prototype says ladder, the header
says ladder, the code says random on purpose for a reason nobody can now produce.
Picking one and deleting the other would be guessing, and would throw away work
whichever way it went. Making it a setting costs two days and settles the question
per competition instead of per argument.

**Why `random` is the default.** Any existing install upgrades into precisely the
behaviour it already has. No live competition changes shape under a player who is
part-way through a draw, and nobody has to be told about a behaviour change they did
not ask for.

The trade-off is accepted and real: **a fresh install does not match the prototype
until someone switches it.** The setting's description must therefore say which mode
the prototype was designed around, or the next person to build a site from this
plugin will report the ladder screens as broken.

**Alternatives rejected:**

- *Default `ladder`.* Matches the prototype out of the box, at the price of silently
  changing every running competition on upgrade. For a paid skill competition mid-draw
  that is not a defensible trade.
- *`random` for existing competitions, `ladder` for new ones.* Gets both, and leaves
  an install where two competitions behave differently for reasons visible only in a
  migration note. Rejected as a support problem.

**Scope.** This record covers the **engine** only — the strategy inside
`class-question-bank.php`. The setting field, its per-competition override and the
upgrade migration belong to the settings work; the two on-screen presentations
belong to the competition page and the run. Keeping them apart matters because the
engine change is two days and the presentations are already budgeted elsewhere;
bundling them produced a five-day estimate that double-counted both.

**Consequence:** `Stage` becomes a meaningful word only under `ladder`
(see CONTEXT.md). Under `random` a run has levels but no stages, so every surface
that says "N questions · M stages" needs a second form — which is
[ADR 0021](0021-random-discloses-composition.md).
