# User seat limit: one rule for the Users screen and the server's check (BUG-112 follow-up)

**Date:** 2026-10-29
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
Looking into BUG-112 (whether a free-trial partner may add users) turned up a mismatch in how a partner's seat limit is worked out:
- `GET common/user-count` (what the Users screen and the employee form read): the subscription's `user_seats`, else the Plan & Fee "User Seat" plan's `total_seat_count` for the partner type, else 3.
- The server's own check when a user is created (`UserService::create`) or an employee login is created (`EmployeeService::create`): the subscription's `user_seats` only, else 3 when there is no subscription.

With a subscription whose `user_seats` is empty (no seat plan when it was made), the screen said seats were free and opened the form. Save was then refused with "User limit reached. Please purchase additional seats." For an employee login, the refusal was an uncaught 500.

## Solution
The rule now lives in one place on `Partner`, and all three callers use it. The rule itself is unchanged; the server check now applies the same fallbacks the screen already did.
- `usedUserSeats()`: the partner's own login plus its employees' logins.
- `userSeatPlan()`: the active Plan & Fee "User Seat" plan for its partner type.
- `userSeatLimit()`: `user_seats` → seat plan `total_seat_count` → `DEFAULT_USER_SEATS` (3).
- `hasFreeUserSeat()`.

The seat count is still a copy taken when the subscription is made. A later Plan & Fee change only reaches partners whose `user_seats` is empty.

## Files changed
- `app/Models/Partner/Partner.php`: the four methods above, and `DEFAULT_USER_SEATS`.
- `app/Services/FCH/Common/CommonService.php`: `getUserCount()` uses them.
- `app/Services/FCH/Common/User/UserService.php`: the create check uses `hasFreeUserSeat()`.
- `app/Services/FCH/Common/Employee/EmployeeService.php`: the same, for an employee created with a login.

## Tests
- Added `tests/Feature/User/UserSeatLimitRuleTest.php` (5 tests). 3 were red first: an empty `user_seats` falling back to the plan on both screen and Save, and to 3 with no plan; an employee login following the same fallback. 2 pin behaviour that already held: the subscription's own `user_seats` wins, and the user is refused at the limit.
- Suites run: `tests/Feature/{User,Employee,Partner,Billing}`, `tests/Unit/Subscription`: 259 passed. Full `php artisan test`: 4075 passed, 2 skipped, 0 failed.

## Open / follow-up
- **Editing an employee with "Create account" ticked (`EmployeeService::updateWithUserSync`) has no seat check at all**, so it can add a login past the limit. It also has no paid-seat path, so adding the check needs the purchase flow too. Left as is.
- **BUG-112's rule** (a free-trial partner may add users up to its seat limit) still needs Craig's confirmation. See the QA sheet.
