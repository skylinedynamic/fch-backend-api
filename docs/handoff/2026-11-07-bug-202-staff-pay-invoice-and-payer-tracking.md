# BUG-202: partner staff can pay the partner's invoices; who paid, approved or rejected is kept

**Date:** 2026-11-07

## Problem
A partner's staff user (e.g. Admin role) saw Pay Now on the partner's own invoices (e.g. an $800 Borrower Fee). The payment failed every time: `POST /api/v1/payment/process` returned 400 "Unauthorized invoice payment attempt.".

`UnifiedPaymentService::assertMayPay()` allowed only two cases:
- the invoice's own payer, i.e. for a partner invoice the owner login (entity Partner)
- staff, but only for invoices billed to a User or Employee

A staff login is an Employee, so it was refused on every partner invoice. Nothing recorded which user paid, approved or rejected a payment, apart from free-text names in `payments.details`.

## Solution
Decided 07-Nov.

**Permission "Pay Invoice"** (`Invoice::PERMISSION_PAY`):
- Added to `config/permissions.php` under invoices, for partners (`is_host: 0`, `is_certified: 1`).
- `PayInvoicePermissionSeeder` creates it and gives it to every static role (each partner's Admin, the host's Admin and Developer) and to every user holding one, since users carry their own copy of their role's permissions here. Those users get `is_refresh = 1`.
- New partners get it from config through `createPartnerRoles()`. Other roles get it from Roles & Permissions.

**Checked in the panel only.** The panel shows Pay Now only to holders of the permission. The API does not check it (07-Nov decision).

**`assertMayPay()`** now also lets the partner's own staff pay the partner's own invoices (`entity_type` Partner, `entity_id` = the user's `partner_id`). It still refuses:
- a borrower's login, which shares the partner_id
- another partner's invoice

**New columns** (migration `2026_11_07_000001`), all nullable FKs to `users`, null on delete:

| Column | Table | Set when |
|---|---|---|
| `paid_by` | payments | Every checkout attempt: succeeded, processing (manual), waiting for 3-D Secure, or failed (`recordCheckoutFailure`). Set from the checkout's own user, never inferred later. |
| `paid_by` | invoices | The invoice becomes Paid. A card payment takes the payment's `paid_by`. A manual payment gets it on approval: the user who submitted it, not the approver. |
| `approved_by` | payments, invoices | A manual payment is approved. |
| `rejected_by` | payments only | A manual payment is rejected. The invoice just returns to Unpaid. |

**Automatic charges** (borrower-fee auto-charge, `ChargeSubscriptionInvoiceJob`) have no user. `paid_by` stays null even when they run inside another user's request, because `createRecord()` no longer guesses it from `auth()`.

## Files changed
- `database/migrations/2026_11_07_000001_add_payer_and_reviewer_users_to_payments_and_invoices.php` (new).
- `database/seeders/PayInvoicePermissionSeeder.php` (new). Also added to `DatabaseSeeder`.
- `config/permissions.php`: "Pay Invoice".
- `app/Models/Invoice/Invoice.php`: `PERMISSION_PAY`, plus `paid_by` and `approved_by` in fillable.
- `app/Models/Payment/Payment.php`: `createRecord()` passes `paid_by` (default null).
- `app/Services/GlobalPayment/UnifiedPaymentService.php`:
  - `assertMayPay()` lets the partner's staff pay
  - the checkout carries `paid_by` into the payment (completed, 3-D Secure pending, failed) and into the invoice when paid
- `app/Services/FCH/Common/Payment/PaymentService.php`:
  - approve sets `approved_by` on both, and the invoice's `paid_by`
  - reject sets `rejected_by` on the payment
- `tests/Feature/Payment/PayInvoiceTest.php` (new, 11 tests): staff pays the partner's invoice; the API lets staff pay without the permission; a borrower of the partner and another partner's invoice are refused; the owner still pays; card success and decline; auto-charge has no payer; approve and reject record the right users.
- `tests/Feature/Payment/PayInvoicePermissionSeederTest.php` (new, 3 tests): the definition; static roles and their users get it with `is_refresh = 1`; non-static roles do not; running twice changes nothing.
- Admin panel: see `Fch-admin-frontend-react/docs/handoff/2026-11-07-bug-202-pay-now-needs-pay-invoice.md`.

## Testing
- **New tests:** failed first (400s, missing seeder, no `paid_by`), then passed.
  - After the "API does not check the permission" decision, the old "staff without it is refused" test became "the API lets the partner's staff pay", and a borrower-refusal test was added. That new test failed, then passed.
- **Suites:** `tests/Feature/Payment`, `tests/Feature/Invoice`, `tests/Integration`, `tests/Feature/Subscription`, `tests/Feature/Partner`, `tests/Feature/RolePermission`, `tests/Feature/Seeders`, `tests/Feature/CommissionPayout`, `tests/Feature/Billing` and `tests/Unit`: 2349 passed, 1 skipped. Then `tests/Feature/Payment` + `tests/Integration/Payment` again after the decision: 299 passed.
- **Local MySQL:** migration run, and the seeder gave "Pay Invoice" to 34 static roles and 31 users.
- **Live UI as a temporary staff user of partner 41** (Admin role), on the admin panel against the real API:
  - Pay Now showed. Cash → Confirm → `/payment/process` 200, invoice Processing, payment `paid_by=staff`.
  - Host approved through the real endpoint: invoice Paid with `paid_by=staff` and `approved_by=1`, and the payment `approved_by=1`.
  - A second invoice paid by the staff user and rejected: invoice Unpaid with both columns null, and the payment `rejected_by=1`.
  - The same staff user on a role without "Pay Invoice": no Pay Now on an unpaid invoice.
  - All temporary data (user, employee, role, invoices, payments, logs, tokens) was deleted afterwards.

## Open items
- **API not permission-gated:** a staff user without "Pay Invoice" can still pay the partner's invoices through the API. This was the decision; the panel hides the button.
- **Paid seats on the Users and Employees screens:** they open the payment modal straight after creating the seat. That modal is not gated by the permission. The API allows the partner's staff, so it works either way.
- **Partner 39 locally:** its users are logged out by the panel's expired-trial check (ProtectedRoute). That is unrelated local data, so the live check used partner 41.
- **Card-on-file:** a staff user's first card payment can become the partner's saved card if the partner had none (`completeCharge()` saves it on the payer, the partner). Future auto-charges would then use it. Unchanged.
- **Commission payouts** (`CommissionPayoutService`) don't set `paid_by` or `approved_by`.
- **Display:** `paid_by`, `approved_by` and `rejected_by` are not shown in the API resources or the UI yet.
- Not committed.
