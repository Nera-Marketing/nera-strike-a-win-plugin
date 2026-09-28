# The gate chooses the language, the header changes it

The client picked **Option 1 combined with Option 2** from
`strikeawin-stage.pages.dev/options/`:

> We'll go with Option 1, but combined with Option 2. So we'd like the pop-up at the
> beginning for age verification and language selection, but once the user is inside
> the site, we'd still like the language toggle to stay visible in the header so they
> can switch language at any time.

So: a card on first visit choosing English or Russian and confirming 18+, and a
language pill in the header of every standalone screen thereafter.

## This settles Language Scope for standalone: `whole_standalone`

The options page says it outright — *"Switching to Russian changes the control and
the header labels; translating the rest of the site is part of the build."* A header
that changes language is a section that has a current language, which is the
definition of `whole_standalone` rather than `questions_only`.

The setting itself stays. `questions_only` remains the default, remains correct for
mix mode, and remains what an operator running only a Russian question bank wants.

## Polylang is now ruled out entirely, not deferred

> **This section is superseded by
> [ADR 0028](0028-polylang-owns-the-languages-strike-a-win-owns-the-reach.md)**
> (17 Sep 2026). Polylang is in. The objections below became the requirements for its
> "Strike A Win only" reach option rather than reasons to avoid it. The rest of this
> record — the gate, the header toggle, interface vs run language, cookie over
> `sessionStorage` — still stands.

[ADR 0024](0024-question-bank-owns-its-language.md) rejected Polylang for
`questions_only` and said it would be introduced *if* the scope ever became
`whole_standalone`. That deferral is withdrawn.

Two of its three objections were never about scope:

1. Polylang has no "language without URLs" mode. Declaring a second language grows a
   Russian URL variant of **every page on the site**.
2. Its settings screen lists `product` and `page`, unticked. One administrator
   ticking "Products" starts translating WooCommerce across the whole shop.

Both reach the main site, and the section is now bound by a rule that did not exist
when 0024 was written: **a standalone page never overrides or alters the equivalent
page on the main site.** Polylang cannot be confined to the section, so it is out at
any scope.

Language therefore stays SAW-owned, the way routing, templates and assets already are.

## The pop-up is an acknowledgement, not verification

It asks the player to confirm they are 18. It does not check.

[ADR 0023](0023-age-verification-follows-the-mode.md) holds: standalone does not
consult `nera-age-shield-plugin`, and real verification stays where it already is —
at registration and before payment. Recording this card as "age verification" in a
compliance document would overstate what it does.

The design keeps the site visible behind the overlay, which is deliberate and worth
preserving: it is a prompt, not a wall, and a wall would also block crawlers.

## The header toggle and the run's language are different things

An earlier decision says a language whose question bank cannot fill a run is hidden
when that run starts. The header toggle is always visible, which looks like a
contradiction and is not.

The toggle sets the **interface** language. The **run** language is settled when a
run begins, and that is where an under-stocked bank removes the option. A player can
read the site in Russian and still be told that this particular competition can only
be played in English.

## Switching reloads, and needs no JavaScript

The prototype calls `location.reload()` after a switch, because the whole page
follows the choice. A server-rendered section reaches the same place more simply: the
toggle is a link, the language is resolved in PHP before anything renders, and the
page comes back translated.

The consequence is that the choice cannot live in `sessionStorage` as it does in the
prototype. JavaScript runs after the server has already chosen the words. It has to
be a cookie for guests and user meta for signed-in players — readable at the top of
the request.
