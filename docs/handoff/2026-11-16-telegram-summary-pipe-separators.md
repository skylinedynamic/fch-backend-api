# Telegram summary: pipe-separated values

2026-11-16

## Problem
In the weekly and monthly owner summary sent to Telegram, the values on each card line were separated by three spaces (`$14.3K   📈 +30%   was $11.0K`), and the partner/client totals used a middle dot (`380 active · 21 new`). They were hard to tell apart in the chat.

## Solution
Every value on a card line is now separated by ` | `:

```
<b>$14.3K</b> | 📈 +30% | <i>was $11.0K</i>
<b>410</b> | 380 active | 21 new
<b>41</b> | 🆕 new
```

The title (`Four Corner · Weekly Summary`) and the `├ FCH:` / `└ Partners:` split lines are unchanged.

## Files changed
- `app/Services/FCH/Common/OwnerSummary/OwnerTelegramMessage.php`: `GAP` is now `' | '`; docblocks updated.
- `lang/en/owner_summary.php`: `telegram.active_new` is now `:active active | :new new`.
- `tests/Unit/OwnerSummary/OwnerTelegramMessageTest.php`: expectations use `|`.

## Testing
- Test changed first and run: 3 failed (red), as expected.
- After the fix: `tests/Unit/OwnerSummary` 31 passed; `tests/Integration/OwnerSummary` 37 passed.

## Open items
- None. The email report and Business Insights screen are not affected.
