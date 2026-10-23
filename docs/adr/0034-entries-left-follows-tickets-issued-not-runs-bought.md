# "Entries left" follows tickets issued, not runs bought

Client finding #49. Buying one Standard run moved the public "Entries left" from 98,863
to 98,822 before a single question was answered. `Nera_SAW_Competition_Spec::stock()`
computed `remaining = total - won - reserved`, and a bought run holds its whole ceiling
(the perfect run, 41 tickets there) in `reserved` the moment it is paid.

That hold is not a mistake. [ADR 0001](0001-reserve-at-checkout-init.md) reserves at
purchase so the pool can never be oversold, and it must stay. What was wrong is that one
figure did two jobs.

## Decision

`stock()` now returns two figures and the screens use the right one:

| Field | Meaning | Used for |
|---|---|---|
| `public_remaining` | `total - won` | What a player is told: the hero's "Entries left", the card's "N entries left" pill, the low-stock threshold. Moves when tickets are WON. |
| `remaining` | `total - won - reserved` | What can still be SOLD: sold-out, the maximum on the runs stepper, the cart. Never printed. |
| `held` | `reserved` | The admin Report. Not shown to players at all (the client's call: "separately or not at all"; not at all). |

"Issued" is `Nera_SAW_Competition_Config::tickets_won_total()`: confirmed tickets of
finalized runs, the figure the ceilings already use, so the public number and the
ceilings cannot disagree. It is not a count of LFW ticket posts; the two diverge when an
order is cancelled after a run was played (LFW removes the tickets, the run keeps its
total). That is a separate problem, recorded in the demo-data review.

## Consequences

- A competition can read "Sold out" while "Entries left" is still above zero, when
  runs in progress hold the rest of the pool. The held tickets return to the pool if
  those runs score less than their ceiling, so this is truthful but may look odd; the
  card pill says "Sold out" and nothing public explains the hold.
- The runs stepper's maximum is now per tier: the sellable remainder divided by that
  tier's perfect run, capped at 99. It used to be the remaining TICKETS, a different
  unit.

## Same change, for the record

- **One date format** (finding #12): `Nera_SAW_Date::display()`, "Sun 14 Sep, 9:00pm"
  (Russian: "вс, 14 сен, 21:00"), on every screen that shows a date.
- **Enter button and stepper** (#11): "Enter for £50 (2 runs)", rebuilt in the browser
  from the tier and quantity (`competition-buy.js`); minus/plus around a real number
  input. A whole total drops ".00". The `saw_cd_cta` field is no longer used by this
  screen.
- **Countdown** (#16): a small chip on each card (top right, opposite the stock pill)
  and a "Time left" fact on the competition page. It runs from the server's clock, not
  the visitor's, and closes the buy form when it reaches zero.
