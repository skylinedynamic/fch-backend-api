# Users — a bought seat's user stays inactive until the seat is paid (BUG-123)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-123** (PP-CERT-10, found 21 Oct on stage): a Certified partner at its seat limit bought a seat ($49, 1 month), added the 4th user and paid with Cash. With the invoice and the payment still **Processing**, the user was already **Active**. The email typed in the Add User form was also replaced by the employee record's.

**The API was already right:**
- `UserService::create()` makes a paid seat's user (`is_paid`) **inactive**, with the email as sent.
- `SubscriptionService::handleSuccessfulPayment()` skips a Processing invoice.
- `handleUserSeatPayment()` activates the user (with a new password mail) only when the invoice is paid.

The admin's Add User page sends `is_paid` and `months` with the typed email, and `origin/stage` has that code now. QA's report (21 Oct) has not been retested since, and nothing on the current code reproduces it.

## Solution
No code change. A test pins the whole flow, so it cannot regress.

## Files changed
- `tests/Feature/User/PaidSeatUserActivationTest.php`: new, 3 tests.

## Tests
- **New** (Certified partner at its 3 free seats, user seat plan $49):
  - the bought seat's user starts inactive with the typed email (not the employee's) and an unpaid User Seat invoice;
  - the user stays inactive while the invoice is Processing;
  - the user is active once it is Paid, and the seat has an end date.
- 3 passed. The behaviour already existed; these pin it.

## Open items
- **QA sheet:** BUG-123 → Ready for Retest on the current stage. When retesting, also check that a *Paid* cash approval activates the user.
