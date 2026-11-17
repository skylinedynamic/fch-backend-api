# Owner summary lazy-load error on assigned staff

2026-11-09

## Problem

`GET /api/v1/common/owner-summary` returned a 500 for the host owner, so the admin panel's Owner Summary page failed:

```
Attempted to lazy load [entity] on model [App\Models\User\User] but lazy loading is disabled.
```

`CommissionViewer::expectedPayout()` eager-loaded `borrower.assignedUser`, but not that user's `entity`. Working out a staff payout goes through `CompensationService::resolveOwner()`, which reads `$user->entity`.

Eloquent only enforces strict lazy loading on models hydrated together, so it failed only when the unpaid funded deals had **two or more different assignees**. The existing fixtures had one assignee with a payout due, so the tests passed. The same method also backs `payouts_due_amount` on the host owner's commissions list (`POST /api/v1/common/commissions`), which failed the same way.

## Solution

Eager-load `borrower.assignedUser.entity` in `expectedPayout()`. The relation was simply missing, and this fixes both endpoints in the one place they share.

`CompensationService::resolveOwner()` was left unchanged. Calling `loadMissing()` there would hide the error but keep a query per user.

The other two places that load `borrower.assignedUser` were also left unchanged, because neither can have two assignees: the host-staff list (`CommissionService.php:44`) only shows the viewer's own clients, and the details page (`CommissionService.php:147`) loads one commission.

## Files changed

- `app/Services/FCH/Common/Commission/CommissionViewer.php`: eager-load the assignee's `entity`
- `tests/Feature/OwnerSummary/OwnerSummaryTest.php`: regression test for the summary endpoint
- `tests/Feature/Commission/CommissionViewerTest.php`: regression test for the commissions list
- `tests/Integration/Commission/PayoutsDueTest.php`: new; `payoutsDue()` with the real services
- `tests/Unit/Commission/CompensationOwnerTest.php`: new; a loaded `entity` is used under strict lazy loading

## Testing

- **Before the fix:** the feature and integration regression tests failed with the reported error. The unit test passed, because it pins existing behaviour.
- **After the fix:** OwnerSummary, Commission, CommissionPayout, and the new Integration and Unit folders: 99 tests, all pass.
- **Live E2E:** `react-fch` `e2e/sidebar/owner-summary/owner-summary.live.spec.ts` against the local API: 3 passed (including login setup). No new lazy-load errors in the log. The spec was not run before the fix; the log shows the same page failing with this error.
- **Not run:** the full backend suite.
- **No frontend change:** the response shape is the same, so no portal or admin tests needed updating.

## Open items

- Production has lazy loading enabled (`Model::preventLazyLoading(!app()->isProduction())`), so this would not have thrown there, only run extra queries. Any non-production environment throws.
- `expectedPayout()` still runs one compensation query per deal with an assigned staff member. This adds to the performance item in `2026-11-09-owner-summary-endpoint.md`.
