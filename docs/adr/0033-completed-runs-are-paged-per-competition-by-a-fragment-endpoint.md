# Completed runs are paged per competition, by a fragment endpoint

"My runs & tickets" listed every completed run a player had ever finished, each with
every ticket number it earned, in one page load. With 31 runs that was 34 queries (one
per run to fetch its numbers), 1,357 number chips and a 149 KB document; it grows with
every run played.

## Decision

- **Page the completed runs, per competition, 5 at a time.** `Nera_SAW_Run::runs_for_user()`
  takes `$per_page` and a competition => page map, and loads ticket numbers for the
  page being shown only, in one batched query (`ticket_numbers_for_runs()`). The same
  player now costs 2 queries instead of 34, 10 runs and 350 chips instead of 31 and
  1,357, and 77 KB instead of 149. `$per_page = 0` keeps the old return-everything
  behaviour for any other caller.
- **No reload: a REST fragment, swapped in by a small script.** `GET
  nera-saw/v1/my-runs/completed?competition_id=&page=` returns the page's HTML,
  already rendered by the same PHP (`Nera_SAW_Account_Pages::render_completed_runs()`),
  and `assets/js/runs-tickets.js` replaces the list. Login required, the player's own
  runs only (there is no user argument), the usual REST nonce.
- **The pager is plain links first.** `?runs_page_{competition}=N#saw-runs-{id}`, built on
  the current address, so it works with scripting off, in a copied link and after a
  reload. The script intercepts the click, keeps the address in step with
  `history.replaceState()`, and falls back to following the link if the request fails.
  Each competition pages independently.

## Why not a Vue component

The plugin does ship Vue (the quiz island), and a paged list is a fair fit for it. It
was not used here for three reasons: the account screens are server-rendered PHP with a
sibling script per behaviour (`lang-swap.js`, `add-to-basket.js`), so a Vue island would
be the first on a WooCommerce account screen; a second Vite entry means every change to
a list of chips needs a build and a rebuilt `dist/` (which also re-hashes the quiz
bundle); and returning the server's own rendered HTML keeps number formatting,
plurals and the Russian strings in one place instead of duplicating them in a template.
If the screen grows interactions that need client state (filters, sorting, live
updates), a Vue island is the right move and the REST route can return JSON beside the
HTML.

## Also

- "1 runs left on Premium" now reads "1 run left on Premium"; the Russian sentence is the
  same for every count, so both msgids map to it.
- The pager's strings have Russian entries in `Nera_SAW_Language`.
