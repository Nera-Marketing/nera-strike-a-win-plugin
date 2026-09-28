# The decision records 0001–0017 are lost, and this is what survives of them

`docs/adr/` did not exist in this repository until 2026-09-16. The code, however,
cites seventeen ADRs by number and cites `CONTEXT.md` by name, so the records
existed somewhere and were never committed — or were committed and lost in a
history rewrite.

This is a problem with teeth. `class-question-bank.php:148` reads:

```php
shuffle( $bag ); // Random level order weighted by remaining count.
// (ADR: Slot = random level order). See CONTEXT.md "Slot".
```

That is a *deliberate* choice with a *recorded* rationale, and the rationale is
gone — while the plugin's own header describes the product as a "timed
**increasing-difficulty** quiz". Without the ADR there is no way to know whether
random order was a considered improvement on the header's promise or a drift away
from it. [ADR 0019](0019-quiz-method-strategies.md) resolves the situation by making
both behaviours available rather than by guessing which was intended.

**Numbers 0001–0017 are retired.** New records start at 0018 so that a future reader
finding `ADR 0006` in a comment is not sent to an unrelated document.

## What the citations still tell us

Reconstructed from the surviving comments only. Each line is what the code claims
the ADR decided — not a restoration of its reasoning, which is gone.

| ADR | Cited at | What the comment claims it decided |
|---|---|---|
| 0001 | `class-database.php:11`, `class-reservations.php:3` | A reservation ledger exists so a competition cannot oversell its ticket pool |
| 0002 | `class-integrations.php:11` | Spin-to-Win pre-claim suppression was removed when STW was dropped from the core |
| 0003 | `class-question-cpt.php:13`, `class-run.php:818` | Questions soft-delete and permanent purge is blocked; minted numbers are allocated to slots deterministically and sum to the minted total |
| 0004 | `class-settings-admin.php:8` | Global settings appear on every competition's Strike A Win tab and are overridable there |
| 0005 | `class-settings-admin.php:6`, `class-constants.php:28` | Admin-configured timer bounds are themselves fenced by a code-level hard clamp |
| 0006 | `class-cart-entry.php:29`, `class-plugin.php:108` | Quantity is the number of runs bought; reservation and grant happen at **payment**, not at checkout-init, and the old TTL sweep was retired |
| 0007 | `class-frontend.php:103, 398` | No URL may start a run; starting is in-page and token-gated, with a per-user token |
| 0010 | `class-report-admin.php:462`, `class-runs-list-table.php:214`, `class-run.php:802` | A deliberate leave (**abandoned**) is distinguished from a silent lapse (**expired**), and both are surfaced distinctly in the Report |
| 0011 | `class-run.php:397, 423` | Tickets are minted even when the client never reaches the results screen; normal completion does not take that path |
| 0013 | `class-report-admin.php:72, 414` | An errored run can be restored by an administrator, which refunds the run and voids it |
| 0015 | `class-question-import.php:3` | The question bank has CSV import and export |
| 0016 | `class-question-cpt.php:29, 57` | A derived plain-text mirror of the answers exists so the admin list can search answer text |
| 0017 | `class-question-import.php:171, 241` | Import rows are classified overwrite / warning / rejected, counted once each, and warned rows always save as draft |

Numbers 0008, 0009, 0012 and 0014 are not cited anywhere in the source. They may
never have existed, or may have covered code since removed.

**Why:** the cheap response to a missing ADR is to delete the citation and move on.
That destroys the only remaining evidence that a decision was made at all, and the
next person to meet `shuffle( $bag )` reads it as an arbitrary line rather than as a
choice someone argued for. Keeping the citations and recording what they point at
costs one file and preserves the shape of the reasoning even where the reasoning
itself is gone.

**Consequence:** a comment citing ADR 0001–0017 points *here*, not at a document
that explains it. Before reversing any behaviour those comments describe, treat the
absent rationale as a real risk rather than as permission — the decision was
considered once, by someone with context we no longer have.
