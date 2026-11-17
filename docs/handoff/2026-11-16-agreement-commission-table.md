# Partner commission table in the partner agreement

**Date:** 2026-11-16
**Branch:** feature/agreement-builder-modification
**Author:** Awlad Hossain (with Claude Code)

## Problem
The host wants the partner agreement to show each partner's own commission. The Agreement Builder had no merge field for it, so commission could only be written as fixed wording ("5.1 Commission Percentage ...").

## Solution
There is a new merge field, `{{signer.commission_table}}`, offered only in the **partner** agreement.

When a partner opens the agreement to sign (`checkSignNeed`), the field is filled with a table of that partner's tiers:
- columns From (funded deals) / To / Commission;
- the commission reads "$1,500" for a flat tier or "27.5%" for a percentage tier;
- the last tier's "To" reads "and up".

**The signed copy is frozen.** The same fill runs before the signed record is built (`submitSign`), so the signed copy keeps the tiers as they were at signing.

**Other cases:**
- A partner with no tiers gets "No commission is set."
- In the borrower agreement the token is removed.
- The host doesn't sign, so it never sees the field filled.

**Markup and safety.**
- This is the only merge field whose value is markup. `AgreementMergeFields::fill()` writes it unescaped.
- It is built only from numbers and lang strings, with inline styles so the signed PDF matches.
- When the token is alone in its own `<p>` or `<div>`, the table replaces that element, because a `<table>` inside a `<p>` is not valid HTML.

**How the builder lists it.** `POST /common/agreement-templates/merge-fields` adds the field for type `partner` as `{key, label: "Partner's commission tiers", group: "Signer", sample: <example table>, html: true}`. The admin panel uses `html` to show the sample as a table in its preview.

**Shared formatting.** The "$" and "%" formatting moved into `CommissionTierFormatter`. `PartnerService`'s activity-log summary uses it too, so its output is unchanged.

## Files changed
- `app/Services/FCH/Common/ManageAgreement/AgreementCommissionFields.php` (new): the field definition, the sample table, and the signer's tier table.
- `app/Services/FCH/Common/ManageAgreement/AgreementMergeFields.php`:
  - `values()` adds the commission value for the partner agreement;
  - `fill()` writes it unescaped (it is blank where not offered);
  - new `unwrapTable()`.
- `app/Services/FCH/Partner/CommissionTierFormatter.php` (new): `value()` and `maxLabel()`.
- `app/Services/FCH/Partner/PartnerService.php`: `commissionSummary` uses the formatter.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementTemplateController.php`: `mergeFields` includes the commission field.
- `lang/en/agreement.php`: the `commission.*` strings.

## Testing
- New `tests/Feature/Agreement/AgreementCommissionTableTest.php`, 8 tests, red before green. It covers:
  - the field is offered for partner and not for borrower;
  - a partner sees their own tiers as a real, unescaped table that replaces its paragraph;
  - the token inside a sentence still becomes the table;
  - another partner's tiers never show;
  - no tiers shows "No commission is set.";
  - the borrower agreement drops the token;
  - the signed copy keeps the tiers after they change.
- New `tests/Unit/Partner/CommissionTierFormatterTest.php`, 3 tests.
- Agreement, Partner and formatter suites: **300 passed**. The full suite result is in the reply for this change.

## Open items
- A partner who already signed keeps the old tiers in their signed copy. Changing their tiers doesn't ask them to sign again; that would be a separate feature.
- The master template's legal wording in section 5.1 still says "Commission Percentage ... in the applicable Addendum". The host can insert the field there in the builder.
