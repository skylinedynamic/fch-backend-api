# R3-BUG-022: a requested amount of "5e4" is refused

Date: 2026-11-15

## Problem

On QA Draft application 112's Funding Request step (`POST common/funding-application/funding-request`), QA sent four requested amounts:
- `abc`: "must be a number"
- `-100`: "cannot be negative"
- `''`: required
- `5e4`: **no requested-amount error**

Had the rest of the request been valid, `5e4` would have been stored as 50,000. Nothing was saved, because every call got 422 on another field.

All three forms that take a requested amount used `required|numeric|min:0`. Laravel's `numeric` is PHP's `is_numeric()`, which also takes `5e4`, `.5`, `50000.` and a leading space.

## Solution

- **New rule `App\Rules\MoneyAmount`.** It takes a plain amount only: digits with up to two decimals, e.g. `50000` or `50000.50`, or a JSON number.
  - Refused: scientific notation, a missing leading digit, a trailing dot, three decimals, spaces, a thousands comma, a plus sign and hex.
  - A leading minus is let through, so `min:0` keeps its "cannot be negative" message.
  - Message: "Enter the amount as a plain number, e.g. 50000 or 50000.50." (`borrower_validation.money_amount_format`).
- **Applied after `numeric` and `min:0`** on `requested_amount` in:
  - `FCH/Partner/FundApplication/StoreFundingRequestRequest` (partner funding request step, QA's)
  - `Borrower/FundApplication/StoreFundingRequestRequest` (client funding request step)
  - `Borrower/Funding/StoreFundingRequestRequest` (client funding request)

`abc`, `-100` and `''` keep their messages.

## Files changed

- `app/Rules/MoneyAmount.php` (new).
- The three `StoreFundingRequestRequest` classes above: `requested_amount` uses the rule.
- `lang/en/borrower_validation.php`: `money_amount_format`.
- `tests/Unit/Rules/MoneyAmountTest.php` (new, 17): 7 amounts pass, 10 kinds of non-amount are refused.
- `tests/Feature/FundApplication/RequestedAmountFormatTest.php` (new, 9): for each of the three forms:
  - `5e4` is refused
  - `abc`, `-100` and `''` keep their messages
  - `50000` and `50000.5` have no amount error

## Testing

- **Seen failing first:** `5e4` on all three forms (3 of the 9). The other 6 pin the messages that stay.
- **`tests/Unit/Rules/MoneyAmountTest`, `tests/Feature/FundApplication`, `Funding`, `Borrower`, `General`:** 697 passed (1 skipped).
- **Full suite (after R3-BUG-021 to 024):** 5,655 tests, all passed (2 skipped).

## Open items

- **Other money fields:** monthly/annual revenue, debt balance, the embed form and pre-qualification still use plain `numeric`. They were not in this bug; the same rule can be added there.
