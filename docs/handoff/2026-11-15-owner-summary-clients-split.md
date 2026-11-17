# Owner Summary: clients split between FCH and partners

2026-11-15

## Problem
The Clients card on the Owner Summary / Business Insights page (`/owner-summary`) and in the weekly report showed only a total, Active and New. It didn't show how many clients are FCH's own and how many belong to partners, unlike Funded Volume, Funded Deals and Leads.

## Solution
`kpis.clients` in `GET /common/owner-summary` gains `host` and `partners`. Both count non-archived clients, and `host + partners = total`. A client counts as the host's when its `partner_id` is null or is the host's id, which is the same rule (`isHosts`) used for deals and leads. The existing `total`/`active`/`new` keys are unchanged.
In the weekly report the Clients card now has FCH / Partners rows like the other split cards. Active and new move to the note: ":active active · :new new last week".

## Files changed
- `app/Services/FCH/Common/OwnerSummary/OwnerSummaryService.php`: new `clients()` with the host/partners split
- `app/Services/FCH/Common/OwnerSummary/OwnerReportPresenter.php`: Clients card uses the FCH/Partners split and an active/new note
- `lang/en/owner_summary.php`: `report.clients_note`
- `tests/Feature/OwnerSummary/OwnerSummaryTest.php`: new `clients_are_split_between_the_hosts_own_and_other_partners`; the existing clients assertion now includes host/partners
- `tests/Unit/OwnerSummary/OwnerReportPresenterTest.php`, `tests/Fixtures/owner_weekly_report.php`: card rows/note and fixture

## Testing
`php artisan test tests/Feature/OwnerSummary tests/Unit/OwnerSummary tests/Integration/OwnerSummary`: 81 passed (both new assertions were red first).
A plain `php artisan test --filter=...` currently crashes while loading `tests/Feature/FundApplication/BorrowerEditsApplicationWithoutFundingRequestTest.php`, because it uses the missing trait `Tests\Support\ConvertsEmbedFormSubmissions`. That is unrelated to this change, so run the tests by path.

## Open items
- Fix the missing `ConvertsEmbedFormSubmissions` trait (unrelated, but it blocks full-suite runs).
- The frontend change is in react-fch (`2026-11-15-owner-summary-clients-split.md`).
