# The section owns the site root

The client asked for the standalone site to be the site: every page that is not one of
its own goes to the WordPress trash, and the `/strikeawin/` sub-path goes away.

This reverses a rule [ADR 0018](0018-standalone-stands-on-woocommerce.md) and the
legal mirrors were built on: *a standalone page never overrides or alters the
equivalent page on the main site.* There is no main site any more, so there is
nothing to leave alone.

## Decision

- **Addresses.** The root page ("Competitions") is the front page. Every other screen
  is a top-level page beside it: `/how-it-works/`, `/play/`, `/before-you-pay/`,
  `/checkout/`, `/my-account/`, and the five legal screens. A competition is
  `/competition/{slug}/`, the one rewrite rule the router still owns.
- **No prefix by default.** `Nera_SAW_Router::prefix()` returns '' unless the
  `nera_saw_standalone_prefix` option is set. It used to be the root page's slug.
  `Nera_SAW_Router::path_in_section()` is now the one answer to "is this URL part of
  the section", used by the language stamp, the basket context and the post-login
  return check, which each carried a private copy of the prefix test. With no prefix
  everything is in the section except WordPress's own machinery (admin, REST, login,
  cron, static files).
- **Old addresses redirect.** Anything under `/strikeawin/` is 301ed to the same path
  without it, query string kept. `/cart/` goes to Before you pay. The bare `/ru/` goes
  to the home in Russian, because the Russian copy of the old home page it used to
  forward to is trashed.
- **The pages that used to sit behind the section's screens are the section's pages.**
  Each setting that named an old page now names the section's own:
  - WooCommerce Checkout, My account, Terms → the section's Checkout, My account,
    Terms and Conditions.
  - WooCommerce Cart → Before you pay. `is_cart()` is therefore true there, which is
    what a cart page should be, and `wc_get_cart_url()` lands where the player
    already goes.
  - WordPress Privacy Policy → the section's Privacy Policy.
  - Help and support, player protection, Complaints (the three sibling plugins'
    own page settings) → the section's mirrors, so their footer strips and
    self-heal checks find a live page and stop creating copies.
  - WooCommerce Shop and Refunds, the lottery plugin's three pages, the posts page →
    cleared. None has a section equivalent, and a Shop page that is also the front page
    makes WooCommerce turn the home into a product archive.
- **Terms and Privacy hold their own text.** They used to mirror the old page's content
  live. Their screens now print their own `post_content` (copied once from the old
  page), and the Russian text is the Polylang translation of them.

## How it was done

`tools/flatten-standalone.php` does the data side and is meant to be run once per
environment: a dry run by default, `--apply` to write. It repoints the settings above,
copies the legal text, links Russian translations, trashes every other page (all
languages), makes the section's pages top-level and renames the root slug from
`strikeawin` to `competitions`. It writes an undo file with the previous option
values, trashed IDs and old parents. The WordPress trash keeps pages for 30 days.

## Consequences

- Anything outside the section that used to live on the old theme (About, Contact,
  Winners, Blog, the Shop archive's page) is a 404. The theme is still the skin of a
  404 and of WooCommerce's product archive, which is a post-type archive and not a
  page, so `/all-competitions/` still answers in the theme's skin.
- `Nera_SAW_Standalone_Basket::context()` now says "section" for every front-end
  request, so the second, main-site basket slot is no longer reachable.
- A site that does want a prefix can still set one, but then needs its pages to hang
  under a parent page with that slug, as before.
