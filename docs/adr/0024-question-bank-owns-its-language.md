# The question bank carries its own language, and Polylang is not involved

> **Superseded on 17 Sep 2026 by
> [ADR 0028](0028-polylang-owns-the-languages-strike-a-win-owns-the-reach.md).**
> Polylang is the multilingual engine after all, scoped by a reach control in its own
> settings. The reasoning below is kept because the three objections it raises are the
> exact problems ADR 0028's "Strike A Win only" option has to solve — read it as the
> requirements list for that option, not as a decision still in force.

A taxonomy, `saw_language`, is registered for the `saw_question` post type and for
nothing else. Each question carries exactly one language term. The draw filters on
it with a `tax_query`, using the **run's** language — never an ambient site
language, because under the default scope the site does not have one.

Polylang is not a dependency. If **Language Scope** is ever set to
`whole_standalone`, Polylang is introduced *then*, for the section's own content,
and the existing `saw_language` terms map one-to-one onto its languages.

## Why the scope setting decides the mechanism

Polylang's unit of work is *"the site is currently in language X"*. The default
scope, `questions_only`, is defined by the site **not** having a current language —
the interface stays put and only the questions change. Asking Polylang to do that is
asking it to not do the thing it is for.

## What an earlier revision of this record got wrong

It chose Polylang, on the reasoning that its admin language column, filter and
switcher come for free rather than being rebuilt. That was decided before checking
Polylang's URL behaviour, and three facts change the answer:

1. **Polylang has no "language without URLs" mode.** Every option in its URL
   Modifications settings puts the language somewhere addressable: `?lang=ru`, a
   `/ru/` directory, a subdomain or a separate domain. "Hide the language code" only
   hides it for the *default* language. So declaring a second language grows a
   Russian URL variant of **every page on the site**, including pages with no
   connection to Strike A Win — crawlable, duplicated, and a support surface that
   nobody asked for.

2. **The settings screen is a foot-gun we cannot disarm.** Polylang lists only
   `public` post types there. `saw_question` is registered `public => false`, so it
   never appears and must be enabled through `pll_get_post_types` — which is fine.
   But `product` and `page` *do* appear, unticked. One administrator ticking
   "Products" starts translating WooCommerce across the whole shop. The plugin
   cannot prevent that, and the blast radius is the client's catalogue.

3. **Nothing in Strike A Win reads a locale today.** Searching `includes/` and
   `src/` for `get_locale`, `determine_locale`, `pll_*` or a `lang` request
   variable returns nothing. There is no existing coupling that Polylang would be
   tidying up — it would be introducing the first.

The free admin UI is worth roughly a day. It is not worth a site-wide language
layer on a site that wants one language.

## Why a taxonomy rather than post meta

Post meta was the other candidate and is nearly as cheap. A taxonomy wins on the
things this feature actually does:

- The draw is already a `WP_Query` with a `tax_query` on `product_cat`; language
  joins that instead of adding a `meta_query`, which on a large bank is the
  difference between an indexed lookup and a scan.
- `show_admin_column` gives the question list its language column with no code,
  and a `restrict_manage_posts` dropdown gives the filter with very little.
- **D13's sufficiency check becomes countable.** "Does Russian have enough Expert
  questions for this competition?" is a term-count query, not a meta aggregation.

## The run's language, not the site's

`runs.language` and `config['language']` already exist — written on every run and,
as the gap report found, never read anywhere. This is what makes them mean
something. The draw resolves the language for *that run* and passes it down; two
players can be mid-run in different languages on the same competition at the same
moment, and neither affects the other or the site.

`draw_for_run()` therefore takes the language explicitly. It must never call
anything that resolves "the current language" — that function does not exist in
this design, and reintroducing it is how the site-wide leak gets in through the
back door.

## Consequences

- **A migration exists if `whole_standalone` is ever chosen.** It is a term mapping,
  not a rewrite: each `saw_language` term corresponds to a Polylang language, and
  the questions keep their assignment. Cheap, and deferred until someone actually
  wants it.
- **The admin UI is ours to build.** The column and filter are small; the piece that
  is not optional is the bank-health readout, which compares question counts per
  language per level against every competition's distribution. Without it a thin
  Russian bank is invisible until a player meets a repeated question, because the
  draw falls back to already-seen questions silently rather than failing.
- **The CSV importer gains a `language` column**, with validation and a matching
  export, because that is how the client's Russian set actually arrives.
- **Every existing question is backfilled as English on upgrade.** A question with
  no language term must never be drawable, or a bank with one untagged row silently
  serves it to a Russian player.
- The estimate for the language work does not drop. Removing a third-party
  integration removes *risk*, and that risk was priced into the original figure;
  it converts to margin rather than to a smaller number.
