# Owner summary: this week and custom periods

2026-11-09

## Problem

The approved Owner Dashboard design offers This week, This month and a custom From/To range. `GET /api/v1/common/owner-summary` only accepted preset months, quarters and years.

## Solution

Two new `period` values:

- **`this_week`:** Sunday to Saturday in the viewer's `X-Timezone`, compared with the week before.
- **`custom`:** takes `from` and `to` (`Y-m-d`), compared with the same number of days just before.

Custom range rules, enforced in `FetchOwnerSummary`, with messages in `lang/en/owner_summary.php`:

- Both dates are required and must be real dates.
- Neither can be after today, in the viewer's timezone.
- `to` cannot be before `from`.
- There is no limit on length. A 366-day limit from the design was removed at the owner's request.

`from` and `to` are ignored for every other period. The older period values still work, and the response shape is unchanged, with `period.key` now also `this_week` or `custom`.

## Files changed

- `app/Services/FCH/Common/OwnerSummary/OwnerSummaryPeriod.php`: `THIS_WEEK`, `CUSTOM`, `custom()`, `daysInRange()`
- `app/Http/Requests/Api/V1/FCH/Common/OwnerSummary/FetchOwnerSummary.php`: range rules, messages and `summaryPeriod()`
- `app/Http/Controllers/Api/V1/FCH/Common/OwnerSummary/OwnerSummaryController.php`: uses `summaryPeriod()`
- `lang/en/owner_summary.php`: validation messages
- `tests/Unit/OwnerSummary/OwnerSummaryPeriodTest.php`: new
- `tests/Integration/OwnerSummary/OwnerSummaryCustomPeriodTest.php`: new
- `tests/Feature/OwnerSummary/OwnerSummaryTest.php`: new cases for both periods and each validation rule

## Testing

- Unit, integration and feature tests were written first and failed before the change.
- OwnerSummary and Commission folders: 114 tests, all pass.
- `react-fch` live E2E against this API, including a custom range: 4 passed, including login setup.
- **Not run:** the full backend suite.

## Open items

- **Report settings:** built afterwards, see `2026-11-09-owner-weekly-report.md`.
- **Chart months:** the monthly charts show six months ending at the range's last month, so they do not narrow to a custom range.
- Client: `react-fch` `docs/handoff/2026-11-09-owner-summary-period-controls.md`.
