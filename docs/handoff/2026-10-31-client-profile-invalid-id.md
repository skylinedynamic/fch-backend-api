# Client profile counters and activity list with an id that is not one (BUG-001, round 2)

2026-10-31

## Problem
QA round 2 defect log **BUG-001** (R2-OP-GLOB-03), admin `/clients/abc`:
- `POST /api/v1/common/borrowers-profile-states-details-count/abc` returned **500**. `BorrowerController::profileStatsCount(int $borrowerId)` threw a `TypeError` while binding `"abc"`, before the method's `try` could turn it into a 404.
- `POST /api/v1/common/activity-logs/fetch-all-activities` with `borrower_id: "abc"` listed unrelated entries. `ActivityLogService::list()` casts the filter to int (`0`), and on MySQL `json_unquote(json_extract(properties, '$.borrower_id')) = 0` is true for every entry whose `borrower_id` is JSON `null` (`'null'` coerces to 0).

## Solution
- `routes/common.php`: the counters route has `->whereNumber('borrowerId')`, so a non-numeric id is a 404 like an unknown one.
- New `FetchActivityLogsRequest` on `ActivityLogController::index`: `borrower_id`, `partner_id`, `lead_id`, `funding_request_id`, `funding_application_id` are `nullable|integer|min:1`; anything else is a 422 through `BaseApiRequest`. Validation was chosen over silently returning nothing so the bad id is visible, and because SQLite (the test DB) does not reproduce MySQL's `'null' = 0` match — a list-content test would pass without the fix.
- The admin page no longer sends these requests before the client has loaded (`Fch-admin-frontend-react/docs/handoff/2026-10-31-client-profile-invalid-id.md`).

## Files changed
- `routes/common.php` — numeric constraint on `borrowers-profile-states-details-count/{borrowerId}`.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/FetchActivityLogsRequest.php` — new; id filters must be positive integers.
- `app/Http/Controllers/Api/V1/FCH/Common/ActivityLog/ActivityLogController.php` — `index()` takes the new request.
- `tests/Feature/Borrower/ClientProfileInvalidIdTest.php` — new.

## Testing
- `ClientProfileInvalidIdTest` failed first (6 failures: 500 on `abc`, 200 instead of 422 for each filter), then passed.
- `ClientProfileInvalidIdTest` + `tests/Feature/ActivityLog` + `BorrowerTenantIsolationTest` — OK, 177 tests, 703 assertions.
- Full PHPUnit suite not run.

## Open items
- Other `borrowers/{id}` routes have no numeric constraint; most use `findOrFail` and 404, but any with an `int` parameter will 500 the same way.
- The borrower-portal activity list (`BorrowerActivityLogController`) is not changed.
