# BUG-018: converting a lead onto another partner's client's email no longer says so

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-018 (R2-E2E-07): a partner converted a lead whose email was already a client of **another** partner. The answer was HTTP 500, "A borrower with this email already exists."
- The 500 is noise in error monitoring.
- The message tells the partner that this person is a client elsewhere, which is a cross-tenant leak.

**What still reaches it:**
- Creating a lead with a client's email is already refused ("Email is already taken", on `users`). A probe confirmed this for QA's exact steps.
- But two partners can **both** hold a lead for the same email **before** either converts. Once one converts, the other's convert hits this.

## Solution
`LeadService::convertToBorrower()` now picks the message by whose client the email belongs to:
- **Another partner's client:** `lead.email_cannot_be_used`, "This email cannot be used. Please use a different email." It says nothing about who or where.
- **The partner's own client:** `lead.email_already_your_client`, "This email already belongs to one of your clients."
- No second borrower is created, as before.

**Not changed (product owner's choice):** the HTTP status is still 500. The controller's generic `catch (\Exception)` turns the `InvalidArgumentException` into `serverError`. Making it 422 would be a small change in `LeadController::convert`.

## Files changed
- `app/Services/FCH/Common/Lead/LeadService.php`: the duplicate-email check.
- `lang/en/lead.php`: `email_already_your_client`, `email_cannot_be_used`.

## Testing
- **New:** `tests/Feature/Lead/ConvertLeadEmailTakenTest.php`, 2 tests:
  - another partner's client gets the neutral message, without the word "borrower";
  - the partner's own client gets theirs;
  - only one borrower exists either way.
  - Both failed first.
- **Run:** `tests/Feature/Lead`, 71 passed.

## Open items
- The 500 status, if the product owner wants it to be a 422.
