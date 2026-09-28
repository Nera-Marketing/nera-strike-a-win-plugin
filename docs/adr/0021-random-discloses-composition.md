# Under `random`, a competition discloses its composition but never its order

When Quiz Method is `ladder`, the competition page shows what the prototype
designed: stage chips, numbered nodes coloured by difficulty, "N questions ·
M stages".

When it is `random`, the per-level specification rows stay exactly as they are —
"3 × Easy · 1 ticket each", and so on — but the staged node track is replaced by a
neutral, uncoloured track of numbered nodes and the line "mixed difficulty — random
order". A question's level chip appears when that question arrives, not before.

**Why:** `draw_for_run()` picks every slot when the run starts, so the server knows
the entire sequence before the player sees question one. Rendering that sequence
would tell them that question 3 is the Expert — which is a product decision, not an
implementation detail, and it is the one the prototype never had to make because it
only ever designed the ladder.

Three options were weighed:

- *Composition only* — chosen. The player knows what they are buying, which is what
  the prototype's own "Know what you're buying" panel exists to do, without knowing
  what is coming next.
- *Nothing beyond a question count.* Cheapest, and it hollows out the specification
  panel that the rest of the page is built around. A player paying £20 is entitled
  to know the run contains an Expert question.
- *The real order.* Most transparent, and it removes the thing `random` is for. If
  the sequence is visible in advance there is no meaningful difference from `ladder`
  except that the shape is uglier.

**Consequence:** every surface that quotes "N questions · M stages" needs a second
form — the competition card on the list, the specification rows, the pre-payment
summary and the How it works copy. Stage-count copy is therefore not safe to
hardcode anywhere; it reads from the resolved Quiz Method for that competition.

The run screen follows the same rule: under `random` there is no stage interstitial
and no "Stage N of M" chip, because there are no stages (see CONTEXT.md, **Stage**).
