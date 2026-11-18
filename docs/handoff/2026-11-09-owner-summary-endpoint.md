# Owner summary endpoint

2026-11-09

## Problem

The host owner had no single view of the business. The approved Owner Dashboard design needed an API, and it had to work with what the database records today, with no migration.

## Solution

New endpoint `GET /api/v1/common/owner-summary?period=&environment=`, with its own controller, request and service. The existing dashboard is unchanged.

- **Access:** `host-partner` middleware on the route, then `CommissionViewer::HOST_OWNER` in `FetchOwnerSummary`. That is the host's own login, or host staff holding `update commission`. No new permission was needed.
- **Periods:** `this_month` (default), `last_month`, `this_quarter`, `this_year`, `last_30_days`, in the viewer's `X-Timezone`, each compared with the period before.
- **Environment:** `production` (default) or `sandbox`; it filters payments.

What each number means (also in the service's docblock):

- **Cash collected:** succeeded payments on invoices the host is paid on (the `InvoicePartyResolver` rule), excluding commission payouts.
- **Owed:** the same invoices when Unpaid/Overdue or Failed. Invoices have no due date (`payment_date` is the paid date), so "late" means raised more than 30 days ago.
- **Funded:** `Closed (Completed)` with a `funded_date` in the period.
- **Commission:** booked amounts from `CommissionViewer`, not cash. `payoutsDue()` was added so the summary uses the same calculation as the Pay button.
- **Stage activity:** from the audit trail. Funded comes from `funded_date` instead, because the trail only starts on 2026-11-03.
- **Stalled:** open, with no status change for 14 days. The list stops at 10; the count and `stalled_requested` cover all of them.

Churn and lead-to-funded conversion are left out on purpose: nothing records a cancellation date or a lead-to-client link.

## Files changed

- `app/Http/Controllers/Api/V1/FCH/Common/OwnerSummary/OwnerSummaryController.php`: new
- `app/Http/Requests/Api/V1/FCH/Common/OwnerSummary/FetchOwnerSummary.php`: new; owner check and validation
- `app/Services/FCH/Common/OwnerSummary/OwnerSummaryService.php`: new; every number
- `app/Services/FCH/Common/OwnerSummary/OwnerSummaryPeriod.php`: new; period windows
- `app/Services/FCH/Common/Commission/CommissionViewer.php`: added public `payoutsDue()`
- `routes/common.php`: the route
- `lang/en/owner_summary.php`: new
- `tests/Feature/OwnerSummary/OwnerSummaryTest.php`: new

## Testing

- `tests/Feature/OwnerSummary/OwnerSummaryTest.php`: 17 tests, written first.
- With the Commission and Dashboard feature tests: 55 tests pass.
- Admin panel live E2E against this endpoint: pass (read-only). Host 2FA is off, so the login sent no mail.
- Full suite: 5107 tests, all pass except 2 skipped.

## Open items

- **Weekly email report:** no job exists, so the design's "Report settings" was not built.
- **To add churn:** subscription status history (migration).
- **To add conversion %:** a lead-to-client link (migration).
- `application_number` repeats the id (`FUND-0001313`); the summary shows the stored value.
- `leads.lead_source` is a plain string. Any value outside `Lead::getSourceOptions()` is dropped from the source list, so check old data.
- Performance has not been measured on production volumes. Open applications and deals with payouts due are loaded into PHP; consider caching if the page is slow.
