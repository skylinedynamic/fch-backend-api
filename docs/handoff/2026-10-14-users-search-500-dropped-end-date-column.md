# Users search answered 500 — dropped `end_date` column

**Date:** 2026-10-14 · **Branch:** `e2e-missing-tests` (uncommitted) · **Found by:** admin-portal live E2E (`Fch-admin-frontend-react/docs/e2e/ISSUES.md` E2E-012, spec `e2e/sidebar/users/users.live.spec.ts`)

## Problem
Typing anything in the admin Users search box made `POST /api/v1/common/users/fetch-users` answer **500 "Failed to retrieve users."** `User::scopeSearch()` still matched `users.end_date`, which migration `2026_08_15_000002_drop_payment_and_date_columns_from_users_table` dropped, so MySQL raised `SQLSTATE[42S22] Unknown column 'end_date'`. `UserController::fetchUsers()` catches every `Throwable` and returns the 500 without logging, so nothing reached `storage/logs`.

## Solution
`end_date` removed from `User::scopeSearch()`; it still searches name, email, phone number, entity type, status and role name.

The existing HTTP-level tests could not have caught this: on SQLite (the test DB) an unknown double-quoted column is read as a string literal, so the query succeeds. The new test checks every column the search uses against the real `users` schema.

## Files changed
- `app/Models/User/User.php` (`scopeSearch`)
- `tests/Feature/User/UserListSearchTest.php` (new, 3 tests)

## Verified
- `UserListSearchTest › the search only uses columns the users table has` failed first ("users.end_date is searched but does not exist"), then passed.
- `php artisan test tests/Feature/User` — 16 passed.
- Local API (`fch_dev`): the same search request answers 200.

## Pending
- Log the exception in `UserController::fetchUsers()` (and the other `catch (Throwable)` → `serverError` handlers) so the next failure is visible in the logs.
