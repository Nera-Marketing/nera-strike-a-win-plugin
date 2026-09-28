# Odds are authored into the product description, not calculated

The front end never computes a player's chance of winning. Whatever the client
writes into the competition's product description is what appears.

**Why:** the two source documents disagree, and this resolves the conflict rather
than splitting it.

- `requirements.md` L40: *"the client writes odds text into the product description.
  No calculation on the front end."*
- `handover/front-end-needs`: *"The dossier's hard rule is odds from the real pool,
  never typed in."*

`requirements.md` is the newer document — it records the call of 14 September 2026 —
and it is the one written after the client was in the room. The handover page is
built from the July admin guide and says of itself that where the two diverge, it is
the one that is behind.

**The risk is real and is being accepted.** An authored figure can drift from the
actual ticket pool: the client edits the stock and forgets the sentence, and the site
then advertises odds it does not offer. For a paid prize competition that is a
compliance exposure, not a typo.

An admin warning — flagging when the description's figure diverges from the real
pool — was offered and not taken. If this ever bites, that is the cheap remedy and
this paragraph is the argument for it.

**Consequence:** nothing in the plugin reads stock to produce an odds string, and
nothing should be added that does. If a future ticket asks for "live odds", it is
reversing this decision and needs the client's agreement first, not a filter.
