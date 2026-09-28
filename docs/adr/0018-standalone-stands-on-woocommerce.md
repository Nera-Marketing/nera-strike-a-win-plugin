# Standalone mode serves its own pages, but stands on WooCommerce

In **standalone** mode the plugin renders its own section of the site — competitions
list, competition detail, how it works, the run, account, cart and checkout — from
its own templates, under one configurable URL prefix (`/strikeawin/…`), bypassing
the active theme entirely. Strike A Win products disappear from the main site's
shop, search, archives, related products, REST and sitemaps.

What it does **not** do is leave WooCommerce. The same WordPress users, the same
`WC_Order` records, the same gateways, tax, emails and refunds. Cart and checkout
are WooCommerce's own functions, hooks and templates with this plugin's markup and
styling over the top. **Mix** mode is untouched and remains the default.

**Why:** the brief asks for a front end "independent of the main site", and the
prototype is a complete site — its landing page is a competitions list, not a
product archive. But independence is wanted at the level of *presentation*, and the
obvious over-reading is expensive. A separate user table or order store would cost
the grant ledger (which is keyed to WooCommerce order items), order emails, refunds,
the Report, and every compliance record that currently lives on the order.

Three readings were weighed for cart and checkout specifically:

- *Plugin templates over WooCommerce* — chosen. About six days, low risk, and it is
  what `requirements.md` L91 actually asks for: "Checkout is WooCommerce styled to
  the same tokens."
- *Plugin cart, WooCommerce checkout* — two baskets to keep in step, with the basket
  hold, stock and pricing computed in two places. Rejected as a synchronisation
  problem bought for no gain.
- *Plugin cart, checkout and payment* — rejected outright. It takes on PCI surface,
  abandons `WC_Order`, and requires rebuilding the grant ledger, reservations and
  ticket award on top.

**The prototype has no cart or checkout screen.** Searching the whole package —
`site/`, `site-prototype/`, the composer — produces zero files mentioning either;
payment is simulated end to end. So "cart and checkout to the prototype" had no
design to follow, which is part of why the WooCommerce reading wins: there is
nothing being given up.

**A subdirectory, not the site root.** The prototype treats its competitions list as
the homepage, and `requirements.md` L69 calls it "The homepage". The section
nonetheless sits beside the main site rather than replacing it. The reason is
operational rather than aesthetic: with a prefix, **both modes run on one site at
once**, so QA exercises mix and standalone without two environments, and switching
the setting off does not rewrite every URL. If a site is ever Strike A Win and
nothing else, a "use the competitions list as the homepage" option is a small
addition on top of this — the reverse is not.

**The play page stays.** Every link into a run already resolves through
`nera_saw_get_play_url()`, so standalone redirects that one function at its own
route and leaves the existing page, its shortcode and old order-email links working
in mix.

**Consequence:** the plugin acquires URL routing, a template loader and theme
bypass, none of which it has today — no `template_include`, no `add_rewrite_rule`,
no `templates/` directory. That infrastructure is the single largest new component
of the standalone work, and it is infrastructure the plugin will then own forever.

Because mix is not replaced, every screen, hook and test now exists in two modes.
That doubling, not the pages themselves, is the largest cost in the plan.
