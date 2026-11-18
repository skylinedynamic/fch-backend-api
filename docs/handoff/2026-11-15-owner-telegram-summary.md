# Owner summary on Telegram (weekly + monthly)

2026-11-15

## Problem
The owner wanted a short business summary on their phone through Telegram, sent by the scheduler with no chat or replies. Until now there was only the weekly email/PDF report.

## Solution
A one-way Telegram sender. The Bot API is the only supported way for a server to post to Telegram, and here the bot is only the sender. Messages can't go to a phone number, only to a chat id, so the owner presses Start on the bot once and its chat id goes in `.env`.

- **When**: last week every Sunday at 10:00 Eastern, and last month on the 1st at 10:00 Eastern. These are two scheduler entries for `owner-summary:send-telegram-summary {week|month}`, with the same timezone, overlap lock and on-call failure mail as the weekly report.
- **What**: the Business Insights screen's six headline cards in Telegram HTML (`parse_mode=HTML`), using the screen's labels (`owner_summary.report.*`), the same `OwnerSummaryService` numbers (live payments) and the same money/% formatting. On 2026-11-15 it went from 3 plain lines to all six cards, then to this layout with emoji and bold:
  ```
  📊 Four Corner · Weekly Summary        ← bold
  🗓 1 – 7 Nov 2026

  💰 Net Earnings                        ← bold
  $14.3K   📈 +30%   was $11.0K          ← value bold, "was" italic

  🏦 Funded Volume
  $2.31M   📉 -22%   was $2.96M
  ├ FCH: $1.31M
  └ Partners: $1.00M
  ... Funded Deals ✅, Partners 🤝, Clients 👥, Leads 🎯 the same way
  ```
  Monthly runs say "Monthly Summary" and "🗓 October 2026". When there's nothing in the period before to compare with, a card shows "🆕 new" or "➖ no change" with no "was". Every value is HTML-escaped, so a company name with `&` or `<` stays text.
- **Off by default**: if `TELEGRAM_BOT_TOKEN` or `TELEGRAM_OWNER_CHAT_ID` is empty, the command does nothing and exits successfully.
- **Robust**: uses Laravel's `Http` client with a 10s timeout, a 5s connect timeout, and 2 retries on connection errors only. If Telegram refuses or can't be reached, the run fails, so the on-call list gets an email.
- **Token never leaks**: the bot token is part of every request URL, so errors are rebuilt from Telegram's `description` and never pass on the connection exception, which contains the URL. Tests check that the token never appears in the output.
- **Setup helper**: `php artisan telegram:chat-ids` lists the chats that have messaged the bot, to find the chat id.

## Setup
1. In Telegram, message @BotFather → `/newbot` → copy the token into `TELEGRAM_BOT_TOKEN`.
2. Open the new bot and press **Start**.
3. Run `php artisan config:clear && php artisan telegram:chat-ids` and copy your id into `TELEGRAM_OWNER_CHAT_ID`.
4. Test: `php artisan owner-summary:send-telegram-summary week`.

## Files changed
- `app/Services/Telegram/TelegramClient.php`: new, a small Bot API client (`sendMessage`, `recentChats`)
- `app/Services/FCH/Common/OwnerSummary/OwnerTelegramMessage.php`: new, builds the message (six headline cards)
- `app/Services/FCH/Common/OwnerSummary/OwnerTelegramSummaryService.php`: new, picks the period, builds the summary and sends it
- `app/Console/Commands/SendOwnerTelegramSummary.php`: new, `owner-summary:send-telegram-summary {week|month}`
- `app/Console/Commands/ListTelegramChats.php`: new, `telegram:chat-ids`
- `routes/console.php`: weekly and monthly schedule entries
- `config/services.php`, `.env.example`: `telegram.api_url`, `bot_token`, `owner_chat_id`
- `lang/en/owner_summary.php` (`telegram.*`), `lang/en/telegram.php`: message and command wording
- Tests: `tests/Unit/OwnerSummary/OwnerTelegramMessageTest.php` (5), `tests/Integration/OwnerSummary/TelegramSummaryCommandTest.php` (8), `tests/Integration/Console/ScheduleTest.php` (two new entries)

## Testing
`php artisan test tests/Unit/OwnerSummary tests/Integration/OwnerSummary tests/Integration/Console/ScheduleTest.php tests/Feature/OwnerSummary`: 96 passed. All new tests failed first. HTTP is faked with `Http::preventStrayRequests()`, so tests never call Telegram.
No real message was sent: that needs a real bot token and chat id.

## Open items
- Not tried against the real Telegram API yet. Do the Setup steps on staging and run the command once.
- The production server's `.env` needs the two values. Leaving them empty keeps the feature off.
- No portal UI: the bot and chat are configured in `.env` only. If the owner should manage it from Report settings, that needs a webhook and a "Connect Telegram" flow.
- When the 1st falls on a Sunday, both the weekly and the monthly summary are sent (two messages).
