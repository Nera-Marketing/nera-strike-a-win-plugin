# 0037. The brand name lives in one setting, and player copy has no dashes

Status: accepted (6 October rename, client copy sheet)

## Context

The client is renaming the site and has not confirmed the new name. About twenty
player-facing lines also needed new wording, and the house rules are: no dashes
(em, en or a spaced hyphen), no "lottery", no "Submit".

## Decision

- The name is one SAW setting, Settings > Brand name (`brand_name`, default
  "Strike A Win"), read through `Nera_SAW_Brand::name()`. Copy writes the token
  `[BRAND]`; a `gettext`/`ngettext` filter for the `nera-strikeawin` domain, and
  `Nera_SAW_Standalone_Fields::text()/shell()`, swap it in. The browser tab title
  on SAW screens uses it too (and `|` instead of a dash as the separator).
- Fields already saved keep their old text over a new default, so
  `Nera_SAW_Copy::migrate()` rewrites them once, only where the value still equals
  the old default or carries a dash. An editor's own wording is left alone.
- Text we do not own but show (Help and support directory, player protection,
  privacy, terms) is cleaned on output by `Nera_SAW_Copy::undash()`, in the SAW
  screens only.
- `tools/copy-lint.php` fails on a dash, "lottery" or "Submit" in any player string
  (PHP translatable calls, the Vue defaults, the Russian maps and JSON). Admin
  screens are out of scope.
- Russian: runs and tickets take the three Russian plural forms.

## Consequences

- Changing the name is one field. The logo text loses its accent on "A" (it was
  `Strike <em>A</em> Win`); the field still accepts `<em>` if the client wants it back.
- Legal prose that uses "submit" as an ordinary verb (terms, privacy) and the
  complaints plugin's own button are not ours and are left as written.
