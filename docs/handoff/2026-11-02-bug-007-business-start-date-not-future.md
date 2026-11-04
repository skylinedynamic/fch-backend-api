# BUG-007: a business start date in the future is refused

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-007 (R2-OP-CLI-02): on the admin's Client Info, Business Start Date = 01 Jan 2027 then Update answered "Borrower updated successfully", but after reload the field was empty.

A probe test showed the cause:
- **The API stored the date.** `UpdateBorrowerRequest` had only `nullable`, and a GET returned it.
- **The admin's date picker hid it.** The PrimeReact Calendar has `maxDate={new Date()}`, so it cannot show a date after today and rendered the field empty.
- **The next save really cleared it**, because the empty field was sent back.

## Solution
A new rule, `App\Rules\NotFutureDate`:
- It fails a date after today with `common.date_not_in_future`, "The date cannot be in the future."
- It reads the date the way `normalizeDate()` stores it: YYYY-MM-DD, MM-DD-YYYY or MM/DD/YYYY.
- An empty or unreadable value passes, because `required`, `nullable` and `date` decide those.

It is on `business_start_date` in every request that takes it:
- Admin Add / Edit Client: `StoreBorrowerRequest`, `UpdateBorrowerRequest`.
- The client's Business Details: `UpdateBusinessInfoRequest`.
- A change request: `StoreChangeRequest`.
- Leads, which carry it into the client on conversion: `StoreLeadRequest`, `UpdateLeadRequest`, `UpdateLeadContactInfoRequest`.

Pre-qualification and the embed form already had `before_or_equal:today`.

**Frontend:** no change. The admin Client form already shows the API's `business_start_date` field error, and its picker already stops at today.

## Files changed
- `app/Rules/NotFutureDate.php`: new.
- `lang/en/common.php`: `date_not_in_future`.
- The seven requests listed above.

## Testing
- **New, all failed first:**
  - `tests/Unit/Rules/NotFutureDateTest.php`, 2 tests: each format, today vs tomorrow, and empty values.
  - `tests/Feature/Borrower/BusinessStartDateNotFutureTest.php`: the admin update gets 422 and the stored date is unchanged; a past date still saves.
  - `LockedProfileFieldsTest::a_business_start_date_in_the_future_is_refused`: the client's own Business Details.
  - `BorrowerProfileApiTest::a_business_start_date_change_request_in_the_future_is_refused`.
- **Run:** `tests/Feature/Borrower` + `tests/Feature/Lead` + `tests/Unit/Rules`, 393 passed.

## Open items
- Clients that already have a future business start date stored keep it. A one-off query can list them, if wanted.
