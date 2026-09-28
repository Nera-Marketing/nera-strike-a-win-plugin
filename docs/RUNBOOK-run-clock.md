# Runbook — deploying the run clock fix (X2)

**What this deploys:** the fix for the abandoned-run sweeper that has never run, so
tickets players earned but never received finally get minted.
See [ADR 0020](adr/0020-run-level-deadline.md).

> 🇬🇧 English · 🇻🇳 [Tiếng Việt](#tiếng-việt)

---

## Before you start — what will actually happen

Read this even if you skip the rest.

On a site where the sweeper has never fired, there is a backlog of runs stuck in
`active`. Some of those players **answered questions correctly and earned tickets
that were never issued**, because issuing happens at finalize and finalize never
ran.

This deploy does three separate things, and only the third one is irreversible:

| Step | Effect | Reversible? |
|---|---|---|
| 1 · Deploy the code | Adds the `expires_at` column and schedules the sweep. Nothing else changes. | Yes |
| 2 · Back-fill | Gives old runs a clock — **and the five-minute cron picks them up on its own** | **In practice, no** |
| 3 · Sweep | Only makes step 2's outcome happen now instead of within five minutes | **No** |

**Back-fill is the commit point, not the sweep.** The sweep is on a five-minute
cron, so a run given a clock that is already in the past will be finalized
shortly afterwards whether or not anyone presses the second button. That button
exists so you can watch it happen rather than wonder.

Finalizing creates real ticket numbers in a real draw and sends the confirmation
emails that go with them. That is the correct outcome — those tickets were
earned. But back-fill at the moment you are ready for that, not before, and tell
whoever watches the draw that the numbers are about to move.

**Do this on staging first.** The screen shows you the numbers before you commit.

### One setting to decide first

**Strike A Win → Settings → Behaviour → If a run is interrupted.**

| | What the backlog becomes |
|---|---|
| **Let the player continue** *(default)* | Swept runs finalize as `expired` and **their tickets are minted** |
| **Close the run** | Swept runs are flagged `errored` and wait for an administrator. **Nothing is minted.** |

If the backlog is large and you would rather review it than issue it, set this to
*Close the run* **before** back-filling. The runs then land in the Report with a
Restore action instead of minting on their own.

---

## Step 0 — Take a database backup

Not optional for live. The tables that matter are `wp_nera_saw_runs`,
`wp_nera_saw_run_slots`, and whatever Lottery for WooCommerce uses for ticket
numbers.

---

## Step 1 — Deploy the code

Deploy as usual (GitHub release, or upload the zip). Nothing needs to be run by
hand.

On the next page load the plugin will:

- add `runs.expires_at` and its index, via `dbDelta` — the DB version moves
  `0.5.0 → 0.6.0`;
- schedule the sweep (`nera_saw_sweep_reservations`, every five minutes) if it is
  not already scheduled.

**Verify:** go to **Strike A Win → Run Clock**. The row *"Sweep scheduled"* must
show a time within the next five minutes.

> **If it says NOT SCHEDULED**, the plugin did not reach `init` — usually a fatal
> earlier in the request. Check the error log before going further.
>
> **If it shows a time that never advances**, WP-Cron is not running on this site.
> That is common on hosts where `DISABLE_WP_CRON` is set and a real cron job calls
> `wp-cron.php`. Confirm that job exists, or use the *Run the sweep now* button
> below on a schedule of your own.

At this point **nothing has changed for any player.** New runs get a clock from
now on; old ones are untouched.

---

## Step 2 — Look at the numbers

**Strike A Win → Run Clock** shows:

| Row | What it means |
|---|---|
| Active runs with no clock | The backlog. These can never be swept until back-filled. |
| Active runs with a clock | Normal — started since this deploy. |
| Clocked and already past it | The next sweep finalizes these. |
| **Tickets already scored but unminted** | **What players are owed.** This is the number to care about. |
| Oldest clockless run | How far back the backlog goes. |

The preview table lists the next 20 runs with the clock each *would* get, and
whether it would be swept immediately. **The preview writes nothing.**

Sanity-check two things before continuing:

1. Does *"Oldest clockless run"* roughly match when the sweep stopped working?
2. Is *"Tickets already scored but unminted"* a plausible number, or is it wildly
   larger than the site's normal ticket volume? If it looks wrong, stop and ask.

---

## Step 3 — Back-fill, in batches

> ⚠ **This is the point of no return.** Everything back-filled here will be
> finalized by the five-minute cron shortly afterwards, and its tickets minted.
> Do not back-fill on a Friday evening if nobody is around to answer questions
> about the emails that follow.

Click **Back-fill next 100**. Repeat until *"Active runs with no clock"* reaches
zero.

Each click is a separate request, so a large backlog will not time out. The
operation is idempotent — a run that already has a clock is skipped, so clicking
again is harmless.

To back-fill now and mint later, the sweep has to be stopped first: deactivate the
plugin, or unschedule `nera_saw_sweep_reservations`. Otherwise assume back-filling
and minting happen together.

---

## Step 4 — Sweep

Either wait up to five minutes for cron, or click **Run the sweep now** to do it
immediately and see the result.

The sweep processes up to 200 runs per pass, so a large backlog takes several
passes. Cron will keep going on its own; the button is there so you can watch.

**Verify after sweeping:**

- *"Clocked and already past it"* falls towards zero.
- **Strike A Win → Report** shows those runs as `expired` or `abandoned`, not
  `active`.
- The competitions involved show the new ticket numbers.
- Players who were owed tickets have them.

---

## Step 5 — Confirm the ongoing behaviour

Play one run on staging and check:

1. Start a run, answer question 1, then **close the tab**.
2. Wait past the run window (questions × timer + a little).
3. Within five minutes of that, the run shows as `expired` in the Report, with
   the tickets from question 1 minted.

Then the reconnect path:

1. Start a run, answer question 1, close the tab.
2. Come back **before** the window expires.
3. You should land on whichever question is live *now* — not question 2 with a
   full timer. Questions that lapsed while you were away score zero.

---

## Rolling back

| If you have | Do this |
|---|---|
| Deployed only (step 1) | Deactivate the plugin — it unschedules the sweep. The column is harmless. |
| Back-filled in the last few minutes, cron has not fired yet | Deactivate the plugin first — you are racing the cron — then `UPDATE wp_nera_saw_runs SET expires_at = NULL WHERE status = 'active';` |
| Swept | **No rollback.** Tickets are minted and emails sent. Restore the database backup if you must, and be aware that anything that happened since is lost too. |

---

## What changed in the code

| File | Change |
|---|---|
| `class-database.php` | `runs.expires_at` + `sweep (status, expires_at)` index; DB version 0.6.0 |
| `class-run.php` | Run wall clock, deadline chaining, `catch_up()`, rewritten `finalize_stale()`, mark-seen-at-serve, transactional redraw |
| `class-reservations.php` | Registers the five-minute schedule and `ensure_scheduled()` on `init` |
| `nera-strikeawin.php` | Schedules on activation, unschedules on deactivation |
| `admin/class-run-clock-admin.php` | This screen |

---
---

# Tiếng Việt

# Runbook — triển khai bản sửa run clock (X2)

**Nội dung:** sửa bộ quét run bỏ dở vốn chưa bao giờ chạy, để vé mà người chơi đã
kiếm được nhưng chưa bao giờ nhận cuối cùng cũng được mint.
Xem [ADR 0020](adr/0020-run-level-deadline.md).

## Trước khi bắt đầu — chuyện gì sẽ thực sự xảy ra

Đọc phần này kể cả khi bỏ qua phần còn lại.

Trên site mà bộ quét chưa bao giờ chạy, đang tồn một đống run kẹt ở trạng thái
`active`. Một số người chơi trong đó **đã trả lời đúng và kiếm được vé nhưng chưa
bao giờ được cấp**, vì việc cấp nằm trong khâu finalize mà finalize không chạy.

Lần triển khai này làm ba việc tách biệt, và chỉ việc thứ ba là không lùi được:

| Bước | Tác động | Lùi được? |
|---|---|---|
| 1 · Deploy code | Thêm cột `expires_at` và lên lịch quét. Không đổi gì khác. | Được |
| 2 · Back-fill | Gán đồng hồ cho run cũ — **và cron 5 phút sẽ tự quét chúng** | **Thực tế là không** |
| 3 · Sweep | Chỉ khiến kết quả của bước 2 xảy ra ngay thay vì chờ 5 phút | **Không** |

**Điểm không quay lại là bước back-fill, không phải bước sweep.** Bộ quét chạy theo
cron 5 phút một lần, nên một run được gán đồng hồ đã nằm trong quá khứ sẽ bị
finalize ngay sau đó, bất kể có ai bấm nút thứ hai hay không. Nút đó chỉ để bạn
nhìn thấy nó xảy ra thay vì phải đoán.

Finalize tạo ra số vé thật trong một cuộc quay thật, kèm email xác nhận. Đó là kết
quả **đúng** — những vé đó đã được kiếm. Nhưng hãy back-fill đúng lúc bạn sẵn sàng
cho việc đó, đừng sớm hơn, và báo trước cho người theo dõi cuộc quay.

**Chạy trên staging trước.** Màn hình cho bạn xem số liệu trước khi quyết định.

## Bước 0 — Backup database

Không phải tùy chọn với live. Các bảng quan trọng: `wp_nera_saw_runs`,
`wp_nera_saw_run_slots`, và bảng số vé của Lottery for WooCommerce.

## Bước 1 — Deploy code

Deploy như bình thường (GitHub release hoặc upload zip). Không cần chạy tay gì cả.

Ở lần tải trang kế tiếp, plugin sẽ:

- thêm `runs.expires_at` và index qua `dbDelta` — DB version chuyển `0.5.0 → 0.6.0`;
- lên lịch bộ quét (`nera_saw_sweep_reservations`, 5 phút một lần) nếu chưa có.

**Kiểm tra:** vào **Strike A Win → Run Clock**. Dòng *"Sweep scheduled"* phải hiện
một mốc thời gian trong vòng 5 phút tới.

> **Nếu hiện NOT SCHEDULED**, plugin chưa chạy tới `init` — thường là do fatal ở
> đâu đó sớm hơn trong request. Kiểm tra error log trước khi đi tiếp.
>
> **Nếu hiện một mốc mà mãi không nhích**, WP-Cron không chạy trên site này. Rất
> phổ biến ở host có `DISABLE_WP_CRON` và dùng cron job thật gọi `wp-cron.php`.
> Xác nhận job đó tồn tại, hoặc dùng nút *Run the sweep now* theo lịch của bạn.

Tới đây **chưa có gì thay đổi với bất kỳ người chơi nào.** Run mới có đồng hồ từ
giờ trở đi; run cũ chưa bị đụng.

## Bước 2 — Nhìn số liệu

**Strike A Win → Run Clock** hiển thị:

| Dòng | Ý nghĩa |
|---|---|
| Active runs with no clock | Phần tồn. Không back-fill thì không bao giờ quét được. |
| Active runs with a clock | Bình thường — bắt đầu sau lần deploy này. |
| Clocked and already past it | Lần quét kế tiếp sẽ finalize nhóm này. |
| **Tickets already scored but unminted** | **Số vé đang nợ người chơi.** Đây là con số cần quan tâm. |
| Oldest clockless run | Phần tồn kéo dài tới đâu. |

Bảng preview liệt kê 20 run kế tiếp kèm đồng hồ mà mỗi run *sẽ* nhận, và liệu nó
có bị quét ngay hay không. **Preview không ghi gì cả.**

Kiểm tra hai điều trước khi đi tiếp:

1. *"Oldest clockless run"* có khớp với thời điểm bộ quét ngừng hoạt động không?
2. *"Tickets already scored but unminted"* có phải một con số hợp lý không, hay lớn
   bất thường so với lượng vé bình thường của site? Nếu thấy sai, dừng lại và hỏi.

## Bước 3 — Back-fill theo lô

> ⚠ **Đây là điểm không quay lại.** Mọi thứ bạn back-fill ở đây sẽ bị cron 5 phút
> finalize ngay sau đó và phát vé. Đừng back-fill vào tối thứ Sáu khi không có ai
> trực để trả lời về đám email sắp gửi đi.

Bấm **Back-fill next 100**. Lặp lại cho tới khi *"Active runs with no clock"* về 0.

Mỗi lần bấm là một request riêng nên phần tồn lớn cũng không bị timeout. Thao tác
này idempotent — run đã có đồng hồ thì bỏ qua, bấm lại vô hại.

Nếu muốn back-fill trước và phát vé sau, cách duy nhất đáng tin là chặn bộ quét
trước: tắt plugin, hoặc gỡ lịch `nera_saw_sweep_reservations`. Còn không thì cứ coi
như back-fill và phát vé xảy ra cùng lúc.

## Bước 4 — Quét

Chờ tối đa 5 phút cho cron, hoặc bấm **Run the sweep now** để chạy ngay và xem kết
quả.

Mỗi lượt quét xử lý tối đa 200 run, nên phần tồn lớn cần vài lượt. Cron sẽ tự chạy
tiếp; nút bấm chỉ để bạn theo dõi.

**Kiểm tra sau khi quét:**

- *"Clocked and already past it"* giảm dần về 0.
- **Strike A Win → Report** hiển thị các run đó là `expired` hoặc `abandoned`,
  không còn `active`.
- Các competition liên quan hiện số vé mới.
- Người chơi đang bị nợ vé đã nhận được.

## Bước 5 — Xác nhận hành vi thường ngày

Chơi thử một lượt trên staging:

1. Bắt đầu một lượt, trả lời câu 1, rồi **đóng tab**.
2. Chờ quá cửa sổ thời gian của lượt (số câu × timer + một chút).
3. Trong vòng 5 phút sau đó, run hiện là `expired` trong Report, và vé của câu 1
   đã được mint.

Rồi tới đường reconnect:

1. Bắt đầu một lượt, trả lời câu 1, đóng tab.
2. Quay lại **trước khi** cửa sổ hết hạn.
3. Bạn phải vào đúng câu đang sống *ở thời điểm đó* — không phải câu 2 với timer
   đầy. Các câu đã trôi qua trong lúc bạn vắng mặt tính 0 điểm.

## Lùi lại

| Nếu bạn đã | Làm thế này |
|---|---|
| Chỉ deploy (bước 1) | Tắt plugin — nó tự gỡ lịch quét. Cột thừa vô hại. |
| Back-fill vài phút trước, cron chưa chạy | Tắt plugin trước đã — bạn đang chạy đua với cron — rồi `UPDATE wp_nera_saw_runs SET expires_at = NULL WHERE status = 'active';` |
| Đã quét | **Không lùi được.** Vé đã mint và email đã gửi. Muốn lùi thì phải phục hồi backup, và chấp nhận mất luôn mọi thứ phát sinh sau đó. |

## Code đã đổi những gì

| File | Thay đổi |
|---|---|
| `class-database.php` | `runs.expires_at` + index `sweep (status, expires_at)`; DB version 0.6.0 |
| `class-run.php` | Đồng hồ cấp lượt chơi, nối deadline, `catch_up()`, viết lại `finalize_stale()`, mark seen khi phục vụ, redraw có transaction |
| `class-reservations.php` | Đăng ký lịch 5 phút và `ensure_scheduled()` trên `init` |
| `nera-strikeawin.php` | Lên lịch khi kích hoạt, gỡ lịch khi tắt |
| `admin/class-run-clock-admin.php` | Màn hình này |
