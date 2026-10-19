# SCRUM-182: borrower full SSN, brought onto a release-based branch

**Date:** 2026-10-18 · **Branch:** `bugfix/scrum-182` (from `release`, uncommitted)

## Why
The borrower profile's full-SSN work (`2026-10-12-borrower-full-ssn-and-seeders.md`) and the encryption of `credit_profile_requests.ssn` (`2026-10-12-f102-ssns-at-rest.md`) exist only on `stage`, inside large mixed commits that can't be cherry-picked. This branch ships them from `release`.

## How the merge stays clean
Every change below is **byte-identical to `stage`**: the same hunks, copied from `git diff origin/release origin/stage`. Git merges identical changes on both sides without conflict.
- A trial `git merge-tree` of this work into `origin/stage` gives the same 7 conflicting files as before it. They come from this branch's earlier commit and the release/stage drift, and none is new.
- Into `origin/release` it gives 0.

Nothing that exists only on `stage` was taken: Borrower's funding-engine cache and `scopeVisibleTo`, the embed-form and PDF SSN work, and the seeders.

## Changes (all from `stage`)
- `POST /borrower/profile/personal-info`:
  - takes the full `ssn` instead of `last_4_ssn`;
  - a blank `ssn` keeps the one on file;
  - `ssn` is required only while no full SSN is on file.
- `GET /borrower/profile/personal-info`:
  - returns `ssn` (9 digits, or the last 4 on older rows, or `null`) instead of `last_4_ssn`;
  - the response is `Cache-Control: no-store, private`.
- `POST /borrower/profile/credit-profile-request`: a missing `ssn` falls back to the full SSN on the profile.
- `Borrower::hasFullSsn()`.
- `setLast4SsnAttribute()` is a no-op when the same last four digits are written again. The admin borrower form, last-4 change requests and lead conversion therefore no longer replace a full SSN with its last four.
- `CreditProfileRequest.ssn` is cast `encrypted`. The new migration `2026_10_12_000000_encrypt_credit_profile_requests_ssn` widens the column and encrypts existing rows; it can be re-run.
- `lang/en/borrower_validation.php`: `ssn_required`.

**API contract change:** deploy together with the borrower portal's `bugfix/scrum-182`. The admin portal (`react-fch`) needs no change. It reads `last_4_ssn` from admin resources, which are unchanged, and the decrypted `ssn` from the credit profile request resources.

## Tests (from `stage`; red before the code, green after)
- `tests/Feature/Profile/BorrowerProfileSsnTest.php`
- `tests/Unit/Borrower/BorrowerSsnAttributesTest.php`
- `tests/Unit/Borrower/BorrowerLast4SsnKeepsFullSsnTest.php`
- `tests/Feature/CreditProfile/StoreCreditProfileRequestTest.php`: profile default and encryption tests, merged beside this branch's one-per-borrower tests.
- `tests/Feature/Profile/BorrowerMiddleNameTest.php`: the payload sends `ssn`.

Before the code: 31 failed. After: Profile, Unit/Borrower, CreditProfile, Integration/CreditProfile all passed (136).

## Deploy
```bash
php artisan migrate --force   # 2026_10_12 encrypts credit_profile_requests.ssn; 2026_10_15 makes it one per borrower
php artisan config:cache
php artisan horizon:terminate
```

Before releasing, count the borrowers holding only the last four digits. They will be asked for the full SSN on their next profile save.

## Not done here
The comments carried over from `stage` still say the full SSN is "never echoed back". `stage`'s final decision is that the profile read returns it. They were kept identical so the merge stays clean; fix them on `stage` and here in the same change.
