# The checkout is four fields, two countries and the wallet

Client findings #17, #21 and #29, decided by Lewis on 2 October. A run is a digital entry,
so the checkout asks for as little as it can. Everything is in `Nera_SAW_Checkout`, applies
only to the section's own checkout (a basket of competition entries, the page and the AJAX
that rebuilds it), and never to a wallet top-up or the admin.

## Decision

- **Billing form: first name, last name, email, country.** Street, apartment, town, county,
  postcode, phone, company, order notes and the shipping block are removed
  (`woocommerce_checkout_fields` at priority 99, after the theme's own layout filters).
  Taxes are off, so no postcode is needed to calculate anything. The date of birth is taken
  at registration.
- **Country: United Kingdom and Ireland.** `woocommerce_countries_allowed_countries`, which
  WooCommerce also checks a posted country against, so the server refuses any other.
- **Consent is the two ticks on Before you pay, and it is now checked on the server.** The
  checkout's own terms tick is gone (its privacy sentence stays, as text). With that tick
  removed the Before you pay form is the only record of the player's agreement, and it was a
  browser-side `required` on a GET form: anyone could open `/checkout/` directly. So the
  checkout page takes the two ticks (`saw_confirm_age`, `saw_confirm_rules`) from the form,
  remembers them in the WooCommerce session against a hash of the basket, and redirects a
  visitor who has not given them back to Before you pay. A submitted checkout without them is
  refused (`woocommerce_after_checkout_validation`): "Please confirm your age and the rules on
  the previous screen before you pay."
- **Payment methods: Wallet only, as a setting.** Settings -> Checkout lists every
  WooCommerce gateway; the ticked ones show (`woocommerce_available_payment_gateways`),
  Wallet by default. Adding a provider later is ticking a box. Nothing is asked of
  WooCommerce about which gateways are *available* while filtering them: the theme's wallet
  code is wired to that call and re-enters itself without bound.
- **PayPal Payments is kept off the page while it is not ticked.** It loads its SDK from
  paypal.com and prints button containers on the checkout whether or not its gateway is
  offered; both are removed (scripts and styles dequeued, containers hidden) unless a
  `ppcp*` gateway is ticked.
- **The card gateway is called "Debit / credit card"** (`woocommerce_gateway_title`, in this
  plugin only) instead of "Pay with Apple / Google Pay ( Debit & Credit )".
- **Part wallet, part card has its own value, `nera_wallet_split`.** Its radio used the card
  gateway's `cashflows_card`, so the server could not tell the two apart (#29). The row is
  shown only when it and the card gateway are both ticked. A posted `nera_wallet_split` is
  mapped back to `cashflows_card` for WooCommerce (which knows no such gateway) in
  `woocommerce_checkout_posted_data`, and the order records `_nera_split_payment = yes` and
  the title "Part wallet, part card".
- **No coupon prompt, by default, with a setting to bring it back.** Requested alongside the
  phone field. Note that it is also how a coupon *prize* from an offline draw is redeemed
  (`Nera_SAW_Draw_Prizes` makes single-use coupons), so switch it on if prizes are awarded.

## Consequences

- With only the wallet offered, a player with no balance cannot pay; funding the wallet is
  handled by the wallet itself and is out of scope here.
- The pool-capacity gate still refuses an add-to-cart the competition cannot back, so a
  checkout can only be reached for a basket that exists.
- Consent is per basket: changing the basket asks again.
- Not exercised end to end: a real card or PayPal payment (no provider is agreed), and the
  theme's split-payment JS with a live card. The mapping and the radio's value are tested.
