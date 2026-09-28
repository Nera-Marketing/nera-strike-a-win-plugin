# Standalone sells one entry at a time, and has no basket screen

In standalone mode the flow is **competition → Before you pay → checkout**. There is
no cart page. Adding an entry **replaces** whatever the basket held.

**Why:** the design has no cart artboard and no checkout artboard, across all 29
screens. That is not an omission — the pre-payment screen shows one competition, one
tier, one price and the words "one run". A basket holding several entries would be a
screen with nothing on it to decide.

Confirmed with the client after being raised, alongside the choice to keep payment on
WooCommerce checkout rather than invent a payment step.

## This narrows ADR 0022

[ADR 0022](0022-basket-holds-only-competition-entries.md) says a basket mixing entries
with ordinary products should **stop and ask** rather than clear. That still holds in
**mix** mode, where the shop and the competitions are the same site and the customer
chose both.

It does not hold in standalone. There, catalogue isolation hides the shop from the
section entirely, so a confirmation dialog would name a product from a site the player
cannot see and cannot get back to. What they get instead is a notice on the next
screen saying what was removed.

## Which side gives way

Segregation runs **both ways**, and the answer differs by direction:

- **Adding an entry clears the basket.** Here the basket is a selection in
  progress, and one entry is all it ever holds.
- **Adding an ordinary product removes only the entries.** The rest of that basket
  belongs to the main site, and emptying it because of a competition the shopper
  may have forgotten about would be the section reaching into a shop it does not
  own.

A basket that is already mixed — built before this rule shipped, or by a path that
never runs the add-to-cart filter — is repaired on `woocommerce_check_cart_items`,
which fires on both the cart and the checkout. Without that, the rule would only
ever govern the next addition and the main site's cart would keep showing entries
it is not supposed to sell.

**Entries removed to make room for another entry are not mentioned.** Swapping one
competition for another is the section's ordinary path — a player changing their mind
on the list — and a warning every time turns the normal action into one that looks
like a mistake. Only non-entry items are named.

## Two baskets, swapped by context

> **Supersedes the three sections below** (17 Sep 2026, later the same day). The
> section and the main site keep **separate baskets**. Adding an entry does not touch
> the main site's shopping, and adding a product does not disturb an entry.

WooCommerce has one cart per session, so "two baskets" is two saved copies and a
swap: the session holds `nera_saw_basket_section` and `nera_saw_basket_main`, and the
right one is written into `session['cart']` at `wp_loaded` priority 1 — before
`WC_Cart_Session` hydrates at priority 5. WooCommerce then prices and validates it
exactly as it would any basket, which is the point: two baskets, one cart
implementation.

**Context is decided from the URL, never a conditional tag.** At that priority the
query is not parsed and `is_page()` answers false for everything. Three signals, in
descending trust: an explicit `saw_basket` in the request, which the section's own
form and script send; the path, for anything under the section prefix; and the
referer, for WooCommerce's AJAX endpoints — `/?wc-ajax=checkout` has no path of its
own and the page that fired it is the only thing that knows.

**An empty basket needs enforcing.** `get_cart_from_session()` assigns contents only
when the session holds some, so an empty session cart leaves whatever the cart object
already had. An ordinary request starts with an empty object and never shows this;
swapping baskets is precisely the case that would. `enforce_empty_basket()` closes it.

**Carts that predate the split are main-site carts.** A context with nothing saved
starts empty in the section and inherits the existing basket on the main site —
treating a stranger's shopping as a competition entry would be the worse guess.

### What this made unnecessary

Three rules existed only because both sides shared one cart, and all three are gone:

- segregation in both directions, which emptied one side to protect the other;
- `unmix()`, which repaired baskets holding both;
- hiding entries from the main cart, its counter and its fragment hash.

What survives is the section's own rule: **one entry at a time**, because that is what
Before you pay shows. An ordinary product can no longer be in this basket to remove.

---

## ~~The main site's cart does not show entries at all~~

*(Superseded — kept because the four options weighed here are the reasoning behind
the split above, and the rejected ones are still worth not repeating.)*

In standalone, a basket holding nothing but entries makes the main site's cart render
its **empty state**. No notice, no redirect, no partially-shown line.

Four answers were weighed and three were rejected for reasons worth keeping:

- **Leave the entry visible** — puts a Strike A Win product on the one page the
  section exists to keep them off.
- **Redirect the cart** — takes over a page the section does not own.
- **Hide the line, keep the totals** — shows a total with no visible cause. The only
  option that could mislead about money.
- **Print an explanatory notice** — accurate, and still a Strike A Win message on a
  main-site page. The section's job here is to be absent, not politely present.

The counter is included: a header badge reading "1" over a cart that says it is empty
is a disagreement a shopper reports as a bug, and they would be right.
`woocommerce_cart_contents_count` is a display figure — `is_empty()` counts the cart
directly — so nothing about what is in the basket, or what it costs, changes.

## Two checkout pages, one WooCommerce

`/checkout/` is the main site's and `/strikeawin/checkout/` is the section's. They are
separate pages that share WooCommerce's cart, gateways and order pipeline through
hooks, and nothing else. `wc_get_checkout_url()` resolves to whichever the basket
belongs to.

## Checkout is WooCommerce's, not a copy

The section renders WooCommerce's own checkout page through its own template. The URL
is the real one and `is_checkout()` is true.

A separate page holding `[woocommerce_checkout]` would look identical and be quietly
broken: PayPal Payments, Stripe and every express button decide whether to render from
`is_checkout()`, so the copy would lose exactly the payment methods the section needs
most. This is the same class of failure as the `woocommerce_gateway_description` filter
that PayPal Payments never fires — a gateway's behaviour hanging off a call that looks
incidental.

**Consequence:** the theme's own checkout JavaScript is dequeued on this screen along
with the rest of the theme's assets. On this site that includes the terms-checkbox
guard on the PayPal Smart Button. If the standalone checkout needs that guard, it has
to be reimplemented inside this plugin — it will not be inherited.

## Consent is native, not scripted

The two checkboxes on Before you pay carry `required`. The browser refuses to submit
until both are ticked, with no script involved.

A JavaScript gate fails open the moment anything earlier on the page throws, and what
it is gating is the player's confirmation that a run can earn nothing and that no
refund is due. Native validation cannot fail open.
