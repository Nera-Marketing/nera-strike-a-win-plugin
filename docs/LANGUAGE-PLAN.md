# Multilingual plan — workstream 8, "Russian: site-wide language"
# Kế hoạch đa ngôn ngữ — workstream 8

> ## ▶ RESUMED — 17 Sep 2026
>
> Restarted the same day it was paused, beginning with the question bank.
> **L1–L4 are built.** L5 (replace the draft Russian copy, retire Language Scope)
> is what remains. The removals in §6 are still *not* to be done ahead of it.
>
> <details><summary>The pause note it replaces</summary>
>
> ## ⏸ PAUSED, NOT CANCELLED — 17 Sep 2026
>
> The client has parked multilingual to concentrate on the English standalone
> section. **Nothing in this plan is withdrawn.** The two ADRs stand, all seven
> answers in §8 stand, and the build order in §7 is still the order.
>
> **Do not act on the removals below while this is paused.** §6 tells a reader to
> delete the Language Scope setting and `Nera_SAW_Mode::language_scope()`. That is
> a step *inside* this workstream, not tidying to be done first — deleting it now
> would throw away working code to make room for a feature nobody is building yet,
> and the setting harms nothing where it sits. Nothing reads it.
>
> **Tạm dừng, không phải huỷ.** Không xoá gì trong lúc dừng — phần "đã bỏ" ở §6 là
> một bước *bên trong* workstream này, không phải việc dọn dẹp làm trước.
>
> </details>


Two client decisions, both recorded as ADRs:

- **16 Sep** — Option 1 + Option 2 from `strikeawin-stage.pages.dev/options/`: an entry
  pop-up for language and age, plus a language toggle that stays in the header.
  [ADR 0027](adr/0027-the-gate-chooses-the-language-the-header-changes-it.md)
- **17 Sep** — Polylang is the engine; this plugin adds a **reach** control inside
  Polylang's settings.
  [ADR 0028](adr/0028-polylang-owns-the-languages-strike-a-win-owns-the-reach.md)

Hai quyết định của client, đều đã ghi thành ADR: pop-up đầu vào + toggle trên header;
và Polylang là engine, plugin này chỉ thêm một control **phạm vi** vào settings của
Polylang.

---

## 1. Who does what / Ai làm gì

| Layer | Owner |
|---|---|
| Declaring languages, translating pages and strings | **Polylang** |
| How far that is **served** on the front end | **This plugin** — the reach control |
| The entry gate and the header toggle inside the section | **This plugin** |
| Question language and the draw | **This plugin** — see §4 |

This plugin does not build a translation engine. It decides reach and renders two
controls.

Plugin này không dựng engine dịch. Nó quyết định phạm vi và render hai control.

---

## 2. The reach control / Control phạm vi

Added to **Polylang's own settings screen**, two radios:

| Option | Front end |
|---|---|
| **Whole site** | Polylang behaves normally. Nothing blocked. |
| **Strike A Win standalone only** | Section multilingual; main site front end held to one language. |

**Front end only.** The admin is untouched in both modes — language columns, filters
and per-post pickers keep working. An editor translating main-site pages behind a
one-language front end is a site being *prepared*, not a broken one.

**Chỉ ảnh hưởng frontend.** Backend giữ nguyên ở cả hai chế độ. Biên tập viên dịch
trang main site trong khi frontend chỉ chạy một ngôn ngữ là site đang *chuẩn bị*, không
phải site hỏng.

### "Strike A Win only" is at least three pieces of work

Polylang is site-wide by construction. Whoever builds this should expect all three:

1. **Post types** — which ones carry translations is Polylang's setting, not ours.
   `page` and `product` appear there unticked. Reach governs **serving, not storing**.
2. **Switcher and `hreflang`** — suppressed on main-site requests.
3. **The `/ru/` URL still routes.** Leaving it to render a second English page is
   duplicate content; it needs a canonical redirect.

Item 3 is the one that gets missed, because the site looks right until somebody finds
the URL.

*Mục 3 hay bị bỏ sót, vì site trông vẫn đúng cho đến khi có người tìm ra cái URL đó.*

---

## 3. Availability — degrade to silence / Thiếu thì ẩn, không báo lỗi

| Polylang | Languages | Standalone section |
|---|---|---|
| Not installed / inactive | — | No switcher. No notice. One language. |
| Active | one | No switcher. |
| Active | two or more | Switcher in header, languages in the gate. |

A missing multilingual plugin is **not a fault condition**. Strike A Win is monolingual
by default and ships to sites that will never install Polylang. Every language call
site asks "is there more than one language?" and renders nothing when there is not —
it never assumes Polylang's functions exist.

Thiếu plugin đa ngôn ngữ **không phải lỗi**. Mọi chỗ gọi đều hỏi "có nhiều hơn một
ngôn ngữ không?" và không render gì nếu không — không bao giờ giả định hàm của Polylang
tồn tại.

The gate has two jobs. With fewer than two languages it keeps the age acknowledgement
and drops the language buttons; it does not disappear.

---

## 4. The question bank IS translation pairs / Ngân hàng câu hỏi LÀ cặp dịch

> **Reversed 17 Sep 2026, at the client's request.** This section used to say the
> opposite: that a Russian question is a separate native question, never paired,
> because Polylang's unit of work is a translation pair and a bank of unrelated
> questions does not fit it. The client asked for the seeder's English bank to be
> translated instead — *"dễ nhất là dịch bộ seeder câu hỏi / trả lời từ tiếng Anh →
> Nga"* — and to keep questions and answers in step.
>
> That is the simpler thing to review and it keeps both ladders identically graded,
> which the earlier design did not guarantee. It also means Polylang is being used
> the way it expects, so the admin's translation UI works normally.
>
> **What it costs:** every Russian question is now only as good as the English one it
> came from, and a question that reads naturally in English can read as translated
> prose in Russian. That is a copy-quality risk, not a structural one, and answer 6
> already treats the Russian copy as a draft to be replaced before launch.
>
> **Đảo lại theo yêu cầu client.** Trước đây ghi rằng câu hỏi tiếng Nga là bộ riêng,
> không ghép cặp. Nay: dịch bộ seeder Anh → Nga và ghép cặp, cả câu hỏi lẫn đáp án.

Each Russian question is the translation of the English question at the same level and
position, and its answers are in the same order — row three of the Russian answers is
row three of the English ones. `Nera_SAW_Seeder::trivia_pool_ru()` holds them aligned
to `trivia_pool()`, and `seed_questions_slice()` links the pair with
`pll_save_post_translations()`.

**Every question is paired, curated and generated alike — extended again on 17 Sep**
after the client asked for the generated questions too ("*tôi cần dịch hết tất cả
các câu tự sinh ra*"). The 8 curated questions per level use the hand-written
Russian counterpart at the same position. The rest — the generated arithmetic,
Roman-numeral, capital-city and chemistry questions that fill a level beyond the
curated 8 — are translated by parsing the English sentence `gen_*()` just built
(`Nera_SAW_Seeder::translate_generated_ru()`), not by drawing a second set of random
numbers: a second draw would produce a *different* question that happened to share
a translation group, not a translation of the one just created. See
[ADR 0029](adr/0029-the-seeder-translates-what-it-generates.md) for why. Ten
sentence shapes cover all sixteen generators; the answer options for arithmetic and
Roman-numeral questions need no translation at all — digits and Roman numerals read
the same way in both languages — while capital-city and chemistry questions also
translate their answer options through name maps (`countries_ru()`,
`capital_cities_ru()`, `elements_ru()`). A sentence shape or a name this file has
not mapped is left English-only rather than half-translated. As seeded today, the
demo bank is 600 English questions and 600 Russian translations, one for one.

**Answered 17 Sep: (a).** Polylang manages `saw_question`, and — as of the reversal
above — every question is paired, not left unpaired as this answer originally
assumed. The bank therefore depends on Polylang being active, which nothing else in
this section does — that is accepted, not overlooked.

Two ways it was weighed:

- **(a) Enable `saw_question` in Polylang, never pair the translations.** Polylang
  allows a post in a language with no counterpart. Gains the admin language column and
  filter for free; the draw's `tax_query` moves to Polylang's `language` taxonomy.
- **(b) Keep the bespoke `saw_language` taxonomy** from
  [ADR 0024](adr/0024-question-bank-owns-its-language.md), independent of Polylang.

**Chọn (a).** Một taxonomy thay vì hai, và được cột + bộ lọc trong admin miễn phí.
Đánh đổi: ngân hàng câu hỏi phụ thuộc Polylang — chấp nhận có ý thức.

Either way: a language whose bank cannot fill a run is unavailable **at that run**,
even while the interface offers it everywhere else. Interface language and run
language are different decisions.

---

## 5. Resolving the language / Xác định ngôn ngữ

In order, first hit wins:

1. An explicit switch this request (the header toggle) — a `GET` link
2. Polylang's own resolution (URL, cookie, user preference)
3. The section's default

**Not `sessionStorage`.** The prototype uses it because it translates the DOM *after*
load. The section is rendered by PHP, so the language must be known **before** markup
is written. JavaScript arrives too late.

**Không dùng `sessionStorage`.** Prototype dùng được vì nó dịch DOM *sau khi* tải.
Section này render bằng PHP — ngôn ngữ phải biết **trước** khi in markup.

Switching is a page load, not a repaint. The toggle therefore works with JavaScript
off, and a translated page can be linked to.

---

## 6. Retired / Đã bỏ

The **Language Scope** setting (`questions_only` / `whole_standalone`) is retired *as
a design*. It permitted something Polylang now simply does.

**The code stays until this workstream is actually built.** It is still in
`Nera_SAW_Settings_Admin` and `Nera_SAW_Mode`, nothing reads it, and it costs nothing
where it sits. Removing it is step L5, not a prerequisite — and while multilingual is
paused, deleting it would be throwing away working code for a feature nobody is
building.

When L5 runs: remove `Nera_SAW_Mode::language_scope()` rather than leave it returning
a value nobody honours.

Setting **Language Scope** đã bỏ. Nó vẫn còn trong code nhưng không ai đọc, nên vô hại
cho tới khi workstream 8 xoá hẳn.

---

## 7. Build order / Thứ tự làm

| # | Work |
|---|---|
| L1 | ✅ **Done.** Availability helper and language resolver — `Nera_SAW_Language`. Everything else reads it. |
| L2 | ✅ **Done.** Reach control in Polylang's settings (`Nera_SAW_Language_Reach`) — the switcher hidden off-section, the stray-language redirect. Not yet verified in a real browser; see [docs/PENDING-VERIFICATION.md](PENDING-VERIFICATION.md). |
| L3 | ✅ **Done.** Header toggle + entry gate (`Nera_SAW_Language_Switcher`, `templates/standalone/language-toggle.php`, `entry-gate.php`) — no JS dependency, hidden per §3, age question follows age-shield per §8.2. Not yet verified in a real browser. |
| L4 | ✅ **Done.** Bank is under Polylang; the seeder pairs the whole English bank — curated and generated alike, 600/600 as seeded today — as real Polylang translations. See [ADR 0029](adr/0029-the-seeder-translates-what-it-generates.md). The *draw's* language scoping (`query_level()`, `Nera_SAW_Run::playable_languages()`) has since been exercised against a live Russian ladder/random run via headless harness — see §8.3, item 1. Not yet exercised through a real browser against the live REST API — see [docs/PENDING-VERIFICATION.md](PENDING-VERIFICATION.md) item 11. |
| L5 | Replace the draft Russian copy (curated pool + generated-question templates) with reviewed copy; retire Language Scope |

Russian strings and the one/few/many plural helpers can be lifted from the prototype's
`saw-ru.js`.

---

## 8. Answers, 17 Sep 2026 / Câu trả lời

| # | Question | Answer |
|---|---|---|
| 1 | Question bank mechanism | **Polylang manages `saw_question`** — ~~never paired~~ **paired; see §4, reversed 17 Sep** |
| 2 | Competition names and prize copy | **Tick `product` in Polylang** |
| 3 | Age in the entry gate | **Follows `nera-age-shield-plugin`** — see below |
| 4 | Gate persistence | **Until the cookie is cleared or the player logs out** |
| 5 | No age plugin installed | **Still show the 18+ self-declaration** |
| 6 | `saw-ru.js` | **Draft.** Build against it, replace before launch |
| 7 | Dates in Russian | **Follow the language** — Russian month names, 24h clock, prices stay GBP |

### 8.1 What answer 2 costs / Giá phải trả của câu 2

Ticking `product` puts **every** WooCommerce product under Polylang, not only the
competitions — the iPhone and MacBook lotteries on the main site come with it. The
reach control still stops those translations being *served* on the main site front
end, but they exist, and `/ru/` addresses for them will route.

So the "Strike A Win only" option's third piece — the canonical redirect in §2 — is
no longer optional. It is the only thing standing between this decision and duplicate
content across the whole catalogue.

Tick `product` kéo **toàn bộ** sản phẩm vào Polylang, kể cả lottery của main site.
Reach control vẫn chặn *phục vụ* trên frontend main site, nhưng bản dịch vẫn tồn tại và
URL `/ru/` vẫn route được — nên redirect canonical ở §2 không còn là tùy chọn.

### 8.2 Age in the gate / Tuổi trong pop-up

Answers 3 and 5 together:

| `nera-age-shield-plugin` | Player | Gate shows |
|---|---|---|
| Active | not verified | Age question, unticked, recorded through age-shield |
| Active | already verified in the database | The same control, **pre-ticked** |
| Not installed | anyone | The 18+ line as a self-declaration only — nothing is recorded |

**This reverses [ADR 0023](adr/0023-age-verification-follows-the-mode.md) for
standalone.** That record says standalone does not consult age-shield. It now does,
when age-shield is there.

**Điều này đảo ngược ADR 0023** cho standalone: trước đây standalone không dùng
age-shield, giờ thì có — khi plugin đó tồn tại.

> **Raise before building:** a **pre-ticked consent box** is a pattern regulators and
> auditors flag, because a tick the user did not make cannot evidence a choice they
> made. Showing the same fact as a statement — *"Verified: you confirmed you are over
> 18"* — carries the identical information and is not a consent control at all.
> Worth putting to the client before it is built.
>
> **Cần nêu trước khi build:** ô đã tick sẵn là mẫu bị kiểm toán compliance bắt lỗi,
> vì một dấu tick người dùng không tự đánh thì không chứng minh được lựa chọn của họ.
> Hiển thị cùng thông tin ở dạng câu khẳng định thì không phải là control đồng ý nữa.

### 8.3 Still open / Còn treo

Nothing blocking. In order of what to check next:

1. ✅ **The draw's own language scoping is verified — by harness, not yet by
   browser.** `query_level()` now takes an explicit `$lang` param (added
   alongside the quiz-run UI rebuild), and `Nera_SAW_Run::start()` resolves and
   freezes a run's language once at start (`resolve_run_language()`: explicit
   request → Polylang ambient current → none) rather than trusting
   `Competition_Config::get()`'s bare `'en'` default. `Nera_SAW_Run::
   playable_languages()` intersects `Nera_SAW_Language::codes()` with a bank-health
   check per level so a language only offers itself when every level actually has
   enough questions, and returns none at all for a monolingual bank. A headless
   PHP harness started and completed a live Russian ladder run and a live Russian
   random run end to end, confirming every served question was Russian and that
   ticket minting was unaffected. **Still open:** a real browser against the live
   REST API — see [docs/PENDING-VERIFICATION.md](PENDING-VERIFICATION.md) item 11.
2. **L2 and L3 have not been opened in a real browser.** Built and lint-clean; the
   entry gate, the header toggle and the reach control's redirect/switcher-hiding
   have only been exercised through PHP-level harnesses. See
   [docs/PENDING-VERIFICATION.md](PENDING-VERIFICATION.md).
3. **The Russian copy is a draft** — curated pool and generated-question
   templates alike — which is a launch gate rather than a build one.

### 8.4 A lesson worth keeping, from building L4 / Một bài học từ khi làm L4

Putting `saw_question` under Polylang (`pll_get_post_types`) does more than let the
draw scope by language. It scopes **every** `WP_Query`/`get_posts()` against that
post type by the current language, unless the call explicitly passes `'lang' =>
''`. Two internal, non-player-facing queries were caught by this and silently
returned only the default language's rows: `Nera_SAW_Question_Bank::count()` and
`::delete_by_batch()`. The second one is the seeder's wipe — with it scoped, a
reseed never found the Russian half of the bank to remove, and each reseed piled a
fresh copy of it on top of the last one rather than replacing it. Both are fixed
with `'lang' => ''`; anything else added to this file that queries `saw_question`
for an administrative reason (a count, an export, a cleanup) needs the same
opt-out, on purpose, or it will look correct for exactly one language and wrong for
every other one it silently drops.

A second, unrelated finding from the same investigation: the seeder's "Customers"
and "Submissions" phases send ordinary WordPress/WooCommerce notification emails,
and on an environment whose local mail transport cannot complete a send, `wp_mail()`
does not fail fast — it can block until PHP's execution-time limit kills the
request, which looks exactly like "the seeder hangs with no progress". Both seeder
entry points now short-circuit `wp_mail()` for the duration of a seed
(`Nera_SAW_Seeder_Admin::suppress_mail_during_seed()`), since demo data has no
business sending real mail on any environment.
