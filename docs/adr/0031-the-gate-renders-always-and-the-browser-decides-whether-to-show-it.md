# The gate renders always, and the browser decides whether to show it

Client finding #1 (report #f1, rounds 1 and 2): the entry pop-up came back on every
page for signed-out visitors.

## What was wrong

[ADR 0027](0027-the-gate-chooses-the-language-the-header-changes-it.md) made the pop-up
a server decision: `gate_due()` returned `! entered()`, so the markup existed only
when the request carried no `nera_saw_entry` cookie. That is correct for an uncached
request and wrong behind a full-page cache (Breeze on production):

- A cached page was built for whichever visitor hit it first, and served to everyone
  after, with no `Vary` on the cookie. If that first visitor had no cookie, the gate
  was baked into the page for all.
- The cookie was only set when the URL carried the gate's query string
  (`saw_entry` with `saw_over18` or `saw_gate_closed`). Anything that did not reach
  PHP uncached, such as a cached response to that URL, set nothing.

## Decision

**The server always renders the gate on a standalone screen, and the cookie, read
in the browser, decides whether it is visible.**

- `gate_due()` now says only "this is a standalone screen". The initial `hidden`
  attribute reflects the cookie when PHP did see it, which keeps an uncached,
  cookie-bearing request correct without JavaScript.
- `entry-gate.js` reads `document.cookie`: absent means reveal, present means hide.
  This is also what un-bakes a cached page, in either direction.
- The same script writes the cookie on Enter and on Close, with the path, domain,
  `Secure` and lifetime PHP passes in `data-*` attributes (`cookie_data()`), so the
  answer no longer depends on a query string reaching an uncached request. The
  server-side `remember_entry()` stays, for visitors without scripting.
- Without scripting, a `<noscript>` rule shows the gate even where a cached page
  carries it `hidden`. ADR 0027's rule that the pop-up works with scripting off
  stands.

Considered and rejected: honouring the cookie server-side and keying or excluding the
cache on it. It works only if every cache layer in front of the site is configured
for it, and Breeze rules are the part of this stack the plugin does not own. A page
that is correct whatever the cache did needs none.

## Language

- **Closing the pop-up without choosing means English.** The client's own rule, now
  also stated for the client-side path: the close control sets the cookie, and on a
  non-English page follows its link (`saw_lang=en`) so the page switches.
- **First visit pre-selects the browser's language.** `navigator.languages` picks the
  matching language's button when neither the URL nor a language cookie has chosen
  one. It is only a pre-selection: Enter goes to that language, nothing navigates by
  itself, and an explicit choice always wins. Done in the browser, not from
  `Accept-Language` on the server, because a cached page would freeze the server's
  answer for every later visitor.

## Consequences

- Every standalone page now carries the gate's markup, hidden for most visitors. Its
  content is static, and a crawler is no more blocked than before (see ADR 0027:
  a prompt, not a wall).
- A cached page can briefly show the gate to a visitor who already answered, before
  the script runs. Script is loaded in the footer; if this flashes in practice, the
  fix is an inline one-line cookie check that sets `hidden` before first paint.
- Page cache still keys on URL only. Pages with `saw_*` query args should be excluded
  from caching on the host, though correctness no longer depends on it.
- The language chosen on the gate still arrives via `saw_lang` and the language
  cookies. This ADR does not make the translated page itself cache-safe; that is the
  concern of [ADR 0028](0028-polylang-owns-the-languages-strike-a-win-owns-the-reach.md).
