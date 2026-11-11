# BUG-187: Current / Recent Bill card shows the invoice's own date

**Date:** 2026-11-11
**Branch:** round-1-run-2-fix
**Author:** Awlad Hossain

## Problem
QA Round 1 Run 2, BUG-187. The retest on 10-Nov-26 came back "Partially Fixed" and the bug was reopened. On My Subscription › CURRENT / RECENT BILL, the Certified partner's invoice INV-20261020008 showed `Date: 2026-10-21`. The invoice itself (`/invoices/276`, invoice API `created_at`) says 2026-10-20.

The cause is that the card and the invoice format the same `created_at` with different helpers:
- `recent_bill.created_at` used `toTimezone()`, which shifts the UTC timestamp into the viewer's `X-Timezone`. An invoice made in the UTC evening becomes the next day for a viewer ahead of UTC, such as Dhaka at UTC+6.
- The invoice detail, the invoice list and Recent Payments History use `toDateOnly()`, which gives the stored date without a shift.

The borrower Billing Details card (`BorrowerBillingService`) had the same code and the same bug.

The other two retest points are unchanged. Showing a commission payout on the card is by design and QA accepted it. The Recent Payments History part was already fixed.

## Solution
`recent_bill.created_at` now uses `toDateOnly()` in both services, so the card shows the same date as the invoice it links to, whatever the viewer's timezone. The fix makes the card match the rest of the invoice screens. Making every invoice date timezone-aware instead would change many screens and is outside this bug.

## Files changed
- `app/Services/FCH/Common/MySubscription/MySubscriptionService.php`: `recent_bill.created_at` uses `toTimezone()` → `toDateOnly()`.
- `app/Services/Borrower/Billing/BorrowerBillingService.php`: the same change on the borrower side.
- `tests/Feature/MySubscription/MySubscriptionRecentPaymentsTest.php`: new test. An invoice at 2026-10-20 20:00 UTC, viewed with `X-Timezone: Asia/Dhaka`, gives `2026-10-20` on the card, the same as the invoice detail API.
- `tests/Feature/Billing/BorrowerBillingApiTest.php`: the same test for `/borrower/billing-details`.

## Tests
- Red: both new tests failed before the fix with `'2026-10-21'` instead of `'2026-10-20'`, the bug as QA reported it.
- Green: `php artisan test tests/Feature/MySubscription tests/Feature/Billing tests/Feature/Partner/PartnerAccountApiTest.php tests/Feature/Subscription` gave 158 passed (626 assertions).
- Frontend and E2E: see the handoff with the same name in `borrower-fch` and `react-fch`.

## Open / follow-up
- Google Sheet (Round 1 Defect Log, BUG-187): set the status for retest. The retest column also holds BUG-188's dev note, pasted into the wrong row; it should be removed.
- Other `toTimezone()` dates on these responses, such as `enrollment_completed_at`, were left as they are. They are not invoice dates and were not part of this bug.
