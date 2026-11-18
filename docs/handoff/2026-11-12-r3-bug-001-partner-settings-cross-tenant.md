# R3-BUG-001: a partner could read and change another partner's settings

Date: 2026-11-12

## Problem

The Payment, Email, SMS and Branding settings endpoints accept an optional `partner_id`, so the host can edit a partner's settings from Partner profile › Configuration. The API never checked who sent it. `ResolvesConfigPartner::configPartnerId()` only checked that the partner existed. Only the admin panel checked the permission, by hiding the tab.

QA (Round 3, R3-PP-SEC-01) signed in as partner 89 and, by adding `partner_id=88`:

- read partner 88's payment gateways (with their keys where a gateway is configured, including the host's Stripe secret key)
- switched off partner 88's Cash payment (`POST /api/v1/common/partner-payment-providers/status`)
- read partner 88's mail and SMS configuration and its branding

Every call returned 200. Severity Critical, P1.

## Solution

The check now lives in the shared trait, so all four settings controllers get it from one place:

- With no `partner_id`, or with the caller's own partner id, the request works on the caller's own settings, as before.
- If the request names another partner, the caller must be a **host user holding `manage partner configuration`**. Anyone else gets **403** (`partner.configuration_forbidden`).
- The 403 check runs before the 404 for an unknown partner, so a partner can't use the answer to find out which partner ids exist.

The agreement-template controller already had this check. It now uses the same helper and constant instead of its own copy, and it stays stricter: naming any partner there, even its own, needs the permission.

## Files changed

- `app/Traits/ResolvesConfigPartner.php`: `configPartnerId()` now rejects naming another partner without the right. New `mayManageOtherPartners()` helper. Doc comment updated.
- `app/Models/Partner/Partner.php`: new `PERMISSION_MANAGE_CONFIGURATION` constant, replacing the string literal.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementTemplateController.php`: uses `mayManageOtherPartners()` and the constant. No change in behaviour.
- `tests/Feature/Partner/PartnerConfigurationOnBehalfTest.php`: the host test user now holds the permission. New cross-partner tests. Header comment updated, since the old one said the API deliberately didn't check.

## Testing

- New tests (written first; all 3 refusal tests failed with 200 instead of 403 before the fix):
  - `a_partner_cannot_read_or_change_another_partners_settings`
  - `a_non_host_partner_cannot_name_another_partner_even_with_the_permission`
  - `a_host_user_without_the_permission_cannot_name_another_partner`
  - `a_partner_may_name_its_own_partner`
- Each refusal test calls every endpoint for Payment (list, show, status), Email (list, save, delete), SMS (read, save) and Branding (read, save) with the other partner's id. It checks that every call returns 403 and that the other partner's gateway, mailbox, SMS and branding are unchanged.
- `PartnerConfigurationOnBehalfTest`: 12 tests passed.
- `tests/Feature/{Partner,Agreement,MailConfiguration,Payment,Setting}`: 542 tests passed.
- Full suite: 5,485 tests passed, 2 skipped.

## Open items

- **Secrets in responses:** where a gateway is configured, the settings endpoints still return its keys in full to whoever may read them (the partner itself, or the host). Masking them, for example showing only the last 4 characters, would limit the damage of any future leak. That's a separate change.
- **Admin panel:** no change is needed. The Configuration tab is already gated on the same permission, so a host user who sees it gets 200. If a host role loses the permission, its Configuration tab calls now get 403 instead of working.
- **Staging retest:** repeat the QA steps as partner 89 against partner 88. Expect 403 on every call, and partner 88's Cash payment should stay on.
