# R3-BUG-027: a paid client or partner can't be moved back to the free plan or trial through the API

Date: 2026-11-15

## Problem

1. QA created client 216 on One Time Payment $2,497 (INV-20261111001, Unpaid).
2. QA moved the client to the Limited Free Plan, then back to paid, which raised INV-20261111002.
3. The client was left with two Unpaid $2,497 enrollment invoices.
4. After 355 was paid, 354 stayed Unpaid and could never be paid: "Unauthorized invoice payment attempt." It belongs to the replaced subscription.

What happened:
- Paid → free only flipped `is_free_trail` and left the old subscription and its invoice open.
- Free → paid then enrolled the client again, with a new subscription and invoice.

The product doesn't offer paid → free:
- The client edit form shows the free-plan section only while the client is already on it (`ClientInfoSection.tsx`, `canManageFreeTrial`).
- The partner edit form does the same for the trial (`PartnerInformationSection.jsx`).

QA did step 2 through the API. No real data is in this state (confirmed by the user, 2026-11-15).

## Solution

**Both APIs now follow the forms: free → paid only.**
- **Client** (`POST common/borrowers/{id}`): `is_free_trail: true` on a client that is on a paid plan gets 422 "A client on a paid plan cannot be moved to the Limited Free Plan." (`borrower_validation.paid_to_free_not_allowed`).
- **Partner** (`PUT admin/partners/{id}`): `is_free_trail: true` on a paid partner gets 422 "A partner on a paid plan cannot be put back on the free trial." (`partner.validation.paid_to_trial_not_allowed`).

**Unchanged:**
- free/trial → paid (still asks for the enrollment)
- a free client or trial partner saved as such
- a paid one saved as paid
- edits without the flag

With paid → free closed, the second enrollment invoice can't be raised.

## Files changed

- `app/Http/Requests/Api/V1/FCH/Common/Borrower/UpdateBorrowerRequest.php`: refuses paid → free.
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdatePartnerRequest.php`: refuses paid → trial.
- `lang/en/borrower_validation.php`, `lang/en/partner.php`: the two messages.
- `tests/Feature/Borrower/ClientPaidToFreeBlockedTest.php` (new, 4): paid → free refused, paid saved as paid, free saved as free, free → paid still asks for its enrollment.
- `tests/Feature/Partner/PartnerPaidToTrialBlockedTest.php` (new, 4): the same for a partner.
- `tests/Feature/Partner/PartnerAdminApiTest.php`: the edit tests sent the add-partner payload (`is_free_trail: true`) to a paid partner, so they were putting it on the trial unintentionally. They now send `editPartnerPayload()` (paid).

## Testing

- **Seen failing first:** the paid → free / paid → trial test on each side (1 of 4 each). The other 3 pin edits that stay.
- **`tests/Feature/Partner`:** 136 passed.
- **`tests/Feature/Borrower`, `General`, `Lead`:** passed.
- **Full suite:** see below.

## Open items

- **Stage data:** QA client 216's INV-20261111001 (id 354) stays Unpaid. It is QA data only; cancel it by hand if wanted.
