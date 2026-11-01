# BUG-071: a business email may match another account's email (by design)

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-071 (R2-OP-CHG-04): a client filed a Business Email change request with another client's email, and the host approved it. QA expected it to be refused, because "mails sent to the business email could reach another client".

## Decision
**By design, not a defect (product owner).**
- **No mail goes to the business email.**
  - Borrower mail goes to the sign-in email.
  - The business email is only a fallback where the personal email is blank (`InvoicePartyResolver`, `Reminder`). A client always has a sign-in email, so the fallback is not used in practice.
  - Otherwise it is shown on the profile and printed on the signed funding application (`FundingApplicationDocumentService`).
- **Two clients of one company may share an office address**, so a uniqueness rule would block legitimate data.
- **Only the sign-in email must be unique.** That rule exists, on `email` in `StoreChangeRequest`.

## Solution
No behaviour change. The decision is recorded so it is not "fixed" later:
- A BY DESIGN (BUG-071) comment on the `business_email` rule in `app/Http/Requests/Api/V1/Borrower/Profile/StoreChangeRequest.php`.
- A test that pins the behaviour.

## Files changed
- `app/Http/Requests/Api/V1/Borrower/Profile/StoreChangeRequest.php`: the comment.
- `tests/Feature/Borrower/BorrowerProfileApiTest.php`: the new test.

## Testing
- **New:** `a_business_email_may_be_another_accounts_email_by_design`, a change request with another client's email is accepted (200). It is a characterization test, so it passed on its first run.
- **Run:** `BorrowerProfileApiTest`, 15 passed.

## Open items
- **QA sheet:** set BUG-071 to *Not a bug / By design* (comment below).
