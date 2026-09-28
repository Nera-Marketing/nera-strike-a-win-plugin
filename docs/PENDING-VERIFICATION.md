# Pending verification — needs a human, not a harness

Things the automated harnesses cannot prove, listed as they accumulate. Tick them
off on staging before the work they belong to is called done.

> 🇬🇧 English · 🇻🇳 Tiếng Việt below each item.

---

## X2 — the run clock

Engine side is covered by harness (`test_x2.php`, 26 assertions) and the resume
policy by `test_x2b.php` (19). What those cannot check is the browser, real cron,
and what a player actually sees.

### 1. Cron actually fires on the target site

- **Do:** deploy, then open **Strike A Win → Run Clock** and note *"Sweep
  scheduled"*. Reload after six minutes.
- **Expect:** the time has advanced to a new slot.
- **If it never advances:** WP-Cron is not running — common where
  `DISABLE_WP_CRON` is set and a real cron job calls `wp-cron.php`. The engine is
  fine; the schedule is not being executed.
- 🇻🇳 Deploy xong, mở màn Run Clock xem *"Sweep scheduled"*, chờ 6 phút rồi tải
  lại — mốc thời gian phải nhảy sang lần kế tiếp. Không nhảy = WP-Cron không chạy
  trên site đó.

### 2. Abandon → tickets actually arrive

- **Do:** play a run, answer question 1 **correctly**, close the tab. Wait past
  the run window (questions × timer), then up to five more minutes.
- **Expect:** the Report shows the run as `expired`, and the tickets from
  question 1 exist in the competition.
- **Why it matters:** this is the whole bug. If tickets do not appear, minting is
  still not happening.
- 🇻🇳 Chơi một lượt, trả lời **đúng** câu 1, đóng tab. Chờ quá cửa sổ thời gian +
  5 phút. Report phải hiện `expired` và vé của câu 1 phải tồn tại.

### 3. Reconnect lands on the live question

- **Do:** start a run, answer question 1, close the tab. Come back **before** the
  run window ends — roughly three questions' worth of time later.
- **Expect:** you land on question 4 or 5, not question 2, and the timer shows
  the *remaining* seconds rather than a full one. Questions 2–3 score zero. The
  tickets from question 1 are still counted.
- **Why it matters:** this is the half of `requirements.md` L38 that did not work
  before — the clock used to pause while you were away.
- 🇻🇳 Trả lời câu 1, đóng tab, quay lại sau khoảng 3 câu. Phải vào thẳng câu 4–5
  với **thời gian còn lại**, không phải câu 2 với timer đầy.

### 4. Resume policy = Close

- **Do:** set **Settings → Behaviour → If a run is interrupted** to *Close the
  run*. Repeat test 3.
- **Expect:** the run is not continued. The Report shows it as errored with a
  Restore action, nothing is minted, and **Strike A Win → Log** holds a
  `run_interrupted` entry.
- **Then:** click Restore and confirm the player's run balance goes back up.
- 🇻🇳 Đổi setting sang *Close the run* rồi lặp lại test 3. Run không được chơi
  tiếp; Report hiện errored + nút Restore; không mint vé; Log có
  `run_interrupted`. Bấm Restore rồi kiểm tra số lượt chơi của khách tăng lại.

### 5. A reload inside the live question is NOT punished

- **Do:** with the policy still on *Close the run*, start a run and press F5 while
  a question still has time on it.
- **Expect:** the run continues normally. Nothing is flagged.
- **Why it matters:** if this closes the run, every flaky mobile connection
  becomes a support ticket.
- 🇻🇳 Vẫn để *Close the run*, bấm F5 khi câu đang hỏi còn giờ. Run phải chạy tiếp
  bình thường, không bị đánh dấu gì.

### 6. The backlog migration, on staging with a real copy of live data

- **Do:** follow [RUNBOOK-run-clock.md](RUNBOOK-run-clock.md) end to end.
- **Expect:** *"Tickets already scored but unminted"* is a plausible number; the
  preview matches what the back-fill then does; after sweeping, those players
  hold the tickets.
- 🇻🇳 Chạy đủ [RUNBOOK-run-clock.md](RUNBOOK-run-clock.md) trên staging với bản
  sao dữ liệu live. Con số *"Tickets already scored but unminted"* phải hợp lý.

---

## X1 — the settings

Covered by harness (`test_x1.php`, 28 assertions) including the settings page
render. One thing it cannot check:

### 7. Saving the settings page does not wipe the new keys

- **Do:** open **Strike A Win → Settings**, change a timer value, save. Reopen.
- **Expect:** all four behaviour settings still hold what they held.
- **Why it matters:** `save()` writes the whole option array, so a key it forgets
  is a key it deletes. That failure mode already bit the feature flags once.
- 🇻🇳 Mở Settings, đổi một giá trị timer, lưu, mở lại. Cả bốn setting hành vi phải
  giữ nguyên giá trị cũ.

---

## W15 — standalone language (L2/L3), entry gate, order-received

Covered by PHP-level harnesses only (resolver, reach filters, seeder pairing,
template ownership). Nothing here has been clicked through in a real browser.

### 8. The entry gate and header toggle, in a real browser

- **Do:** with Polylang active and two languages declared, open any standalone
  screen as a first-time visitor.
- **Expect:** the entry gate appears once, offers both languages, and the 18+
  question shows the plain checkbox — or, for a player age-shield has already
  verified, the "Verified: you have confirmed you are over 18" statement instead
  (never a pre-ticked box — see §8.2 of
  [docs/LANGUAGE-PLAN.md](LANGUAGE-PLAN.md)). Choosing a language and confirming
  should not show the gate again on the next page, and the header toggle should
  switch the current page's language without losing your place.
- **Then:** set Polylang to a single language (or deactivate it) and reload.
- **Expect:** no gate for languages, no header toggle, no error — the 18+
  question, if age-shield is absent, still shows as a self-declaration.
- 🇻🇳 Mở section lần đầu: gate phải hiện đúng 1 lần, có cả hai ngôn ngữ, câu hỏi
  tuổi là checkbox thường (hoặc câu khẳng định nếu age-shield đã xác minh, không
  bao giờ tick sẵn). Chuyển ngôn ngữ ở header không được mất vị trí đang đọc. Khi
  chỉ có 1 ngôn ngữ (hoặc tắt Polylang): không gate ngôn ngữ, không toggle, không
  lỗi.

### 9. The reach control, set to "Strike A Win only"

- **Do:** in Polylang's settings, tick **Strike A Win section only**, then visit a
  main-site page's non-default-language URL (e.g. `/ru/` in front of a main page).
- **Expect:** a 301 back to the canonical (default-language) page, and no language
  switcher anywhere on the main site. Inside the section, both languages still
  work normally.
- **Why it matters:** without the redirect, that URL renders a second copy of the
  same English page — duplicate content that looks fine until a crawler or a
  visitor finds it. See §8.1 of [docs/LANGUAGE-PLAN.md](LANGUAGE-PLAN.md).
- 🇻🇳 Bật "Strike A Win section only", mở URL ngôn ngữ khác của trang main site
  (vd `/ru/...`) → phải redirect 301 về trang mặc định, main site không còn hiện
  switcher. Trong section vẫn chạy 2 ngôn ngữ bình thường.

### 10. The order-received screen, as an actual guest checkout

- **Do:** as a signed-out visitor (not an admin), complete a section checkout for
  a competition entry, end to end.
- **Expect:** the confirmation screen renders in the section's skin (not a
  redirect to the main site's `/my-account/`), and if the product is a lottery
  type LFW draws a result screen for, it appears in the section's palette, not
  the mu-plugin's indigo one.
- **Why it matters:** every automated check of this screen so far has been driven
  as a signed-in administrator or through a forged session; the guest path is the
  one a real player actually takes, and an earlier finding this session (still
  open, see below) was specifically a guest-only failure.
- 🇻🇳 Checkout thật với tài khoản khách (chưa đăng nhập) từ đầu tới cuối — màn
  hình xác nhận phải đúng skin section, không bị đá về `/my-account/` main site.

### 11. The quiz-run UI (language → question → stage-break → results)

- **Why this is here:** the reskin was verified with a mock-`fetch` Vue harness
  (canned REST responses, scripted clicks) so every phase could be screenshotted
  against the reference designs, not with a real browser run against the live
  REST API. The harness proves the markup and state transitions; it cannot prove
  the real endpoints, real timers, or a real payment-gated run behave the same
  way.
- **Do:** buy an entry for a ladder-mode competition with two or more languages
  playable, then play a full run for real: pick a language, answer through more
  than one stage (including at least one wrong and one timed-out answer), finish
  with tickets earned, then play a second run and answer everything wrong to see
  the zero-tickets ending.
- **Expect:** the language gate only appears when the competition actually has
  more than one playable language (see `Nera_SAW_Run::playable_languages()`); the
  stage number and badge colour match the level of the question actually being
  served; the "Stage X of Y" bar never flashes the wrong count; the inline
  reveal/banked banner appears without a page navigation; the clock shown counts
  down from what the server actually granted, not a client-side guess; the
  results screen's ticket numbers and score match what the Report shows for that
  run afterward.
- **Then:** repeat with a random-mode (non-ladder) competition and confirm no
  stage-break screen ever appears and every question reports itself as its own
  stage (`stage_no`/`stage_count` both equal the slot's position/total).
- 🇻🇳 Bản reskin mới được kiểm bằng harness Vue giả lập `fetch` (dữ liệu REST giả
  lập, click kịch bản) để chụp từng màn so với thiết kế mẫu — chưa test bằng
  trình duyệt thật gọi REST API thật. Cần mua vé một competition chế độ ladder có
  từ 2 ngôn ngữ trở lên, chơi thật hết 1 lượt (chọn ngôn ngữ, qua ít nhất 2
  stage, có câu sai và câu hết giờ, kết thúc có vé), rồi chơi lượt 2 trả lời sai
  hết để xem màn "0 vé". Kiểm tra: gate ngôn ngữ chỉ hiện khi thật sự có ≥2 ngôn
  ngữ chơi được; màu và số thứ tự stage đúng với câu đang hỏi; thanh "Stage X of
  Y" không nhấp nháy sai; màn phản hồi hiện ngay tại chỗ không chuyển trang; đồng
  hồ đếm ngược đúng thời gian server cấp; số vé và điểm ở màn kết quả khớp với
  Report sau đó. Lặp lại với competition chế độ random (không phải ladder) để
  chắc chắn không bao giờ hiện màn stage-break và mỗi câu tự là 1 stage.
