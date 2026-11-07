# Excuse one partner or client from signing the agreement

2026-11-05

## Problem
Agreement signing could only be turned on or off for groups:
- **globally**, with the host's "Agreement Signing Feature" switch;
- **for all partners**, with the host's partner template;
- **for all of one partner's clients**, with that partner's borrower template.

The host had no way to excuse one specific partner or one specific client while signing stayed on for everyone else.

## Solution
- **New column.** `partners.agreement_required` and `borrowers.agreement_required` are booleans, default `true`, so nothing changes for anyone until the host excuses someone.
- **Sign check.** `AgreementService::checkSignNeed()` answers `needs_sign: false` (message `agreement.not_required`) when the signer's own record has `agreement_required === false`. Only an explicit `false` excuses. The global switch and the templates' own on/off still apply on top.
- **New endpoint.** `POST /api/v1/common/agreement-sign/required/{userId}` with `{ required: bool }`. Rules:
  - The **host's team only** (admins and staff), the same rule as viewing or cancelling someone else's agreement. A non-host partner or their staff gets 404; a borrower is stopped by the common routes' guard (403).
  - It takes the target's **user id** and updates the record behind it: the partner for a partner login, the client for a borrower login.
  - It sets `is_refresh` on that user so their portal re-reads it.
  - An unknown user gets 404, and a missing or non-boolean `required` gets 422.
- **Resources.** `agreement_required` is now in `PartnerDetailsResource`, `BorrowerDetailsResource`, `BorrowerProfileResource` and `BorrowerPersonalDetailsResource`.
- **No change to partner or borrower updates.** Both use `validated()` input, so the new column cannot be changed through them.

- **Locked by Settings › Agreement.**
  - The host can change the switch only while signing is on in Settings › Agreement **and** the agreement this person would sign is active: the host's partner agreement for a partner, the client's own partner's borrower agreement for a client.
  - New `AgreementService::requirementStatus()` and `GET /common/agreement-sign/required/{userId}` (host team only) return `agreement_required`, `signing_enabled`, `template_active`, `can_change` and `locked_reason`.
  - While locked, `POST` answers 422 with `locked_reason` and changes nothing.
  - New `AgreementTemplate::SLUG_PARTNER` / `SLUG_BORROWER` constants.
  - New lang keys `required_locked_globally`, `required_locked_template_partner`, `required_locked_template_borrower`.

## Files changed
- `database/migrations/2026_11_05_000001_add_agreement_required_to_partners_and_borrowers.php`: new column on both tables.
- `app/Models/Partner/Partner.php`: fillable and boolean cast.
- `app/Models/Borrower/Borrower.php`: boolean cast.
- `app/Services/FCH/Common/ManageAgreement/AgreementService.php`: the excuse check in `checkSignNeed()`; new `setRequired()`.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementSignController.php`: `setRequired()`; the host-team check pulled out into `isHostTeam()`, shared with `validateAccess()`.
- `routes/common.php`: the `agreement-sign/required/{userId}` route.
- `lang/en/agreement.php`: `not_required`, `required_updated_successfully`, `required_update_failed`, `signer_not_found`.
- The four resources above: `agreement_required`.

## Testing
- New `tests/Feature/Agreement/AgreementRequiredTest.php`, 15 tests:
  - everyone is required by default;
  - an excused partner is not asked to sign while other partners are;
  - an excused client is not asked while the same partner's other clients are;
  - the host can require it again;
  - switching it on or off sets `users.is_refresh` for that partner's or client's user only, not the host's;
  - the global switch and the template still apply;
  - only the host's team can change it;
  - validation (422) and unknown user (404);
  - the status endpoint while everything is on, while signing is off, while the partner agreement is off, and while a client's partner has their borrower agreement off (POST is 422 and nothing changes); host team only;
  - the details endpoints carry `agreement_required`.
- `tests/Feature/Agreement`: 81 passed. Full suite: 4332 passed, 2 skipped.

## Open items
- **Migration.** It must run on deploy (default `true`, so it is safe on existing rows).
- **Branch base.** The branch `feature/agreement-required` was cut from `release`, not `stage`. Merge to `stage` for QA first.
- **No signer-side change.** Excusing someone who has already signed does not remove their signed agreement. It only stops future prompts, for example after a Cancel / Re-sign.
