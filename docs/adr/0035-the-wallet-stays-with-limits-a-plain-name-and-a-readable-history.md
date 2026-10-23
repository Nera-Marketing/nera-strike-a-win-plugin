# The wallet stays: limits, a plain name, a readable history

Client findings #43 and #44. The wallet (woo-wallet) was going to be hidden until John
decided whether players may hold a prepaid balance on a prize-competition site. Lewis
decided on 2 October that **it stays**, which turns the report's "hide the top-up" into
"make it safe and readable". Everything is in `Nera_SAW_Wallet`, the one place that
knows the wallet, rather than in the third-party plugin or the theme.

## Decision

- **Top-up limits, as settings.** Strike A Win -> Settings -> *Wallet top-up limits*:
  £100 per top-up and £250 per day, 0 meaning none. Both figures are provisional until the
  client confirms them. Enforced inside Strike A Win, not by writing woo-wallet's own
  `max_topup_amount`, so one screen owns both and the daily limit sits beside the
  per-top-up one.
  - Every way a top-up can start is checked: woo-wallet's form
    (`woo_wallet_is_valid_wallet_recharge_amount`), the cart and checkout it leads to
    (`woocommerce_check_cart_items`, `woocommerce_after_checkout_validation`) and its REST
    route `me/topup`, which makes the order itself and has no filter to hook
    (`rest_request_before_callbacks`).
  - A day is the calendar day in the site's timezone. Only **paid** top-ups (processing
    or completed, by `date_paid`) count; one still awaiting payment is checked when it is
    made and counts once it is paid. Credits an administrator adds by hand are not
    top-ups and are not limited.
  - Messages say what is left ("...you can add £50.00 more"), with Russian strings.
- **The payment method reads "Wallet".** The theme appends a "✓ Sufficient Balance" badge
  to the gateway's title (`woocommerce_gateway_title`, priority 10), and WooCommerce
  stored that title on the order, so it reached order pages and emails. The title is now
  "Wallet" at priority 99, and `woocommerce_order_get_payment_method_title` rewrites the
  stored title for orders already placed (emails included). "Part wallet, part card" keeps
  its own name, minus any badge. Other gateways are untouched.
- **Balance history is a list on phones.** From 561px up it is the table it was. Below,
  each transaction is a small card (description and amount on one line, date under it),
  because a table that must be swiped sideways to find the Amount column is not a
  statement. woo-wallet's own 600px minimum had to be overridden for this.
- **The "temporary password" notice goes away when it is true to say so.**
  WooCommerce sets `default_password_nag` for a generated password and clears it only on
  its own lost-password form. It is now also cleared when the player sets a password
  through WordPress's reset, or by saving a new password in Account details. And because
  the section's My account is one page that renders every panel (so WooCommerce believed
  it was always on the dashboard), the flag is only visible on the Account details
  endpoint: the notice no longer sits above the wallet and the orders.

## Consequences

- With top-ups off in woo-wallet's own settings (the local install), the limits do nothing
  and nothing changes visibly; they are for the install where top-up is on.
- The daily total reads paid orders for the top-up product, so a refunded top-up still
  counts against the day. Revisit if the client wants refunds to give the allowance back.
- Orders already stored with the badge are corrected when read, not rewritten in the
  database.
