# Partner profile Client list: Joining Date is the day the client was created

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
Admin › Partners › a partner › **Client** tab. The "Joining Date" column showed the borrower's `business_start_date` (when the business started), not when the client joined the platform. `POST /api/v1/admin/partners/fetch-borrowers` did not return `created_at`.

## Solution
- `BorrowerPartnerResource` adds `created_at`, formatted with the `toDateOnly()` helper (`Y-m-d`). `business_start_date` is still returned, so nothing that reads it breaks.
- `PartnerService::getBorrowers()` global search matches `created_at` instead of `business_start_date`, because the column now shows it.
- The column filter and sort already work on any borrower column through `QueryFilter`, so `created_at` needed no change there.
- The admin frontend switches the column to `created_at` (see the admin repo's handoff of the same name).

## Files changed
- `app/Http/Resources/FCH/Borrower/BorrowerPartnerResource.php`: `created_at` via `toDateOnly()`.
- `app/Services/FCH/Partner/PartnerService.php`: search on `created_at`.
- `tests/Feature/Partner/PartnerAdminApiTest.php`: two new tests. The joining date is `created_at` as a date only, and clients are filtered by joining date.

## Testing
- `a_clients_joining_date_is_the_day_it_was_created` failed before the change (`null`) and passes after it.
- `clients_are_filtered_by_joining_date` passed straight away; it covers the existing `QueryFilter` behaviour.
- `PartnerAdminApiTest`: 37 passed.

## Open items
- `toDateOnly()` formats `created_at` as stored (UTC), so a client created late in the evening US time can show the next day.
- Not committed; deploy together with the admin change.
