# BUG-154: Client Documents "Uploaded" date uses the viewer's day

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2
**Author:** awlad

## Problem
In the Owner portal, under Clients > (client) > Documents, the "Uploaded" column showed the UTC day instead of the viewer's day. QA was in Asia/Dhaka (UTC+6) and uploaded a file at 02:06 AM on 01-Nov-2026. The column showed `2026-10-31`, while the Activity sidebar on the same page said "Today at 02:06 AM". The cause: `BorrowerService::getBorrowerDocuments` built `date` with `$doc->created_at->format('Y-m-d')`, which is the UTC day of a UTC timestamp.

## Solution
`date` now comes from `toTimezone($doc->created_at)`. This converts the timestamp to the request's `X-Timezone` and returns `Y-m-d`, the same shape as before. If the header is missing, the app's zone (UTC) is used.

`toDateOnly()` would not fix this. It only formats and does not shift, so it is meant for DATE columns such as `funded_date` and `payout_date`. `created_at` is a timestamp, so which day it falls on depends on the viewer's zone. This is the same approach BUG-120 used for commission `paid_at`.

The frontend needs no change. The admin `ClientDocumentsSection.tsx` / `DocumentCategoryTable.tsx` display `date` as it is.

## Files changed
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: document `date` is now built with `toTimezone()`.

## Tests
- Added `BorrowerDocumentsApiTest::the_uploaded_date_is_the_viewers_day_not_the_utc_day`. For a document created at 31-Oct 20:06 UTC, it checks that the date is `2026-11-01` with `X-Timezone: Asia/Dhaka` and `2026-10-31` with no header. Red first: before the fix, the test returned `2026-10-31` for Dhaka.
- `tests/Feature/Borrower` + `CreditReportDocumentTest`: 320 tests, all passed.

## Open / follow-up
- QA also reported that rejecting a document once logged "status was updated to Rejected" twice (02:07 and 02:08). That is a separate issue and was not looked at here.
- `FundingRequest.php:105` (`uploaded_on`) also formats a document's `created_at` in UTC, on a different screen. It was not changed here; check it if QA reports the same issue there.
