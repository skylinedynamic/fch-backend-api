# Commissions page: "lazy load [entity]" error

**Date:** 2026-11-16
**Branch:** feature/agreement-builder-modification
**Author:** Awlad Hossain (with Claude Code)

## Problem
The admin panel's Commissions page didn't load. `POST /api/v1/common/commissions` returned:

```
{"status": false, "message": "Attempted to lazy load [entity] on model [App\\Models\\User\\User] but lazy loading is disabled."}
```

The list works out the expected payout of unpaid, closed deals (`CommissionViewer::expectedPayout()`):
- For a host client, that is the assigned user's Funding compensation (`CommissionPayoutService::forAssignedUser()` → `CompensationService::getFor()`).
- `resolveOwner()` reads `$user->entity`, the Employee linked to an employee login, whose compensation that login shares. The query eager-loaded `borrower.assignedUser` but not its `entity`.
- Outside production `Model::preventLazyLoading()` is on. Laravel refuses a lazy load on a model fetched with others, so the request failed as soon as two closed host-client deals were assigned to two different employee logins.

The bug predates this branch. It surfaced once the local data had such deals.

## Solution
- `CompensationService::resolveOwner()` loads the relation explicitly (`loadMissing('entity')`). It works for any caller, whether or not the user came eager-loaded.
- `CommissionViewer::expectedPayout()` eager-loads `borrower.assignedUser.entity`, so the list doesn't run one query per deal.

## Files changed
- `app/Services/FCH/Common/Compensation/CompensationService.php`: `loadMissing('entity')` in `resolveOwner()`.
- `app/Services/FCH/Common/Commission/CommissionViewer.php`: eager-loads `borrower.assignedUser.entity`.

## Testing
- New: `CommissionPayoutTest::the_commission_list_loads_when_an_employee_login_is_assigned_to_a_closed_deal`. It uses two closed host-client deals assigned to two employee logins; with one deal or one shared assignee, Laravel doesn't flag the lazy load. The test failed before the fix and passes after.
- `tests/Feature/CommissionPayout` + `tests/Feature/Commission`: **86 passed**. `PartnerAccountApiTest`: **27 passed**.
- Local DB, as the host (craig.rice): the list returns "Commissions retrieved successfully."

## Open items
- No admin-panel change was needed. The Playwright Commissions specs mock the API, so they can't catch a backend error like this one.
- Other `->entity` reads on a User (e.g. `PlanFeeController`, borrower `FundingController`) could hit the same error when called on a User loaded with others. They are not reached from a list today.
