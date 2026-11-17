# Owner weekly report

2026-11-09

## Problem

The approved Owner Dashboard design has a Report settings button that sets up a weekly email with a PDF. Nothing behind it existed: no saved recipients, no scheduled job and no server-side PDF.

## Solution

- **Settings:** `GET` and `PUT /api/v1/common/owner-summary/report-settings`, for whoever may see the owner summary (`CommissionViewer::HOST_OWNER`). One row per host. With no row, the report is off with nobody on it.
- **Validation:** up to 10 complete, unique addresses, and at least one when the report is on. Messages are in `lang/en/owner_summary.php`.
- **Sending:** `owner-summary:send-weekly-report` runs Sundays at 10:00 America/New_York. That timezone is deliberate, because the 10:00 AM ET promise shouldn't move when clocks change.
- **Content:** last week (new `last_week` period, Sunday to Saturday Eastern), live payments only, using the same `OwnerSummaryService` as the screen, so the numbers match.
- **Mail route:** sent through `Notifier` as type `owner_summary.weekly_report`, so it can be switched off in `notification_types` without a deploy.
- **Every run sends:** at the owner's request, each run of the command sends the report if it is on and has recipients. `last_sent_period_from` only records the last week sent and doesn't block anything. Run it from one place only: the Laravel schedule entry and a separate cron on the command would send twice.
- **Failures:** a report that is due but has no host owner login exits with failure, which alerts the on-call list through the existing schedule handler.
- **Look:** the PDF and the email body both follow the Owner Summary screen, and share `resources/views/owner_summary/sections.blade.php`, fed by `OwnerReportPresenter` (a mirror of the screen's formatters). Change the screen's wording or formatting and that class together.
- **PDF:** letter portrait, 3 pages for a busy week. Cards are kept whole, so the charts and stalled deals come earlier than on the screen to avoid half-empty pages.
- **Email:** every section of the report, plus an "Open Owner Summary" button and Review links. The two wide tables scroll sideways on a phone.

The dashboard has an All Payment, Production and Sandbox switch in `react-fch`. The API's `environment` parameter takes `all`, `production` or `sandbox`, defaulting to production. Only `payments.is_environment` carries this mark, so invoices, deals, commissions, clients and leads don't change with it. The weekly report always uses production.

## Headline cards (changed at the owner's request)

Seven cards replace the original six, on the screen, the PDF and the email. `kpis` in the API keeps its old keys and adds to them.

| Card | What it counts |
|---|---|
| Net earnings | The old cash collected, renamed: every succeeded live payment on invoices the host is paid on. Commission payouts aren't taken off, despite "net", because the owner defined it as all payments received. The API key is still `cash_collected`. |
| Funded volume, Funded deals | Period's Closed (Completed) deals, split by the deal's `partner_id`. No partner counts as the host's. |
| Partners, Clients | All not archived, with how many have Active status and how many are new in the period. These ignore the period except the new count. |
| Leads | Created in the period, split by the lead's `partner_id`. |
| Lead-to-funded | Client-type leads created in the period that converted, and whose client has a Closed (Completed) deal at any date. Split by the lead's `partner_id`. |

On the screen and in both reports, the Net Earnings, Funding Commissions and Owed row comes straight after these cards, above Needs Attention. Visible text says FCH, never Host; API keys and code keep `host`. Card and section titles capitalize every word ("Funded Volume By Month"); labels inside a card, such as table rows and Needs Attention items, stay in sentence case.

`leads.convert_to` only stores "Borrower" or "Partner", so a lead has no client id. The match is by email, which conversion copies to the client and refuses if already in use. An unconverted lead isn't counted, since it can share an email with another partner's client. Owed to us left the top row but remains in its own section and in Needs attention.

## Files changed

- `database/migrations/2026_11_09_000001_create_owner_report_settings_table.php`: new table
- `app/Models/OwnerSummary/OwnerReportSetting.php`: new
- `app/Http/Controllers/Api/V1/FCH/Common/OwnerSummary/OwnerReportSettingsController.php`: new
- `app/Http/Requests/Api/V1/FCH/Common/OwnerSummary/FetchOwnerReportSettings.php`, `UpdateOwnerReportSettings.php`: new
- `app/Http/Resources/FCH/Common/OwnerSummary/OwnerReportSettingResource.php`: new
- `app/Services/FCH/Common/OwnerSummary/OwnerReportSettingsService.php`, `OwnerWeeklyReportService.php`, `OwnerWeeklyReportPdf.php`, `OwnerReportPresenter.php`: new
- `resources/views/owner_summary/sections.blade.php`, `stackbar.blade.php`: new; the sections the PDF and email share
- `app/Services/FCH/Common/OwnerSummary/OwnerSummaryPeriod.php`: `LAST_WEEK`, `rangeLabel()`
- `app/Services/FCH/Common/Application/FundingApplicationDocumentService.php`: public `getHostBranding()`
- `app/Console/Commands/SendOwnerWeeklyReport.php`: new
- `app/Mail/OwnerSummary/OwnerWeeklyReportMail.php`: new
- `resources/views/emails/owner_summary/weekly_report.blade.php`, `resources/views/pdf/owner-weekly-report.blade.php`: new
- `config/notifications.php`: the report's notification type
- `routes/common.php`, `routes/console.php`: routes and schedule entry
- `lang/en/owner_summary.php`: messages and report wording
- `tests/Feature/OwnerSummary/OwnerReportSettingsTest.php`, `tests/Integration/OwnerSummary/WeeklyReportCommandTest.php`, `tests/Unit/OwnerSummary/OwnerReportPresenterTest.php`, `tests/Fixtures/owner_weekly_report.php`: new
- `tests/Unit/OwnerSummary/OwnerSummaryPeriodTest.php`, `tests/Integration/Console/ScheduleTest.php`: updated

## Testing

- **Targeted:** owner summary and schedule tests: 84 pass. New tests failed first.
- **Full suite, on the final code:** 5174 passed, 2 skipped, no failures.
- **Live E2E:** `react-fch` owner summary live specs, including saving Report settings: 5 passed, including login setup.
- **Checked by eye:** the PDF at letter size, and the email at desktop and phone width in Chromium, all from `tests/Fixtures/owner_weekly_report.php`.
- **Not tested:** a real email through real mail settings, or how real email clients (Gmail, Outlook) draw it. Tests use a fake mailer.

## Open items

- **Lead-to-funded undercounts:** a client whose email changed after converting isn't matched. On local data 26 of 32 converted client leads still match. Local data also shows 0 of 76 reaching funded, because the 5 funded clients didn't come from leads. Storing the client id on the lead at conversion would make this exact, and needs a migration and a backfill.
- **Two meanings of active partner:** the Partners card uses Active status (26 of 29 locally); the Partner network line "Active partners (lead in 30 days)" uses recent leads (1 of 30 locally). The second total still includes archived partners.
- **Unused API fields:** `kpis.outstanding` and `kpis.new_clients` are no longer shown, and were left in the response.

- **Deploy step:** run `php artisan migrate`. Until then, opening Report settings gets a 500 (`owner_report_settings` doesn't exist) and the dialog shows "Report settings could not be loaded".
- **Client names by email:** stalled deals show the client's business name in both the email body and the PDF, sent to whichever addresses the owner lists.
- **Mail worker:** restart `queue:work` so it picks up the new mailable, and confirm the scheduler is running. A worker started before the mailable's last change logged `Undefined variable $summary`; `MailConfig` swallows that error, so the job finishes with nothing in `failed_jobs`.
- **Mail settings:** the report goes out through the host's mail configuration. Without one, `MailConfig` logs an error and sends nothing, while the week is still recorded as sent.
- **Failed queued mail:** the week is recorded once queued, so `last_sent_period_from` doesn't prove delivery. A mail that later fails isn't retried by the command; run it again to send a new one.
- **Repeat sends:** a cron set more often than weekly emails the same week's report each time.
