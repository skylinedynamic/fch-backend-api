# Dashboard and list card counts

**Date:** 2026-10-22
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
The partner portal dashboard's top cards (`GET /api/v1/common/dashboard`, `data.counts`) didn't match the cards on the Partners, Clients and Leads list pages:

- **Host Total Partners** included the host's own partner record. Locally it showed 24 when there were 23 partners. The Partners list cards did the same.
- **Total Clients** (host and partner) counted archived clients. Locally the dashboard showed 50 while the Clients list showed 49.
- **Leads list cards** were counted from the filtered table, so searching or filtering changed "Total Leads". The Partners and Clients cards always show the full total.
- **Partner Total Funding Applications** left out On Hold applications, while the Funding Applications page card counts them.
- **Commissions page cards** were also counted from the filtered table, and the partner's "Total Deals" counted every commission instead of the completed ones.

Soft-deleted partners, clients and leads were already excluded everywhere, because all three models use `SoftDeletes`.

## Solution
- Dashboard: Total Clients is `Borrower::visibleTo($user)->notArchived()->count()` and Total Leads is `Lead::visibleTo($user)->count()`, the same rules the list pages use. `visibleTo` gives the host every partner's records and a non-host partner only its own. Host Total Partners is `Partner::notArchived()->excludingHost()->count()`. This also stops loading every borrower and lead row just to count them.
- New `Partner::scopeExcludingHost()`, used by the dashboard and by the Partners list card snapshot. The host row still appears in the Partners table, so `the_partner_list_holds_every_partner_the_host_included` is unchanged. It's just not counted.
- Leads list: the card query is snapshotted after `visibleTo` and the host's Partner filter, but before the search, dropdown and column filters. This matches `PartnerService::list()` and `BorrowerService::list()`.
- Partner dashboard: `funding_application` counts every application of the partner, On Hold included. Deleted ones are still left out (`SoftDeletes`).
- Commissions list (`CommissionService::getCommissions()`): the cards come from a snapshot taken after partner scoping and the host's Partner filter, before the status, search and column filters. A new `statistics.completed_deals` counts `commission_status = Completed`. `total_deals` is still sent. The admin frontend's "Total Deals" card is now "Completed Deals" (see that repo's handoff of the same date).

Behaviour change: the host's Total Partners and active-partner cards drop by one. The Leads and Commissions cards no longer change while the table is searched or filtered. A partner's funding-application count now includes On Hold. Response shapes only gained `completed_deals`.

## Files changed
- `app/Services/FCH/Admin/Dashboard/DashboardService.php`: dashboard counts use `visibleTo`/`notArchived`/`excludingHost`
- `app/Models/Partner/Partner.php`: new `scopeExcludingHost()`
- `app/Services/FCH/Partner/PartnerService.php`: Partners list card snapshot excludes the host
- `app/Services/FCH/Common/Lead/LeadService.php`: Leads list cards counted from a pre-filter snapshot
- `app/Services/FCH/Common/Commission/CommissionService.php`: cards from a pre-filter snapshot, plus `completed_deals`
- `docs/test-findings.md`: added F131

## Tests
- Added: `tests/Feature/Dashboard/PartnerDashboardCountsTest.php`. Covers the host's partner, client and lead counts (excluding host, deleted and archived) and that a partner counts only its own live, unarchived clients and leads.
- Updated: `tests/Feature/Partner/PartnerAdminApiTest.php` (+1 test: partner cards exclude host, deleted and archived) and `tests/Feature/Lead/HostLeadAccessTest.php` (+1 test: lead cards ignore search and filters). The existing "host narrows to one partner" card test still passes.
- Added to `PartnerDashboardCountsTest`: a partner's funding applications include On Hold and leave out deleted ones and other partners' applications.
- Updated: `tests/Feature/CommissionPayout/CommissionListTest.php` (+1 test: cards keep their totals under a payment-status filter, and `completed_deals` counts only Completed). Its `commission()` helper now takes optional status arguments.
- Watched the new assertions fail first, then pass after the fix.
- Ran the Feature folders Dashboard, Partner, Lead, Borrower, Archive, Application, FundApplication and CommissionPayout, plus Integration/Lead: all OK. The full suite gave 3328 tests with 6 errors, all in `General/DeadRoutesTest` and `General/SmallEndpointsApiTest`. Those 6 also fail without this change: the Composer classmap still lists deleted classes, and `composer dump-autoload` should clear it.
- The local PHP has `pdo_sqlite` disabled in `php.ini`, so I ran the tests with `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit`. `php artisan test` doesn't pass `-d` on to its child process.

## Open / follow-up
- Should the host row be removed from the Partners table too? The `is_host != 1` filter in `PartnerService::list()` is still commented out.
- Run `composer dump-autoload` to clear the 6 stale-classmap errors.
