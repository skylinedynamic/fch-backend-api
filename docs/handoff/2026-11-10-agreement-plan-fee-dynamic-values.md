# Agreement dynamic values: Plan & Fee prices

**Date:** 2026-11-10

## Problem
Agreement templates could use signer and company details as dynamic values (`{{signer.name}}`, `{{owner.company_name}}`, `{{today}}`), but no Plan & Fee price. Fees had to be typed into a template by hand and went stale when Plan & Fee changed.

## Solution
- **`AgreementPlanFields`**: turns the **active** Plan & Fee plans into dynamic values. Each value is a fixed price, the same for every signer (decided with Awlad: active plans only; price list, not the signer's own plan).
- **Keys** are built from the plan's group and names, not its id, so a template means the same plan on every server: `plan.<group>[.<partner type>].<enrollment type>.<field>`. Slugs become underscores, e.g. `plan.partner_enrollment.white_label_partner_wl.payment_plan_11_month_plan.total_amount`.

  | Group | Fields |
  | --- | --- |
  | Partner enrollment | `total_amount`, `platform_fee`, `installments` (only when more than 1) |
  | User seats | `total_amount` (monthly seat fee), `free_seats` |
  | Client activation fee | `total_amount` |
  | Borrower enrollment | `total_amount`, `installments` (only when more than 1) |

  Money is written with the plan's currency symbol (`$4,997.00`).
- **Per agreement type**: the partner agreement offers and fills all four groups; the borrower agreement only Borrower Enrollment. A `{{plan....}}` that has no value for that agreement (a partner price in a borrower agreement, a plan switched off since) is filled with nothing, never left as raw braces.
- **`POST /api/v1/common/agreement-templates/merge-fields`** `{ type: partner|borrower }`: the list for the builder, as `{ key, label, group, sample }`, where `sample` is the price signing fills in. 422 for another type.
- `AgreementMergeFields::values()` takes the agreement type (the template slug) and adds the plan values. `fill()` now accepts digits in keys (`11_month`).

## Files changed
- `app/Services/FCH/Common/ManageAgreement/AgreementPlanFields.php`: new.
- `app/Services/FCH/Common/ManageAgreement/AgreementMergeFields.php`: plan values per agreement type; digits in keys; unknown plan keys blank.
- `app/Services/FCH/Common/ManageAgreement/AgreementService.php`: passes the template slug when filling (agreement shown and signed).
- `app/Http/Requests/Api/V1/FCH/Common/ManageAgreement/AgreementMergeFieldsRequest.php`: new.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementTemplateController.php`: `mergeFields()`.
- `routes/common.php`: `agreement-templates/merge-fields`.
- `tests/Feature/Agreement/AgreementPlanFeeFieldsTest.php`: new.

## Testing
- `AgreementPlanFeeFieldsTest`: 6 tests, written first; all 6 failed before the change. They cover the partner list (labels, groups, money format, no instalments for one-off plans, inactive plans left out), the borrower list (Borrower Enrollment only), 422 for a bad type, a partner signing (prices filled, including a key with digits), a borrower signing (partner prices blank), and a plan switched off (blank).
- `tests/Feature/Agreement` + `tests/Feature/Billing`: 186 passed.
- Admin side: `react-fch/docs/handoff/2026-11-10-agreement-builder-plan-fee-values.md`.

## Open items
- A plan's key changes if its group, partner type slug or enrollment type slug changes; templates using the old key then show it blank. Plan & Fee edits only amounts today.
- Already-signed agreements keep the prices they were signed with (the signed record stores the filled text).
