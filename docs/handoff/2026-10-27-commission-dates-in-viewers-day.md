# Commission dates show the user's day, not the UTC day (BUG-120)

**Date:** 2026-10-27
**Branch:** bugfix/all-bugfix
**Author:** Awlad Hossain

## Problem
QA defect log **BUG-120** (PP-CERT-13). A host closed application 89 (Closed (Completed), funded $50,000) and paid its commission in cash on 21 Oct around 03:00 local time. The Commissions list showed 2026/10/20, and commission 24 showed Funded Date Oct 20, 2026 and Paid On Oct 20, 2026. The payout invoice said 2026-10-21 and the activity said "Today".

The server runs in UTC, and 03:00 on 21 Oct in UTC+6 is still 20 Oct in UTC. The portals show these strings as the API sends them, and the API used the UTC day for all four:
- List **Date**: `date('Y/m/d', strtotime($this->created_at))`, a UTC time formatted without the viewer's zone.
- **Paid On** (payout record): `paid_at->format(...)`, the same problem.
- **Funded Date** and **Paid On** for payments made before payout records existed: `funding_applications.funded_date` and `commissions.payout_date` are date-only columns, written as `now()`, which is the UTC day.

## Solution
Times stay in UTC. Date-only columns stay `date`.
- **Times** (`commissions.created_at`, `commission_payouts.paid_at`): shown with `toTimezone(..., null, format)`, i.e. as the viewer's day from the `X-Timezone` header.
- **Dates** (`funded_date`, `payout_date`): written as the day in the zone of whoever closed or paid the deal, using the new helper `todayInRequestTimezone()` (`X-Timezone`, falling back to the app timezone, including for an invalid zone). Shown with `toDateOnly(...)`, never shifted, so every viewer sees the same calendar date.

## Files changed
- `app/Helpers/helpers.php`: new `todayInRequestTimezone()`.
- `app/Services/FCH/Common/Application/RequestApplicationService.php`: `funded_date` uses the closer's day.
- `app/Services/FCH/Common/Commission/CommissionPayoutService.php`: `payout_date` uses the payer's day. `paid_at` is shown with `toTimezone`; the older payments' `payout_date` with `toDateOnly`.
- `app/Http/Resources/FCH/Common/Commission/CommissionResource.php`: list `date` uses `toTimezone`.
- `app/Http/Resources/FCH/Common/Commission/CommissionDetailsResource.php`: `payout_date` and `funded_date` use `toDateOnly`.
- `lang/en/commission.php`: `date_format` (`M d, Y`) and `list_date_format` (`Y/m/d`), used in place of the inline formats above.
- `lang/en/application.php`, `app/Models/ActivityLog/ActivityLog.php`: the funding status change's activity log text (title, description, "Note:") moved from inline strings to lang keys. Its log type is now `ActivityLog::LOG_TYPE_FUNDING_APPLICATION_STATUS_UPDATE`. The wording is unchanged.

## Tests
- Added: `tests/Feature/Commission/CommissionDatesInViewerTimezoneTest.php`, 6 tests. List date and Paid On are the viewer's day; the funded date is shown as saved, in any zone; paying stores the payer's day; older payments show `payout_date` as saved; with no header a time is the UTC day. 3 failed before the fix.
- Added: `tests/Unit/Support/HelpersTest.php::today_is_the_day_in_the_callers_timezone_header`. Covers the header, no header, and an invalid zone.
- Added: `tests/Feature/Application/RequestApplicationReviewTest.php::a_funded_date_is_the_day_in_the_closers_timezone`. Failed before the fix ("2026-10-20 21:30:00").
- Added: `RequestApplicationReviewTest::a_status_change_and_a_note_are_logged`. Pins the activity log wording before and after the move to lang keys.
- Suites run: full `php artisan test` gave 3814 passed, 2 skipped, 1 failed. The failure is `Tests\Unit\Assistant\NavigationMapTest` (`/capital-access` has no route in `borrower-fch/src/App.jsx`), which also fails without this change. After the lang changes, `tests/Feature/{Application,Commission,CommissionPayout,FundApplication}` and `HelpersTest`: 301 passed, 1 skipped.

## Open / follow-up
- **Decision (2026-10-27): `funded_date` and `payout_date` stay date-only.** Every viewer sees the same calendar date, the day in the zone of whoever closed or paid the deal. Known effect: a viewer in a zone far from that person can see the list Date / Paid On (their own day) one day apart from the Funded Date. Storing both as UTC datetimes would remove this, if it is ever needed.
- **Existing data is not changed.** Deals funded or paid before this change keep their UTC date. Commission 24 still shows Oct 20.
- Re-test BUG-120 on stage after deploy: close and pay a deal shortly after local midnight.
- No portal (`react-fch`) change was needed, because the screens show the API's strings.
