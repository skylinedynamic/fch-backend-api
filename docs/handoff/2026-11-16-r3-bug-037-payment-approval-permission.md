# R3-BUG-037: staff without "can approve payment" approve a client payment through the API

Date: 2026-11-16

## Problem

QA (R3-PP-PAY-12-R1, R3-E2E-25-R2) set up the following:
1. Certified partner 91's owner created a role that can view leads, clients, payments and invoices, but cannot approve payments.
2. The owner created staff user 396 with that role.
3. Client 222 paid its enrollment in Cash, so payment 402 was Processing.

The staff user called `POST /api/v1/common/payments/402/approve` and got 200 "Payment approved successfully and marked as Paid." The client's enrollment became paid.

Cause: `ApprovePayment::authorize()` and `RejectPayment::authorize()` returned `true`, and the route had no permission middleware. `can approve payment` (`App\Enums\Permission::ApprovePayment`) was only checked by the admin portal hiding the button.

The defect log closed this under dev decision D1 ("role permissions enforced in the UI only"). It was fixed anyway at the user's request, because it changes money and subscription state.

## Solution

Both requests now authorize with `$user->checkPermissionTo('can approve payment', 'api')`. That is the same permission list (direct and through roles) that the portal shows the Approve and Reject buttons by. A user without it gets 403 before anything changes.

There is no exception for partner owners, because the portal has none either. `checkPermissionTo` returns false rather than throwing if the permission row is missing.

The ownership rules in `PaymentService` are unchanged, for example a Certified partner approving the fee it owes the host.

## Files changed

- `app/Http/Requests/Api/V1/FCH/Common/Payment/ApprovePayment.php`: `authorize()` checks the permission.
- `app/Http/Requests/Api/V1/FCH/Common/Payment/RejectPayment.php`: same check.
- `tests/Feature/Payment/PartnerInvoicesAndPaymentsTest.php`:
  - New tests:
    - staff without the permission cannot approve or reject (payment and invoice stay Processing)
    - a partner owner without it cannot approve
    - staff with it can approve
  - The setUp admins now hold the permission, as real admin roles do.
  - The borrower test now expects 403 instead of 422: the request is refused earlier.
  - The Certified "fee it owes the host" test gives the admin the permission, so it still tests the ownership rule.
- `tests/Feature/Payment/PayInvoiceTest.php`: the host admin holds the permission.

## Testing

- **Seen failing first:** staff and owner without the permission both got 200.
- **After the fix:**
  - `tests/Feature/Payment`: 199 passed
  - `tests/Integration/Payment`: 115 passed
  - `BorrowerCommonRouteAccessTest`: 48 passed
- **Full suite:** see the PR / session notes.

## Open items

- **Defect log:** R3-BUG-037 is "Closed - D1". Its status, and whether D1 still applies to money-changing endpoints, is for Craig and the team to record.
- **Deployment:** every environment needs the `can approve payment` permission assigned to the roles that should approve (`UpdatePaymentInvoicePermissionSeeder` grants it to Admin/Developer roles). Without it, the API now refuses those users, as the portal already hid their button.
- **Other D1 gaps:** other permission-only-in-UI endpoints were not changed here.
