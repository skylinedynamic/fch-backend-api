# Remove the public weekly summary

**Date:** 2026-10-12
**Branch:** bugfix-awlad (uncommitted)
**Author:** Awlad Hossain (with Claude Code)

## Problem
`GET /api/v1/common/weekly-summary` was a public endpoint — no sign-in — that returned the platform's weekly executive summary: gross revenue, Four Corner net, partner payouts, partner names, and **client (borrower) names with their commission amounts**. That is nonpublic personal and financial information (GLBA) available to anyone with the URL. No frontend (borrower portal, admin panel, website) called it. It was recorded as decision D11 in `docs/test-findings.md`; the feature is no longer needed.

## Solution
Removed the feature entirely. `/api/v1/common/weekly-summary` now answers **404**. Nothing else depended on the controller or the service.

## Files changed
- `routes/common.php` — route and controller import removed
- `app/Http/Controllers/Api/V1/FCH/Admin/WeeklySummary/WeeklySummaryController.php` — deleted
- `app/Services/FCH/Admin/WeeklySummary/WeeklySummaryService.php` — deleted
- `docs/test-findings.md` — D11 closed, recorded as F66

## Tests
- Updated: `tests/Feature/General/SmallEndpointsApiTest` — `the_weekly_summary_is_served_without_signing_in` (which pinned the public behaviour) replaced by `the_weekly_summary_no_longer_exists`: the URL is 404 and neither class exists. Written first and seen failing (200) before the code was removed.
- Run: `SmallEndpointsApiTest` 8 passed; Feature suite re-run after the removal.

## Open / follow-up
- Locally, run `composer dump-autoload` once after pulling — an optimised class map still lists the deleted files until it is regenerated (CI does a fresh `composer install`, so it is unaffected).
- If anyone outside these repos (a bookmark, a report script) was reading this URL, it now gets 404.
