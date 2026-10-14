# Application partner on partner-side saves (F107, was O17)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F107 (was O17)

## Problem
`PartnerFundingApplicationService::saveFundingApplication()` set:

```php
$data['partner_id'] = auth()->user()->partner_id ?? auth()->id();
```

That is the **caller's** partner, not the borrower's. The fallback, `auth()->id()`, is a user id, not a partner id at all.

When the host's team saved any step of a white-label or certified partner's borrower's application, the application moved into the host's book. The application list was not affected, because it is scoped through the borrower. Everything that reads the application's own `partner_id` went wrong:
- **Submit notification.** It went to the host instead of the partner. The "also tell the host" branch was skipped, so the partner was never told.
- **Admin dashboard.** The application dropped out of the partner's application count and "latest applications", and was counted for the host instead.
- **Activity log and documents.** Both used the host as the application's partner.
- **Partner delete cascade.** `Partner::fundingApplications()` picked up the wrong rows.

## Solution
- **Ownership comes from the borrower.** The application's `partner_id` is now the borrower's `partner_id`, whoever prepares it. `is_partner_prepared = true` still records that a partner-side user prepared it.
- **Backfill.** Migration `2026_10_13_000000_backfill_funding_application_partner_from_borrower` sets `partner_id` to the borrower's partner on every application where it differs or is null. Rows that already match are left alone, so it is safe to re-run.

No borrower-transfer feature exists, so the borrower's current partner is the correct owner for old rows too.

## Files changed
- `app/Services/FCH/Common/PartnerFundApplication/PartnerFundingApplicationService.php`
- `database/migrations/2026_10_13_000000_backfill_funding_application_partner_from_borrower.php` (new)
- `tests/Feature/FundApplication/PartnerFundingApplicationStepsTest.php`
- `tests/Integration/Application/FundingApplicationPartnerBackfillTest.php` (new)

## Tests
- `Feature/FundApplication/PartnerFundingApplicationStepsTest`, written to fail first and then passing:
  - `a_save_by_the_hosts_team_leaves_the_application_with_the_borrowers_partner` covers all 5 steps.
  - `an_application_the_hosts_team_starts_belongs_to_the_borrowers_partner` covers a new application.
- `Integration/Application/FundingApplicationPartnerBackfillTest` covers the backfill:
  - a moved row and a null row are fixed;
  - correct rows and the host's own rows are left alone;
  - a second run changes nothing.
- Suites run: fch-api full suite — 2269 passed, 42 skipped, 0 failed. Real `storage/app` unchanged before and after.

## Open / follow-up
- Run `php artisan migrate` on deploy so the backfill runs. To see what it will change first:
  `SELECT fa.id, fa.partner_id, b.partner_id FROM funding_applications fa JOIN borrowers b ON b.id = fa.borrower_id WHERE b.partner_id IS NOT NULL AND (fa.partner_id IS NULL OR fa.partner_id <> b.partner_id);`
- Nothing records **which user** prepared an application. If that is wanted, add a `prepared_by_user_id` column.
