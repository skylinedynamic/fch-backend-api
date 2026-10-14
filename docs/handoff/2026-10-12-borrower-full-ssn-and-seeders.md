# Borrower profile: full SSN instead of last 4, and a seedable fresh database

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain

## Problem
1. **The borrower profile erased the full SSN (F67).** `borrowers.ssn_encrypted` holds the full 9-digit SSN (credit profile, funding application). The borrower portal's Personal Info screen only collected "Last 4 SSN" (`last_4_ssn`, required on every save), and `Borrower::setLast4SsnAttribute()` encrypted those 4 digits into the same column. Saving any profile change therefore replaced the full SSN with its last four. The admin borrower form and last-4 change requests used the same mutator.
2. **`DatabaseSeeder` failed on a fresh database (F68, was O2).** Seeders had drifted from the migrations (dropped columns, a lazy-load, MySQL-only SQL), and one migration left a stale foreign key on SQLite. New developers and CI could not seed.

## Solution
**SSN**
- `POST /borrower/profile/personal-info` takes the full `ssn` (validated by the existing `App\Rules\Ssn`: `XXX-XX-XXXX` or 9 digits, SSA exclusions). `last_4_ssn` is no longer accepted there.
- `GET /borrower/profile/personal-info` never returns the number: `ssn_masked` (`***-**-6789`) and `ssn_on_file` (full 9 digits on file). `last_4_ssn` was removed from this response. **API contract change** - the borrower portal is updated in the same change.
- A blank `ssn` keeps the SSN on file. `ssn` is required only while no full SSN is on file (none, or just the last four from older rows).
- `setLast4SsnAttribute()` is a no-op when the digits equal the last four on file, so the admin form and last-4 change requests no longer erase a full SSN.
- SSN was **not** added to the change-request flow. Change requests store `old_value`/`new_value` in plain text and email the new value (D12), which would leak the full number.

**Seeders**
- `BorrowerSeeder` writes `ssn_encrypted` (full SSN, `Crypt::encryptString`), because its bulk insert bypasses the model.
- `SubscriptionPlanSeeder` matches the current columns (`group`, `total_installment_count_upto`), using the values `ModifySubscriptionPlansSeeder` assigns.
- `DemoSubscriberSeeder` finds plans by group and uses `total_amount`.
- `UpdateDomainConfigurationPermissionSeeder` eager-loads `permissions`.
- `CreditBuilderVendorSeeder`, `TierSeeder`, `CapitalAccessSeeder` and `DeletePartnersAndBorrowersSeeder` use `Schema::disable/enableForeignKeyConstraints()`.
- Migration `2026_07_09_072926_…` drops the old `funding_type_id` FK on SQLite only. The MySQL path is unchanged, and the migration is already applied in production, so nothing re-runs there.

## Files changed
- `app/Http/Requests/Api/V1/Borrower/Profile/UpdatePersonalProfileRequest.php` — `ssn` rule (required only without a full SSN on file)
- `app/Services/Borrower/Profile/ProfileService.php` — writes `ssn` only when sent
- `app/Http/Resources/Borrower/Profile/PersonalProfileResource.php` — `ssn_masked`, `ssn_on_file`
- `app/Models/Borrower/Borrower.php` — `hasFullSsn()`; `last_4_ssn` mutator keeps a matching full SSN
- `lang/en/borrower_validation.php` — `ssn_required`
- `database/seeders/{Borrower,SubscriptionPlan,DemoSubscriber,UpdateDomainConfigurationPermission,CreditBuilderVendor,Tier,CapitalAccess,DeletePartnersAndBorrowers}Seeder.php`
- `database/migrations/2026_07_09_072926_change_funding_type_id_foreign_key_on_funding_sequencings_table.php` — SQLite-only FK drop
- `docs/test-findings.md` — F67, F68; O2 closed

## Tests
- Added: `tests/Feature/Profile/BorrowerProfileSsnTest.php` (19) — masked read, full save, blank keeps, legacy last-4, required, 9 malformed formats. All failed first.
- Added: `tests/Unit/Borrower/BorrowerSsnAttributesTest.php` (5) — mutator keeps a full SSN. Confirmed red against the old mutator.
- Added: `tests/Feature/General/DatabaseSeederTest.php` (2) — full seed on fresh SQLite, with storage, public path, disks, HTTP and mail all isolated. Failed first at each seeder in turn.
- Updated: `tests/Feature/Profile/BorrowerMiddleNameTest.php` — payload sends `ssn` instead of `last_4_ssn`.
- Suites run: full `php artisan test` — 2161 passed, 42 skipped, 18 failed. The 18 are the pre-existing `Integration/PreQualification` failures (U1/O3, `runStructuredAgent()` missing), unrelated.

## Open / follow-up
- **Admin panel (`react-fch`)** still shows and edits `last_4_ssn` (`BorrowerResource` etc. unchanged). It now can't erase a full SSN, but moving it to the full SSN is a separate change.
- **SSN change requests** would need encrypted storage and masked emails/resources (see D12) before SSN can be a locked field.
- **Production data:** borrowers with only 4 digits on file are asked for the full SSN on their next profile save. Count them before release.
- **Unused helper:** `formatLast4SSN` in the borrower portal is no longer used by any screen (its unit tests remain).

## Follow-up (same day): profile SSN as the credit profile default
- `POST /borrower/profile/credit-profile-request` now treats `ssn` as **optional when the borrower has a full SSN on file**. If it's left out, the request is stored with the profile's SSN. A sent `ssn` still wins and is normalised to 9 digits. It's still required (422) with no SSN or only the last 4 on file. Files: `StoreCreditProfileRequestRequest.php` (`requiredIf`, `creditProfileAttributes()` fills the SSN in).
- Tests: 3 added to `tests/Feature/CreditProfile/StoreCreditProfileRequestTest.php`. The main one failed first. Ran Profile, CreditProfile, CreditAuthorization and Unit/Borrower: 107 passed.
- Open: `credit_profile_requests.ssn` is stored **in plain text** (no cast), and `GET /credit-profile-request` returns it in full. This was already the case before this change, but it's worth encrypting/masking like `borrowers.ssn_encrypted`.

## Follow-up 2 (same day): reveal endpoint
- New `GET /api/v1/borrower/profile/personal-info/ssn` (`PersonalInfoController::revealSsn`) returns `{ ssn }`, the signed-in borrower's own full SSN or `null`, with `Cache-Control: no-store, private`. It's for the eye button on the SSN field. The profile read itself still only carries `ssn_masked`.
- Tests: 4 added to `BorrowerProfileSsnTest` (own SSN, none on file, never another borrower's, needs auth). They failed first. Profile, CreditProfile, CreditAuthorization and Unit/Borrower: 111 passed.
- Consider: audit-logging each reveal (who and when) for GLBA evidence. Not done here.

## Follow-up 3 (same day): final state, SSN in the profile read (supersedes the masking and reveal notes above)
- **Decision (product):** no reveal endpoint. `GET /borrower/profile/personal-info` returns `ssn`, the borrower's own SSN digits (9, or the last 4 on old rows, or `null`). The portal masks it and shows it on the eye button. The response carries `Cache-Control: no-store, private`. The `GET /personal-info/ssn` route and `revealSsn()` were removed. `ssn_masked` and `ssn_on_file` are gone.
- **Unchanged:** `POST personal-info` (full `ssn`, blank keeps the one on file, required without a full SSN on file) and `POST credit-profile-request` (missing `ssn` falls back to the profile's SSN).
- **Tests:** the read tests in `BorrowerProfileSsnTest` were rewritten (full SSN, last 4 only, none, no-store) and the reveal tests removed. They failed first. Profile, CreditProfile, CreditAuthorization, Unit/Borrower and DatabaseSeeder: 110 passed.
- **Trade-off to note:** every profile read now carries the full SSN, rather than only on a deliberate click.
