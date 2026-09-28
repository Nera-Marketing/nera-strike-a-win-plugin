# Polylang owns the languages; Strike A Win owns how far they reach

**Supersedes the Polylang rejection in [ADR 0024](0024-question-bank-owns-its-language.md)
and [ADR 0027](0027-the-gate-chooses-the-language-the-header-changes-it.md).**

Polylang is the multilingual engine. This plugin does not build one, and does not
carry a `saw_language` taxonomy of its own for interface content.

What this plugin adds is a **reach** control, and it lives inside Polylang's own
settings screen rather than in Strike A Win's:

| Option | Effect |
|---|---|
| **Whole site** | Polylang behaves normally everywhere. Nothing is blocked. |
| **Strike A Win standalone only** | The standalone section is multilingual; the main site's front end is held to one language. |

Client instruction, 17 Sep 2026.

## Why the control belongs in Polylang's screen

An administrator deciding how far a language reaches is already in Polylang's
settings, looking at the languages they just declared. A second screen, in another
plugin, under another menu, is a setting nobody finds until it is wrong.

It also states the dependency honestly: with Polylang inactive there is nothing to
scope, and the control is simply not there.

## Front end only

Both options change the **front end**. The admin stays as Polylang leaves it —
language columns, the language filter and per-post language pickers keep working for
every post type the administrator enabled, in both modes.

An editor translating main-site pages while the front end serves one language is a
site being *prepared* for launch, not a broken one. Blocking the admin would make
that preparation impossible and would be read as a bug.

## What "Strike A Win only" actually has to do

Polylang's model is site-wide by construction, so this option is not one filter. It
is at least three, and whoever builds task 8 should expect all of them:

1. **Which post types Polylang translates** is set in Polylang's settings, not here.
   `page` and `product` appear there unticked; if an administrator ticks them,
   translated main-site content exists whatever this option says. The reach control
   governs *serving*, not *storing*.
2. **The language switcher and `hreflang`** are suppressed on main-site requests.
3. **The URL prefix still resolves.** Whatever Polylang's URL Modifications setting
   is, a `/ru/` address for a main-site page will route. Leaving it to render a
   second English page is a duplicate-content problem, so it needs a redirect to the
   canonical one.

Item 3 is the one that gets missed, because the site looks correct until somebody
finds the URL.

## Strike A Win's own Language Scope setting is retired

> **Paused 17 Sep 2026.** Multilingual is parked while the English standalone section
> is finished. This decision stands; the *deletion* it calls for does not happen until
> the workstream is built. See the banner in
> [docs/LANGUAGE-PLAN.md](../LANGUAGE-PLAN.md).

The `questions_only` / `whole_standalone` radio goes. It answered a question that no
longer exists: with Polylang doing the translating, the section's content follows the
language because Polylang translates it, not because a setting permitted it.

Nothing reads the setting today — it is referenced only by
`Nera_SAW_Settings_Admin` and `Nera_SAW_Mode` — so retiring it costs one screen
section and three methods. `Nera_SAW_Mode::language_scope()` should be removed rather
than left returning a value nobody honours.

## The switcher hides itself when there is nothing to switch

Three states, one behaviour between them — the section degrades to silence, never to
an error:

| Polylang | Languages | Standalone section |
|---|---|---|
| Not installed or inactive | — | No switcher. No notice. Renders in one language. |
| Active | one | No switcher. |
| Active | two or more | Switcher in the header, languages in the entry gate. |

A missing multilingual plugin is not a fault condition. Strike A Win is monolingual
by default and ships to sites that will never install Polylang, so an admin notice or
a "multilingual unavailable" message would be the plugin complaining about a
configuration nobody chose. Every language call site therefore asks whether there is
more than one language and renders nothing when there is not — it never assumes
Polylang's functions exist.

The entry gate has two jobs, and only one of them depends on this. With fewer than
two languages it keeps the age acknowledgement and drops the language buttons; it
does not disappear.

A control with one option is a question with one answer.

This applies **only inside the standalone section**. The main site's own templates
are not this plugin's concern in either mode.

## What this does not change

- Age is still acknowledged in the entry gate and *verified* at registration and
  before payment ([ADR 0023](0023-age-verification-follows-the-mode.md)).
- The entry gate still chooses a language on first visit and the header still carries
  a toggle ([ADR 0027](0027-the-gate-chooses-the-language-the-header-changes-it.md)) —
  only the machinery behind them changes.
- A run's question language is still settled when the run starts, and a language
  whose bank cannot fill that run is still unavailable there, however the interface
  is set.

## With Strike A Win deactivated

Polylang active, this plugin off: not this plugin's business. No hooks are registered
and the site behaves as Polylang alone would.
