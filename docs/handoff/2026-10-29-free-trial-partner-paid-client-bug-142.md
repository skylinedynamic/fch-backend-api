# A partner on a free trial can only add Limited Free Plan clients (BUG-142)

2026-10-29

## Problem
A free-trial Certified partner could create a paid client through Clients › Add Client.
- With "Activate Limited Free Plan" off and Enrollment Type set to "One Time Payment", Save created the client (OnBoarding) with a $2,497 Borrower Invoice and an $800 Partner Invoice.
- No "Paid Version Only" warning appeared.
- Leads › Convert to Borrower on the same partner offers only the Limited Free Plan.

**Cause, on the API side.** Nothing checked it. `BorrowerService::store()` is where Add Client, Convert Lead and the embedded-form conversion all create the client, and it never looked at whether the acting partner was on a free trial. Convert Lead held it back on screen only. (For the screen side see the react-fch handoff of the same date.)

## Solution
- **`BorrowerService::ensureMayAddPaidClient()`:** a non-host partner with `partners.is_free_trail` set may not add a paid client. It throws a `ValidationException` on `is_free_trail` with `borrower.paid_version_only`: "Paid clients are only available in the Paid Version. Upgrade your plan to add a paid client, or add this client on the Limited Free Plan."
  - **On create:** it runs in `store()` before anything is written, for any client that is not on the Limited Free Plan. That covers Add Client, Convert Lead and the embedded form.
  - **On update:** it runs in `update()` when a Limited Free Plan client is taken onto a paid plan.
  - **The host is never held back.**
- **`LeadController::convert()`:** now answers a `ValidationException` as a 422. It used to turn every exception into a 500.
  - The Borrower controller and the embedded-form controller already answered 422.

## Files changed
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: `ensureMayAddPaidClient()`, called from `store()` and `update()`.
- `app/Http/Controllers/Api/V1/FCH/Common/Lead/LeadController.php`: a 422 for validation errors on convert.
- `lang/en/borrower.php`: `paid_version_only`.
- `tests/Feature/Borrower/FreeTrialPartnerPaidClientTest.php` (new).

## Testing
`tests/Feature/Borrower/FreeTrialPartnerPaidClientTest.php` (5 tests):
- **A trial partner's paid client is refused:** a 422 with the message, and no borrower or invoice created. Red first: it answered 201.
- **A trial partner's Limited Free Plan client is still created.**
- **A trial partner cannot move a free client onto a paid plan:** a 422, and the client stays free. Red first: it answered 200.
- **A trial partner's paid lead conversion is refused:** a 422, and no borrower or invoice created. Red first: it answered 500.
- **A partner not on a trial is not held back.**

`tests/Feature/Borrower`, `tests/Feature/Lead` and `tests/Feature/EmbedForm`: 450 passed. Full suite: see below.

## Open items
- Clients a trial partner already added on a paid plan (e.g. QA's client 190) are left as they are; their invoices exist. Cleaning them up is a data decision.
