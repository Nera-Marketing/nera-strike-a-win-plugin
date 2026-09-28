# Age verification follows the mode, and that is temporary

> **Amended 17 Sep 2026.** Standalone now *does* consult `nera-age-shield-plugin`
> when it is installed: the entry gate asks the age question through it, and shows it
> already satisfied when the database says so. With the plugin absent the gate keeps
> an 18+ self-declaration that is recorded nowhere. See
> [docs/LANGUAGE-PLAN.md §8.2](../LANGUAGE-PLAN.md).
> The rest of this record stands — mix mode is unchanged, and registration and
> pre-payment remain where verification actually happens.

In **mix**, Strike A Win does nothing about age. `nera-age-shield-plugin` already
collects a date of birth at WooCommerce registration and blocks add-to-cart for an
unverified account, and the main site is where the player registers.

In **standalone**, Strike A Win collects the date of birth in its own registration
form and runs its own 18+ check, rendering the prototype's `register-under18`
screen — a calm rejection, not an error toast.

**The client has marked this temporary.** It is recorded because the seam it leaves
is invisible until someone walks into it.

**Why now:** standalone registration is the plugin's own screen, designed and
specified in the prototype, and it is reached without ever touching the main site's
account pages where age-shield hooks. Wiring standalone into age-shield would mean
either rendering another plugin's fields inside these templates or hard-depending on
a plugin this one may ship without. Neither is worth doing for a flow the client has
already said they intend to revisit.

**The seam.** If both systems are live and a site later switches mode, a player
verified in one is not verified in the other and is asked for their date of birth a
second time. Two stores hold an opinion about the same person's age, and a
compliance question — *which accounts were cleared, and how?* — has two answers.
That is precisely the distinction age-shield's own
`_nera_age_verified_source` exists to keep straight, and this decision reintroduces
the ambiguity it was built to remove.

**When it is revisited,** the fix is small and one-directional: Strike A Win reads
`Nera_DCMS_Storage` when that class exists and falls back to its own store only when
it does not. Age-shield stays the authority wherever it is installed. What must not
happen is the reverse — age-shield learning about Strike A Win — because that makes
a general compliance control depend on one product feature.

**Consequence:** standalone gains its own date-of-birth storage. Treat it as
personal data on the same terms age-shield does: encrypted at rest, erased with the
account, and never given a placeholder value to make a check pass. Do not store a
fabricated date of birth to clear a test account — age-shield's own ADR 0001
(`nera-age-shield-plugin/docs/adr/`) rejects that shortcut for reasons that apply
here unchanged: an invented date is indistinguishable from a real one and hands any
later compliance export a fabrication.
