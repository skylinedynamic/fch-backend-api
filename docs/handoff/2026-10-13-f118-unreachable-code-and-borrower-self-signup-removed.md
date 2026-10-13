# Unreachable code and borrower self sign-up removed (F118, was O53)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F118 (was O53)

## Problem
O53 listed code that was never executed. Checking each entry showed three cases:
1. **Truly unreachable.** No route, caller or test referred to these:
   - `BusinessVerificationService`, plus `OpenCorporatesService`, which only it used;
   - `UserPermissionService`;
   - `Admin\Configuration\AppConfigurationService`, superseded by `Common\Setting\AppConfigurationService`;
   - `AccountCreatedNotification`;
   - `Borrower\Resource\ResourceService`, superseded by `Admin\Resource\ResourceService`;
   - `PartnerAssistedApplicationController` and its `Service`, which had no route.
2. **Not dead.** `Web\DashboardController` was listed by mistake. It is the server's ops dashboard (`/dashboard` in `routes/web.php`: logs, scheduler, health, charts, docs, backups).
3. **A switched-off feature: borrower self sign-up.** The API route `POST /borrower/register` was commented out. The borrower portal's Sign Up route was commented out too, but its page, service, hook and type remained. The code behind it was `AuthController::register`, `BorrowerRegistrationRequest`, `BorrowerAuthService` and `BorrowerSignupId`.

## Solution
**Decisions (user):**
- Remove what is unused.
- Borrowers will not sign up themselves.

**Removed from fch-api**
- `app/Services/Global/Verification/BusinessVerificationService.php`
- `app/Services/Global/OpenCorporates/OpenCorporatesService.php`
- the `opencorporates` entry in `config/services.php`
- `app/Services/FCH/Common/RolePermission/UserPermissionService.php`
- `app/Services/FCH/Admin/Configuration/AppConfigurationService.php`
- `app/Services/Global/Notification/Types/AccountCreatedNotification.php`
- `app/Services/Borrower/Resource/ResourceService.php`
- `app/Http/Controllers/Api/V1/FCH/Admin/FundingApplication/PartnerAssistedApplicationController.php`
- `app/Services/FCH/Admin/FundingApplication/PartnerAssistedApplicationService.php`
- **Self sign-up:**
  - `AuthController::register()` and its now-unused imports;
  - the commented route line in `routes/borrower.php`;
  - `BorrowerRegistrationRequest`, `BorrowerAuthService` and `BorrowerSignupId`;
  - `tests/Integration/Support/BorrowerSelfRegistrationTest.php`. It was added in F105 for the sign-up `partner_id` fix and goes with the feature.
  - `database/seeders/SignupIdSeeder.php` and its call in `DatabaseSeeder`. It only filled `signup_ids` for sign-up. The first full run caught it: three `DatabaseSeederTest` failures with `BorrowerSignupId` not found.
- Six folders left empty by these removals.

**Removed from borrower-fch**
- `src/pages/singin/SignUp.jsx`
- `registerBorrower()` from `src/modules/auth/services.ts`
- `useRegisterBorrower()` from `queries.ts`
- `BorrowerRegistrationPayload` from `types.ts`
- the two commented Sign Up lines in `src/App.jsx`
- a commented `isSignup` line in `src/components/Layout/Auth.jsx`

**Kept**
- **`Web\DashboardController`:** it is live.
- **The `signup_ids` table:** dropping it is a destructive data change, so it has no migration. The table is now unused.
- **`borrowers.signup_id`:** it is a borrower attribute shown in the admin resources, so it stays.

## Files changed
The files listed above, plus:
- `tests/Feature/General/DeadRoutesTest.php`
- borrower-fch `tests/modules/auth/services.test.ts`

## Tests
- fch-api `Feature/General/DeadRoutesTest`, written to fail first and then passing:
  - `the_unreachable_services_and_borrower_self_signup_are_gone`: every class above is gone, `AuthController::register` is gone and `services.opencorporates` is gone. The live twins and `AuthController::login` remain.
  - `POST /api/v1/borrower/register` is not registered.
- `the_ops_dashboard_is_live_and_stays` guards against removing it in future. It passed before and after.
- borrower-fch `tests/modules/auth/services.test.ts` checks that there is no `registerBorrower` or `useRegisterBorrower` and no `SignUp.jsx`, while `SignIn.jsx` stays. It failed first, then passed.
- Suites run:
  - borrower-fch: `npm test` 606 passed; `npm run typecheck` clean.
  - fch-api: full suite 2304 passed, 42 skipped, 0 failed. Real `storage/app` was unchanged.

## Open / follow-up
- Run `composer dump-autoload` after pulling.
- The `signup_ids` table is unused. Drop it with a migration if wanted, after checking that nothing outside the app reads it.
