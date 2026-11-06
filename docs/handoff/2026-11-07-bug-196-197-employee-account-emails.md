# BUG-196 / BUG-197: employee account emails

**Date:** 2026-11-07

## Problem
**BUG-196:** Employees → Add Employee with "Create account" sent the "Welcome to …" login email twice, in the same second. It happened on both host and partner portals.
- `EmployeeService::create()` sent it once.
- `EmployeeController::store()` sent it again when `is_paid` was not in the request, i.e. for every free-seat employee.

A paid-seat employee got one welcome email at creation, which carried a password while the user was still inactive. Activating the seat sets a new password and sends its own email, so that first password never worked.

**BUG-197:** When a paid seat is activated (seat invoice paid), the staff user got an email with:
- **subject:** "Payment Receipt - …", hard-coded in `PaymentSuccessUserMail`
- **body:** the welcome with the login details (`emails.account_created`)

## Solution
- **One send, and none before payment.** Only `EmployeeService::create()` sends the welcome email (the controller's second send is removed), and only for a free seat (`$user && !$isPaid`). This is the rule `UserService::store()` (the Users screen) already follows.
- **Paid seats.** A paid seat's user gets one email, on activation (`SubscriptionService::handleUserSeatPayment`), with the password that works.
- **Activation subject.** It is now `__('common.account_active_subject')`: "Your account is active - :site". This matches the `user.seat_activated` notification title. The body is unchanged. The payer's own payment receipt is a separate email and is not affected.

## Files changed
- `app/Http/Controllers/Api/V1/FCH/Common/Employee/EmployeeController.php`: second welcome send and the unused `$partnerInfo` removed.
- `app/Services/FCH/Common/Employee/EmployeeService.php`: welcome only for a free seat.
- `app/Mail/Payment/PaymentSuccessUserMail.php`: subject from lang.
- `lang/en/common.php`: `account_active_subject`.
- `tests/Feature/Employee/EmployeeAccountMailTest.php` (new, 3 tests):
  - a free-seat employee gets one welcome email
  - a paid-seat employee gets none before the seat is paid
  - the activation email (exactly one) has the subject "Your account is active - …"

## Testing
- **New tests:** all 3 failed first (2 emails; 1 email; "Payment Receipt - Host Co"), then passed.
- **Suites:** `tests/Feature/Employee`, `tests/Feature/User`, `tests/Integration/Mail`, `tests/Unit/Mail`, `tests/Feature/Payment` and `tests/Feature/Subscription`: 474 passed.
- **Local MySQL through the real `POST /api/v1/common/employees`,** with mail on Laravel's `array` transport so nothing went to Mailtrap, inside a rolled-back transaction:
  - **Before the fix:** 2 welcome emails for one employee.
  - **After the fix, free seat (host):** 1 email, "Welcome to Four Corner Funding".
  - **After the fix, paid seat (partner 39):** no email at creation, user inactive. After the seat invoice is paid: active, and 1 email, "Your account is active - Demo".
  - Nothing was left in the DB.

## Open items
- Editing an employee to add a login (`updateWithUserSync`) still sends one welcome email, which is unchanged and correct.
- Not committed.
