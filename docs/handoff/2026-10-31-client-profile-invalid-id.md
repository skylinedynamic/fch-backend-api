# Non-numeric ids: 404 instead of 500, and the activity list refuses them (BUG-001, round 2)

2026-10-31

## Problem
QA round 2 defect log **BUG-001** (R2-OP-GLOB-03), admin `/clients/abc`:
- `POST /api/v1/common/borrowers-profile-states-details-count/abc` returned **500**. `BorrowerController::profileStatsCount(int $borrowerId)` threw a `TypeError` while binding `"abc"`, before the method's `try` could turn it into a 404.
- `POST /api/v1/common/activity-logs/fetch-all-activities` with `borrower_id: "abc"` listed unrelated entries. `ActivityLogService::list()` casts the filter to int (`0`), and on MySQL `json_unquote(json_extract(properties, '$.borrower_id')) = 0` is true for every entry whose `borrower_id` is JSON `null` (`'null'` coerces to 0).

The 500 was not limited to that route: 35 more routes passed a URL id to an `int`-typed action parameter with no constraint (partner details/counters/tenant config/documents, billing, subscriptions, commissions, resources, credit-builder tiers and tasks, change requests, credit profile requests, borrower capital-access strategies, credit accounts, borrower documents, …).

## Solution
- Every `int`-typed URL parameter now has `->whereNumber(...)` at its route (`routes/common.php`, `routes/admin.php`, `routes/borrower.php`), so a non-numeric id does not match and is a 404.
- `IntRouteParametersAreNumericTest` walks all registered routes and fails, naming the route, if an action takes a URL id as `int` without a digits-only constraint — new routes are held to it.
- New `FetchActivityLogsRequest` on `ActivityLogController::index`: `borrower_id`, `partner_id`, `lead_id`, `funding_request_id`, `funding_application_id` are `nullable|integer|min:1`; anything else is a 422 through `BaseApiRequest`. Validation was chosen over silently returning nothing so the bad id is visible, and because SQLite (the test DB) does not reproduce MySQL's `'null' = 0` match — a list-content test would pass without the fix.
- The admin pages no longer send these requests before their record has loaded (`Fch-admin-frontend-react/docs/handoff/2026-10-31-client-profile-invalid-id.md`).

## Files changed
- `routes/common.php`, `routes/admin.php`, `routes/borrower.php` — `whereNumber` on the 36 routes with an `int` URL id.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/FetchActivityLogsRequest.php` — new; id filters must be positive integers.
- `app/Http/Controllers/Api/V1/FCH/Common/ActivityLog/ActivityLogController.php` — `index()` takes the new request.
- `tests/Feature/Borrower/ClientProfileInvalidIdTest.php` — new: client counters/details 404 for `abc` and `999999999`; activity list 422 per filter, numeric/no filter still 200.
- `tests/Feature/General/IntRouteParametersAreNumericTest.php` — new: route guard, plus partner counters/details `abc` → 404.

## Testing
- Both new test files failed first (500 on `abc`; 200 instead of 422; 35 unconstrained routes listed), then passed.
- `ClientProfileInvalidIdTest` + `tests/Feature/ActivityLog` + `BorrowerTenantIsolationTest` — OK, 177 tests.
- Full PHPUnit suite — OK, 4283 tests, 14247 assertions (2 skipped).

## Open items
- The borrower-portal activity list (`BorrowerActivityLogController`) is not changed; it is scoped to the signed-in borrower.
- Routes whose action takes the id untyped (`$id`) are not constrained; they reach `findOrFail` and 404 already.
