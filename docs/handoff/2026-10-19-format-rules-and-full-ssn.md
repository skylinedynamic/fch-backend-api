# Format rules on the remaining fields, and the full SSN in place of "last 4"

**Date:** 2026-10-19
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
An audit of every EIN, D-U-N-S, SSN, phone, ZIP and FICO field found them all on the shared `App\Rules` classes. A few fields still took any value:

- **Bank routing number** (`bank_details.*.routing_number`, Settings → Payment Integration → Bank Transfer) was free text up to 255 characters.
- **Credit report save** (`POST /common/borrowers/save-credit-profile`) cast `metrics.fico_score` with `(int)`. So 9999 or -5 was saved to `borrowers.fico_score`, which lender matching reads.
- **Paid seats** (`months` on employee and user create) had no rule. The service runs `(int) months`, so `-3` produced a negative seat invoice. A non-numeric value on employee create was a **500**.
- **Employee login phone**: `phone_number` was copied onto the new login without being validated.
- **SSN "last 4"**:
  - Leads kept `ssn_last_4`: four digits, plain text.
  - Client add/edit still accepted `last_4_ssn` alongside the full `ssn`.
  - The borrower change request offered `last_4_ssn`, but not `ssn`.

## Solution
- **New rule `App\Rules\RoutingNumber`:** exactly nine digits, checked as text so a leading zero is kept. It applies to `bank_details.*.routing_number`, which stays optional. Message: `common.routing_number_invalid`.
- **`metrics.fico_score`:** `nullable` + `FicoScore` (300–850).
- **`months`:** `nullable|integer|min:1` on `StoreEmployeeRequest` and `StoreUserRequest`. `amount` is left alone because the server computes the price itself.
- **Employee `phone_number`:** `nullable` + `UsPhoneNumber`.
- **Full SSN everywhere, encrypted, the way the client profile already did it:**
  - The new trait `App\Models\Concerns\HasEncryptedSsn` (`ssn` accessor/mutator over `ssn_encrypted`, `hasFullSsn()`) is shared by `Borrower` and `Lead`. Borrower's own copies were removed.
  - **Leads:**
    - Migration `2026_10_19_120000_encrypt_ssn_on_leads_table` replaces `ssn_last_4` with `ssn_encrypted`. The last four already on file move across encrypted, and `down()` restores them.
    - The lead requests take `ssn` (`nullable` + `Ssn`, normalised with `Ssn::canonical`).
    - `LeadResource` returns `ssn`.
    - Converting a lead carries `ssn` to the new client.
  - **Client add/edit:** `last_4_ssn` was removed from `StoreBorrowerRequest` and `UpdateBorrowerRequest`; only the full `ssn` is taken.
  - **Borrower change request:**
    - The field is `ssn` (`required` + `Ssn`), and the mapping is `ssn` → "Social Security Number".
    - `BorrowerChangeRequest` encrypts `old_value`/`new_value` for SSN fields in a `saving` hook, so the order attributes are filled in doesn't matter, and decrypts them on read.
    - Rows from before this change still read back.
- `required`/`nullable` stayed exactly as they were on every field.

## Files changed
- `app/Rules/RoutingNumber.php`: new rule.
- `lang/en/common.php`: `routing_number_invalid`.
- `app/Http/Requests/Api/V1/FCH/Common/Setting/StorePartnerPaymentProviderRequest.php`: routing number rule.
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerController.php`: `metrics.fico_score` rule.
- `app/Http/Requests/Api/V1/FCH/Common/Employee/StoreEmployeeRequest.php`: `months`, `phone_number`.
- `app/Http/Requests/Api/V1/FCH/Common/User/StoreUserRequest.php`: `months`.
- `app/Models/Concerns/HasEncryptedSsn.php`: new trait.
- `app/Models/Borrower/Borrower.php`: uses the trait; duplicate methods removed.
- `app/Models/Lead/Lead.php`: uses the trait.
- `database/migrations/2026_10_19_120000_encrypt_ssn_on_leads_table.php`: new migration.
- `app/Http/Requests/Api/V1/FCH/Common/Lead/{StoreLeadRequest,UpdateLeadRequest,UpdateLeadContactInfoRequest}.php`: `ssn` in place of `ssn_last_4`.
- `app/Http/Resources/FCH/Lead/LeadResource.php`: `ssn` in place of `ssn_last_4`.
- `app/Services/FCH/Common/Lead/LeadService.php`: conversion passes `ssn`.
- `database/seeders/LeadSeeder.php`: seeds a full SSN.
- `app/Http/Requests/Api/V1/FCH/Common/Borrower/{StoreBorrowerRequest,UpdateBorrowerRequest}.php`: `last_4_ssn` removed.
- `app/Http/Requests/Api/V1/Borrower/Profile/StoreChangeRequest.php`: `ssn` in place of `last_4_ssn`.
- `app/Services/FCH/Common/ChangeRequest/BorrowerMappingService.php`: `ssn` mapping.
- `app/Models/Borrower/BorrowerChangeRequest.php`: SSN values encrypted at rest.

## Tests
- **Added:**
  - `tests/Unit/Validation/RoutingNumberTest.php` (14)
  - `tests/Feature/Payment/PartnerBankDetailsRoutingNumberTest.php` (3)
  - `tests/Unit/Profile/BorrowerChangeRequestSsnEncryptionTest.php` (4)
  - `tests/Integration/Lead/LeadSsnEncryptedMigrationTest.php` (2)
- **Updated:**
  - `BorrowerCreditProfileApiTest`: FICO out of range is refused; a report without one still saves.
  - `EmployeeApiTest`: login phone, seat months.
  - `UserManagementApiTest`: seat months.
  - `BorrowerRecordsApiTest`: full SSN kept encrypted; last four alone not taken.
  - `BorrowerProfileApiTest`: SSN change request is a full SSN, encrypted, validated.
  - `ChangeRequestReviewApiTest`: an approved SSN change replaces the SSN on file.
  - `LeadAndContactApiTest`: a lead's SSN is full and encrypted, validated, cleared, and carried on conversion.
- **Run:** `php artisan test` gave **3112 passed, 2 skipped, 0 failed**.

## Open / follow-up
- **Run the migration** after deploy, and locally: `php artisan migrate` (`2026_10_19_120000_encrypt_ssn_on_leads_table`). Leads that held only the last four now hold those four digits encrypted; the partner portal shows them as they are until someone enters the full number.
- **Not given a rule on purpose:**
  - Credit-report address `zip`: bureaus send ZIP+4.
  - Self-reported credit score dropdowns: left as they are, by decision.
- **Dead code:** `StoreLeadRequest` and `UpdateLeadRequest` (FCH namespace) are not used by any route. They were updated anyway, so they don't drift.

## Follow-up (same day): one stored form for EINs, masked SSN on the embedded form
- **EINs were stored half one way, half the other** (`11-1111111` vs `534534534`), so screens showed them unformatted.
  - The new `BusinessEin::canonical()` reduces an EIN writing to its nine digits and leaves anything else for the rule to refuse.
  - It now runs in `StoreBorrowerRequest`/`UpdateBorrowerRequest` (`business_ein`), the three lead requests (`business_ein`) and `StorePartnerRequest`/`UpdatePartnerRequest`/`DemoRequestStoreOrUpdateRequest` (`tax_id`).
  - Existing rows are not rewritten; the portals format either form on display.
- **Embedded form (`resources/views/embed-form/index.blade.php`):** the owner and co-borrower SSN fields are now the portals' masked field.
  - It shows `***-**-1234` at rest and dots while typing, with the digits on the eye button (Bootstrap Icons).
  - The number travels in a hidden `.ssn-value` input carrying the `name`, so FormData always sends the real value.
  - `fieldError()` reads that hidden value.
- **Tests:**
  - `BusinessEinTest` (canonical);
  - `BorrowerRecordsApiTest`, `LeadAndContactApiTest`, `PartnerAdminApiTest` (EIN/tax ID kept as digits);
  - `EmbedFormPageFieldsTest` (SSN field markup).
  - The embed SSN behaviour was checked once in a real browser against `/form/{key}`: masked, password while typing, hidden value set, eye toggles. There is no browser-test setup in fch-api to keep that as a spec.
- **Run:** `php artisan test` gave **3117 passed, 2 skipped, 0 failed**.
- Added `MyApplicationsApiTest::an_empty_home_phone_is_not_an_error`. On both co-borrower validation routes, an empty Home Phone is no error, while a partial one is. A partner-portal error under an emptied Home Phone was a leftover of the screen, fixed there.
