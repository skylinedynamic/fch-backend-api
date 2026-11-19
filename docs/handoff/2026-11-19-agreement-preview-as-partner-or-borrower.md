# Agreement preview as a partner or borrower

**Date:** 2026-11-19
**Branch:** feature/agreement-builder-modification

## Problem
The Agreement Builder's live preview showed only the owner's real details. Every `{{signer.*}}` field, plan price, commission table and enrollment table showed example values. Nobody could check how the agreement would read for a real partner or client before they signed it. The admin's Partner and Client › Agreement tabs had nothing to show before signing, either.

## Solution
- **`GET /common/agreement-templates/preview-signers?type=partner|borrower&search=`** (new, `AgreementPreviewSigners`). `type=partner` lists partners and `type=borrower` lists clients. Up to 50 rows, newest first, searchable by name, business name or email. **Who sees whom depends on the viewer, not on whose agreement is open:**

  | Viewer | Partners | Clients |
  |---|---|---|
  | Host admin or host staff | every non-host partner's own login | every client of every partner |
  | Non-host partner admin or staff | none | its own clients only |
  | Borrower | refused (common routes) | refused |

  - Partner staff logins are never listed.
  - Each row: `user_id`, `name`, `email`, `business_name`, `partner_name` (clients only), `enrollment_type` (`Installment` / `One Time Payment` from the latest enrollment, the same one the enrollment table uses; `null` with none), `has_sign`.
  - Enrollment types are loaded in one query for the whole page (`AgreementEnrollmentFields::enrollmentTypes()`), not one per row.
  - `partner_id` is still permission-checked as on the other agreement endpoints (a host user with `manage partner configuration`, else 403). It doesn't change the list.
- **`POST /common/agreement-templates/preview-values`** now also takes optional `signer_user_id` + `type` (`AgreementPreviewValuesRequest`; `type` is the agreement being previewed and is required with a signer).
  - With a signer, it returns `AgreementMergeFields::values($signer, $owner, $type)`: the signer's details, plan, commission and enrollment values, exactly what signing fills in.
  - The signer must be of the agreement's kind and listable by the viewer: a partner for the partner agreement, a client for the borrower agreement. Anything else gives 404.
  - **The owner fields follow the signer, not the builder** (`AgreementPreviewSigners::ownerFor()`). A client is previewed with **their own partner's** company name, email, phone and address, even from the host's Settings builder. A partner is previewed with the host's, since the host owns the partner agreement.
  - Without a signer, nothing changes (the builder owner's details).
- **`GET /common/agreement-sign/preview/{userId}`** (new, `AgreementSignController::preview`, `AgreementService::previewFor`).
  - It returns the active template the partner or client would sign, filled with their details: `{template_id, slug, template_html}`.
  - It returns 404 when there is no active template for them, or when the user is the host.
  - It reads `template_html` directly, not through `currentVersion()`, so a preview never writes a version row.
  - Access: the host's team sees anyone; a partner's team sees only its own clients. Borrowers are refused by the common route group (403), and the controller also refuses them.
- New lang key `agreement.no_preview_available`.

## Files changed
- `app/Services/FCH/Common/ManageAgreement/AgreementPreviewSigners.php`: new; partner and client lists and lookup, scoped by viewer.
- `app/Services/FCH/Common/ManageAgreement/AgreementEnrollmentFields.php`: `enrollmentTypes()` (batched); shared `enrollments()` query.
- `app/Services/FCH/Common/ManageAgreement/AgreementService.php`: `previewFor()`.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementTemplateController.php`: `previewSigners()`; `previewValues()` takes a signer.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementSignController.php`: `preview()`.
- `app/Http/Requests/Api/V1/FCH/Common/ManageAgreement/AgreementPreviewSignersRequest.php`, `AgreementPreviewValuesRequest.php`: new.
- `routes/common.php`: the two new GET routes.
- `lang/en/agreement.php`: `no_preview_available`.

## Tests
- `tests/Feature/Agreement/AgreementPreviewAsSignerTest.php` (new, 26 tests) covers:
  - the lists and search;
  - the host's admin and staff see every client (with partner name), also from another partner's Configuration;
  - a non-host partner's admin and staff see only their own clients and no partners;
  - the permission check for `partner_id`;
  - a client previewed with their own partner's details (not the host's), a partner with the host's;
  - each agreement previewed only as its own kind of signer;
  - type validation;
  - enrollment type (latest wins, monthly ignored) and sign status, for both types;
  - signer values, a signer outside the owner refused, `type` required;
  - the tab preview: host to partner, partner to own client, inactive gives 404, another partner's client gives 404, borrower refused.
- `php artisan test tests/Feature/Agreement tests/Unit/Agreement`: **171 passed**.

## Open items
- Each list shows the newest 50; a search narrows it. There is no paging, which matters more now that the host lists every client.
- The admin client profile shows its Agreement tab before signing only to the host (`ClientDetails.tsx`). A white-label partner can call the preview endpoint for its own clients but has no tab to see it from yet.
- Admin frontend: `Fch-admin-frontend-react` handoff `2026-11-19-agreement-preview-as-partner-or-borrower.md`.
