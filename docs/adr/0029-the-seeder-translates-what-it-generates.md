# The demo seeder translates its whole English bank, generated questions included

> **This reverses part of [ADR 0024](0024-question-bank-owns-its-language.md) and
> §4 of [docs/LANGUAGE-PLAN.md](../LANGUAGE-PLAN.md), at the client's request on 17
> Sep 2026.** Those records said a Russian question is not a translation of an
> English one — Polylang's unit of work is a translation pair, and a bank of
> unrelated native questions did not fit it, so questions were never to be paired.
> The client asked instead: *"dễ nhất là dịch bộ seeder câu hỏi / trả lời từ tiếng
> Anh → Nga"* ("simplest is to translate the seeder's questions/answers from
> English to Russian"), then *"tôi cần dịch hết tất cả các câu tự sinh ra"* ("I
> need all the generated ones translated too"). This record is what was built in
> response, and why it is built the way it is rather than the more obvious way.

## What exists now

`Nera_SAW_Seeder` produces a bilingual demo bank. Every English question — all 120
per level, not only the 8 curated ones — gets a Russian counterpart, linked to it
as a Polylang translation pair. Answers stay in the same order in both languages,
so the same position is marked correct in both.

Two different mechanisms produce that pair, because the two kinds of question are
made two different ways:

- **Curated questions** (8 per level, hand-written) are paired with a hand-written
  Russian counterpart at the same position, from `trivia_pool_ru()`.
- **Generated questions** (the rest — arithmetic, Roman numerals, capitals,
  chemistry) are paired by `translate_generated_ru()`, which reads the numbers and
  names back out of the English sentence a `gen_*()` method just built, and
  renders the Russian equivalent from them.

## The one decision worth writing down: translate the output, not the process

The obvious-looking alternative is a parallel `gen_*_ru()` for each of the sixteen
generators, each drawing its own random numbers. It was rejected.

A `gen_*` method is a template wrapped around one or two `wp_rand()` calls. Calling
an independent Russian version draws *different* numbers. The result sits in the
same Polylang translation group as its English counterpart, but it is not a
translation of it — it is a different question that happens to share a group. That
is wrong in a way nothing surfaces at seed time: the mismatch is invisible until a
bilingual reader compares the two, or until an answer key stops matching between
languages for a run that mixes them.

Reading the numbers and names back out of the *finished* English sentence — via
`translate_generated_ru( $text, $built )` — guarantees the two describe the same
fact, because there is only one fact: the same operands, the same distractors, the
same position marked correct, computed once.

Ten regular expressions cover all sixteen `gen_*` methods, because several share a
sentence shape (three separate generators all say `"What is %d + %d?"`). Arithmetic
and Roman-numeral answers need no translation at all — digits and Roman numerals
read the same way in both languages. Capital-city and chemistry questions also
translate their answer options, through name maps kept next to the regular
expressions: `countries_ru()` (genitive forms, for "Столица Франции?"),
`capital_cities_ru()`, `elements_ru()`.

**Nothing is half-translated.** A sentence shape without a rule, or a country or
element name without a map entry, makes `translate_generated_ru()` return `null`;
the caller leaves that one question English-only rather than emitting a Russian
sentence built from a guess. The rule holding this together —
[§3 of the language plan](../LANGUAGE-PLAN.md#3-availability--degrade-to-silence),
applied one question at a time instead of one language at a time.

## What this costs

- **A new country or element needs two entries, not one.** Whoever extends
  `capitals()` or `elements()` for the English generators must also extend the
  matching `_ru()` map, or that fact silently stops getting a Russian question
  (degrading to English-only, per the rule above — not a fatal, but a coverage gap
  worth noticing on review).
- **A new `gen_*` method needs a regular expression**, or it silently stops being
  translated the same way. `translate_generated_ru()`'s doc comment says this;
  there is no test that enforces it, because there is no general way to detect "a
  sentence shape exists with no rule" short of enumerating every possible English
  sentence the generators can produce.
- **The Russian sentences are draft copy**, per answer 6 in §8 of the language
  plan — grammatically checked, not read by a native speaker. This applies equally
  to the curated pool and to the generated templates' phrasing.

## Why this belongs to the seeder, not to the run engine

This is demo-data tooling. A real Russian question bank, when the client supplies
one, is written directly — nobody translates a live bank by regenerating and
re-parsing it. `translate_generated_ru()` exists only so a demo site shows a
playable Russian experience without hand-writing 560 arithmetic questions, and it
is reasonable for it to know less than a professional translator would: the answer
options for arithmetic are numbers, and numbers are the one thing this method never
has to get right by hand.
