# Borrower fee is raised for every non-host partner's borrower, whoever enrolls them

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
Rule (confirmed by the product owner): a paid borrower of any partner other than the host (White-Label or Certified) costs that partner the borrower fee, and the host receives it. The borrower's own enrollment and installments go to the borrower's partner.

`EnrollmentService::enrollBorrower()` skipped the fee whenever the host did the enrolling (`$isNonHostPartner = !$isHostCreating && …`). The host does this in two places:
- **Converting a WL/CP partner's lead** (`LeadService` makes the borrower the lead's partner's; `notifyConvertedOnBehalf` tells the partner).
- **Moving a WL/CP partner's free-trial borrower onto a paid plan** (`BorrowerService` update, which calls `enrollBorrower()` again).

In both cases no fee invoice was raised and `partner_payment_for_me` was left as it was. The partner never owed the fee, and its borrower was never held back waiting for it.

## Solution
The fee now follows whose borrower it is: `$isNonHostPartner = $partner ? !$partner->is_host : true`. `$isHostCreating` still decides which welcome email is sent and the borrower invoice's `is_partner_paying`. Those are unchanged.

The existing collection flow applies unchanged:
- the fee is charged from the partner's saved card on the host's Stripe when the borrower makes a first card payment, or when the partner approves the borrower's manual payment;
- otherwise the cron charges it;
- the partner can also pay it by hand. The host approves it, since a partner can no longer approve its own payment to the host.

## Files changed
- `app/Services/FCH/Subscription/EnrollmentService.php`: `enrollBorrower()` raises the fee for every non-host partner's borrower.
- `tests/Integration/Subscription/BorrowerFeeOnEnrollmentTest.php` (new): the host enrolling a WL or a CP partner's borrower raises the fee (unpaid, owed by the partner, 800, borrower held). The partner enrolling its own borrower still raises it. The host's own borrower has none.

## Testing
- The two host cases failed before the fix ("no borrower fee invoice was raised"), then passed. The other two passed before and after.
- `tests/Integration/Subscription`, `tests/Integration/Invoice`, `tests/Feature/{Payment,Borrower,Lead,Billing,Subscription,Website}`: 594 passed.
- Full fch-api suite (with the same day's approval and payment environment fixes): 4294 tests, 0 failures, 2 skipped.

## Open items
- Not committed; the target branch is to be confirmed.
- Existing borrowers the host already converted have no fee invoice. Not backfilled (not requested).
- The admin Convert Lead modal fetches the partner borrower fee (`getPartnerBorrowerFee`). Whether it should tell the host that the partner will be charged was not checked.
