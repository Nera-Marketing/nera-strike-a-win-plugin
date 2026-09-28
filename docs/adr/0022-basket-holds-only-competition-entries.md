# A basket holds Strike A Win entries or ordinary products, never both

In **both** modes, a Strike A Win entry never shares a WooCommerce cart with an
ordinary product. Adding an entry to a basket that already holds ordinary products —
or the reverse — stops and asks, offering to clear the basket. It never empties it
silently.

**Why:** in standalone the two live on parts of the site that cannot see each other.
A customer who reaches `/strikeawin/checkout` and finds a t-shirt in the summary has
been shown an item from a shop the section deliberately hides. Segregating removes
that whole class of confusion, and it keeps a competition order purely a competition
order — which matters for the order emails, the Report, and the grant ledger that
reads those order lines.

**This changes mix mode, which was specified as unchanged.** That is deliberate and
was confirmed after being raised. There is no cart segregation in the plugin today —
no `woocommerce_add_to_cart_validation` handler, nothing — so mix-mode customers who
can currently buy an entry alongside a t-shirt will no longer be able to. It needs a
line in the release notes, and it is recorded here so that a future reader does not
diagnose it as a regression.

**Ask, do not clear.** Silently removing what the customer already chose is the
cheaper implementation and the worse one: the items disappear with no explanation
and the customer's next action is a support ticket. Stopping with a choice costs one
notice and a confirmation.

**Hook ordering.** `nera-age-shield-plugin` already registers on
`woocommerce_add_to_cart_validation` at **priority 20**, where it enforces the 18+
gate. This check registers **below** that priority, so an under-age rejection is
never masked by a basket message. Getting this backwards produces a customer who is
told about their basket when the real reason they cannot buy is their age — which is
a compliance control being obscured by a convenience one.

**Consequence:** any future add-to-cart rule in this plugin joins the same handler
and inherits this ordering constraint. A second, independently registered validator
will eventually race this one.
