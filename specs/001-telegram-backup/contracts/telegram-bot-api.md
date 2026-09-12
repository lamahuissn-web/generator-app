# Contract: Telegram Bot API calls this feature makes

The panel talks to exactly two Bot API methods, both as JSON-inspected responses. Nothing else in
the application talks to Telegram; `App\Services\Backup\TelegramBackupSender` is the only caller.

**Base**: `https://api.telegram.org/bot{token}/{method}` — the token comes from the encrypted
setting, and never appears in a log line, an exception message, or the panel.

## 1. Deliver a copy — `sendDocument`

| | |
|---|---|
| **Method** | `POST /bot{token}/sendDocument` |
| **Body** | `multipart/form-data` |
| **Fields** | `chat_id` (string), `document` (the `.sql` file), `caption` (the message text) |
| **Timeout** | 120 s connect + read; a weak office connection is the normal case, not an error |
| **Success** | HTTP 200 with `{"ok": true, "result": { "document": { "file_id": ..., "file_size": ... } }}` |
| **Failure** | HTTP 200 with `{"ok": false, "error_code": N, "description": "..."}`, or a transport error / non-200 |

**Handling**

- A 200 with `ok: true` is the ONLY outcome that records `status = sent`. Reading HTTP status alone
  would report a delivered backup that never arrived — the Bot API answers 200 for most refusals.
- On `ok: false`, `description` is stored verbatim in `backup_attempts.error` and shown to the
  operator. It is already a human sentence in the language of the Bot API; rewording it would make
  the message less exact than the thing it is reporting.
- On a transport failure (connection refused, timeout, DNS), the reason is composed from the
  exception and stored the same way.
- One copy = one `sendDocument` call, unless it was split (below).

**Caption** (the message that carries the file, when the copy is one part):

```
نسخة قاعدة البيانات — 2026-09-12
الحجم: 2.4 MB
```

Composed through the panel's translation layer, so an English panel posts English. Sizes and dates
are Latin digits; the whole line stays readable inside an Arabic chat.

## 2. Report a failure — `sendMessage`

| | |
|---|---|
| **Method** | `POST /bot{token}/sendMessage` |
| **Body** | `application/json` |
| **Fields** | `chat_id`, `text` |
| **Success** | HTTP 200 with `{"ok": true, ...}` — best effort; this call is never retried in a loop |

**When it is used**

1. A run failed: the text names the night and the reason.
2. **Catch-up**: on any successful send, if attempts failed since the last delivered copy, one line
   is posted naming how many and when the failures started — so a channel that was down for three
   nights produces one message, not silence (FR-004, SC-002).
3. A destination test from the screen: the same call, with the test's own wording.

## 3. Splitting an oversized copy

When the file exceeds `backup.delivery_limit_bytes` (default 45 MB, under Telegram's 50 MB document
limit), it is split at statement boundaries and each part is sent with its own `sendDocument`:

| | |
|---|---|
| **Part naming** | `<name>-part1of3.sql`, `<name>-part2of3.sql`, `<name>-part3of3.sql` |
| **Caption** | the same caption plus a part line: `الجزء 1 من 3` |
| **Failure mid-sequence** | the attempt is recorded `failed` with the part number in the reason; parts already delivered stay in the chat, and the next run sends a fresh, complete copy rather than resuming a stale one |
| **Never** | a part is sent if the file could not be split on a statement boundary — a truncated SQL fragment in the chat is worse than a failure the office can see |

## 4. What is deliberately not used

- **`deleteMessage`** — the office's chat history is the record; the panel does not curate it.
- **Webhooks / `getUpdates`** — nothing is read back from Telegram; the chat is a destination, not a
  channel. A reply in the chat is not an instruction to the panel.
- **Any file above the API's document limit in a single call** — see the splitting rule instead.
