# BUG-191: Desired Funding sorts by currency, then amount

**Date:** 2026-11-06

## Problem
Embedded Form → Loan Applications (host and partners): sorting DESIRED FUNDING compared amounts by face value and mixed currencies, e.g. $6,546 · £15,000 · $20,000 · $25,000. The public form accepts USD, EUR and GBP.

Decision D6: a face-value sort across currencies is not acceptable. Sort by currency first, or by a USD-converted amount.

`EmbedFormController::fetchSubmissions()` ordered by `COALESCE(requested_amount, desired_funding_amount)` only.

## Solution
Option (a), chosen 2026-11-06: group by currency, then sort by amount.
- **Currency order:** follows the form's list (`config('funding_application.embed_form.currencies')`: USD, EUR, GBP) and stays the same in both directions.
- **No currency:** an amount without one counts as the form's default (the first in the list), as `EmbedFormService::defaultCurrency()` treats it.
- **Amount:** sorted asc or desc within each currency.
- **Ties:** fall back to newest first, as before.

No exchange rates are involved.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/EmbedForm/EmbedFormController.php`
- `tests/Feature/EmbedForm/EmbedFormSubmissionsApiTest.php`: new `desired_funding_sorts_by_currency_then_amount`.

## Testing
- The new test failed first (face-value order), then passed:
  - asc: USD 6,546, untyped 20,000, USD 25,000, then EUR, then GBP
  - desc: amounts reversed within USD, EUR and GBP still in the same place
- `tests/Feature/EmbedForm`: 168 passed. The existing amount-sort tests (BUG-009, the requested_amount fallback) still pass.
- **Local MySQL through the real endpoint as the host:** two submissions were set to GBP and EUR inside a rolled-back transaction. asc: the 13 USD/untyped rows from 15,000 up, then EUR 55,555, then GBP 150,000. desc: USD from 44,445,555 down, then EUR, then GBP. The data was unchanged afterwards (local has only USD and untyped submissions).

## Open items
- No live UI spec for mixed currencies: the local DB has no EUR or GBP submissions. The table UI did not change.
- Not committed.
