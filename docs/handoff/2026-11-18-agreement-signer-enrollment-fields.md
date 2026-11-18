# Agreement merge field: the signer's own enrollment, as a table

**Date:** 2026-11-18
**Branch:** feature/agreement-builder-modification
**Author:** Awlad Hossain

## Problem
The Agreement Builder's Plan & Fee dynamic values (`{{plan....}}`, `AgreementPlanFields`) quote the Plan & Fee price list, the same for everyone. The agreement needed the amounts set for **that** partner or borrower when they enrolled.

A first version (earlier the same day) offered one field per amount (`{{signer.enrollment.down_payment}}` ...). That doesn't work: a template can't know whether a signer pays once or in installments, so an installment-only amount is wrong for a one-time signer.

## Solution
One field, `{{signer.enrollment_table}}` (`AgreementEnrollmentFields`), works like the commission table: server-built markup filled at signing, and its token alone in a paragraph replaces that paragraph (`AgreementMergeFields::unwrapTable()`, now for both tables). The table (Item | Amount) has the rows of the signer's enrollment type, from their enrollment subscription. That's the latest `subscriptions` row for the partner (partner agreement) or borrower (borrower agreement), never a user seat.

| Row | Value | Partner, installment | Partner, one-time | Borrower, installment | Borrower, one-time |
|---|---|---|---|---|---|
| Enrollment type | Installment / One Time Payment (`installment_type`) | ✓ | ✓ | ✓ | ✓ |
| Total enrollment amount | `payable_amount` | ✓ | ✓ | ✓ | ✓ |
| Down payment | `down_payment` | ✓ | | ✓ | |
| Subscription amount | `total_subscription_amount` | ✓ | | ✓ | |
| Monthly subscription | `subscription_amount_per_month` | ✓ | | ✓ | |
| Installment months | `installment_count` | ✓ | | ✓ | |
| Platform fee | `monthly_platform_fee` | ✓ | ✓ | | |
| Per user seat fee | `per_seat_amount`, else the partner type's Plan & Fee user-seat plan `total_amount` | ✓ | ✓ | | |
| Free seat count | `user_seats`, else the user-seat plan's `total_seat_count` (as `Partner::userSeatLimit()`) | ✓ | ✓ | | |
| Client activation fee | Plan & Fee `borrower_fee` / `fixed_enrollment` + " (it may vary per borrower)" | ✓ | ✓ | | |

- **No enrollment:** "No enrollment found."
- **For the builder:** `merge-fields` returns the field (group "Signer: Enrollment", `html: true`) with `variants`, the rows each type shows. The builder lists them in the group's modal. Its `sample`, shown in the preview, is a captioned example table for **each** type ("Example: Installment", "Example: One Time Payment"). A signer sees only their own.
- **Leftovers from the first version** (`{{signer.enrollment.*}}`) are filled blank, never left as `{{...}}`.

## Files changed
- `app/Services/FCH/Common/ManageAgreement/AgreementEnrollmentFields.php`: new; the table, its variants and examples.
- `app/Services/FCH/Common/ManageAgreement/AgreementMergeFields.php`: fills it (as markup) and unwraps it like the commission table; blanks the first version's fields.
- `app/Http/Controllers/.../AgreementTemplateController.php`: `mergeFields()` lists it.
- `lang/en/agreement.php`: `enrollment.*` (label, group, row names, item/amount, "may vary", none, example caption).

## Also: Plan & Fee group order
The partner agreement's Plan & Fee groups are now listed as Partner enrollment, Borrower enrollment, Client activation fee, User seats (`AgreementPlanFields::GROUPS_BY_AGREEMENT`, which also sets the builder's order).

## Tests
- `tests/Feature/Agreement/AgreementEnrollmentFieldsTest.php` (10): both menus (one field, variants, the two-table example); partner installment and one-time rows; seat subscription ignored; no enrollment; borrower installment and one-time (no partner rows); first-version field blank; mid-sentence fill. All failed first.
- `AgreementPlanFeeFieldsTest`: borrower list check narrowed to `plan.*`; new group-order test (failed first).
- Installment months and the seat rows: the variant, row and preview-example tests were updated first (5 failed), then passed. Checked on local data: partner #55 (installment) shows 10 months, $49.97 per seat and 10 free seats.
- `tests/Feature/Agreement`: 126 passed.

## Open / follow-up
- The Plan & Fee price-list values (`{{plan....}}`) are unchanged. Agreements that used them by mistake still show the price list; switch them to Enrollment details in the builder.
- An agreement saved with a first-version field (`{{signer.enrollment.*}}`) shows it blank: replace it with Enrollment details.
