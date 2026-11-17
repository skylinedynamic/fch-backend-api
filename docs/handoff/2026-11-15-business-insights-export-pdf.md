# Business Insights › Export PDF: summary report and details report

Date: 2026-11-15

## Problem

Business Insights (`/owner-summary`) had one "Export PDF" button, and it only called `window.print()`. The result depended on the browser and the screen size. There was no real report to keep or send.

The requirement:
- **Two reports from a dropdown:** a summary report and a details report.
- **Professional, not colourful.** The first pass used a navy band, gold accents and coloured tiles; the owner asked for a standard look.
- **The summary report is one page.**

## Solution

**New endpoint:** `GET /api/v1/common/owner-summary/export?type=summary|details`.
- It takes the screen's own period and environment params (`period`, `from`/`to`, `environment`).
- It allows only the host owner, as the screen does (`ExportOwnerSummary` extends `FetchOwnerSummary`).
- It returns `application/pdf` with `attachment; filename=business-insights-{type}-{from}-to-{to}.pdf`.

**`OwnerSummaryExportPdf`** builds both reports with dompdf from `OwnerSummaryService::summary()` through `OwnerReportPresenter`. The figures and wording are the screen's and the weekly email's.

**Look (fourth pass).** Earlier passes were too colourful, then too dashboard-like, then too busy (rules competing, side-by-side tables of different heights, a second muted line inside rows). The owner asked for simple, neat and clean. The final design:
- **Running header:** the host's **document logo** at 40px (`LOGO_HEIGHT`; a very wide logo is kept to `LOGO_MAX_WIDTH`, in its own proportions). It comes from `logo_document`, falling back to `logo_sidebar`, through `FundingApplicationDocumentService::getHostBranding()`. The company name, report and period sit on the right, over one deep navy rule (#1c2b45).
- **Running footer:** "Confidential · …", the generated time, and "Page X of Y". The page number is drawn with `page_script`, since dompdf CSS has no total page count.
- **One rhythm for every section:** a heading (numbered 01–08 in details), a one-line description, then the content. Sections are stacked full width, with nothing side by side.
- **Tables:** a dark rule under the headings, light rules between rows, and a dark rule over a total. Descriptions get their own column instead of a second line.
- **Type:** serif for titles and headline figures, sans for everything else.
- **Colour:** only on up and down changes (green or red text).
- **At a Glance** (`owner-export-glance`): eight headline figures in an even 4 × 2 grid:
  - top row: net earnings, funded volume, funded deals, leads
  - bottom row: outstanding, payouts due, partners, clients

**Summary report (fifth pass):** built from the owner's own HTML mock, converted for dompdf (tables instead of flex or grid, solid fills instead of gradients, DejaVu Sans instead of Inter). It is a standalone view, not the shared layout, and stays on one page (pinned by a test with a busy fixture). From top to bottom:
- a teal accent bar, then the document logo with the report and period on the right
- the title block: eyebrow, large title, period, and the meta list
- **At a Glance:** four cards. Net earnings is filled dark teal; the other three are funded volume, funded deals and leads. Each change is a badge with its direction (▲ green, ▼ red, — grey), with "vs … before" under it, and a zero value is greyed.
- **Receivables & Payables:** outstanding ("12 invoices awaiting payment") and payouts still due.
- **Network:** partners and clients, each with active and a "+N new this month" badge. The numbers come from the new `OwnerReportPresenter::community()`.
- **Net Earnings by stream:** dots (grey when a stream had no payments), banded rows, and a tinted total row.
- **Footer:** company · report, the generated time and timezone, and "Page 1 of 1".

**Both reports share this design (sixth pass).** The look lives in one layout (`owner-summary-export-layout`) and shared partials:
- `owner-export-cards`: the performance, receivables and network cards.
- `owner-export-streams`: net earnings by stream.
- `owner-export-heading`: the barred, optionally numbered heading.
- `owner-export-meta`: the "what it covers" list.

The details report has:
- **A cover:** the logo at 1.5×, the title with a teal rule, the meta list with a teal edge, and the numbered contents.
- **Section 01:** the same cards, plus every metric with direction badges.
- **The other sections:** barred, numbered headings over banded tables with tinted totals. Trend changes are badges, and the definitions sit in a tinted box.

**Readable text.** The pale grey #8593a0 is gone. Secondary text is now #4a5b6b and muted text #5b6b7a, small text is about half a point larger, and the page number is darker and 7.5pt. A test keeps the pale greys out of both reports.

**Details report:**
- **A cover page:** the logo at 1.5×, the title, the period, what it covers, and the numbered **Contents** (01–08, each with its one-line description). The running header and footer are painted out on the cover, because dompdf repeats fixed elements on every page; page numbers start on page 2.
- **The eight numbered sections** as tables:
  - at a glance, plus every metric as a statement (Metric, Value, Change, Breakdown; `owner-export-metrics`)
  - money in & owed (streams, commissions, owed)
  - every attention item
  - trends
  - pipeline & lead sources
  - partner network & top partners
  - stalled deals
  - definitions
- **Every stalled deal**, not the screen's 10. `summary()` gained an optional `$stalledLimit`; details passes `DETAILS_STALLED_LIMIT` (200).

**Period wording.** The presenter said "last week" in about ten places, because it was built for the weekly email. It now takes the words from the report's period through `when()` and `periodColumn()`, and `$summary->t()` in `owner_summary.sections`. Examples: "New this month", "No new leads in the selected range". The weekly report still says "last week".

## Files changed

- `app/Http/Controllers/Api/V1/FCH/Common/OwnerSummary/OwnerSummaryController.php`: `export()`.
- `app/Http/Requests/Api/V1/FCH/Common/OwnerSummary/ExportOwnerSummary.php` (new): adds `type`.
- `app/Services/FCH/Common/OwnerSummary/OwnerSummaryExportPdf.php` (new): `report()`, `render()`, `pageCount()`, `data()`, `filename()`.
- `app/Services/FCH/Common/OwnerSummary/OwnerSummaryService.php`: `summary(..., ?int $stalledLimit)`.
- `app/Services/FCH/Common/OwnerSummary/OwnerReportPresenter.php`: `when()`, `periodColumn()`, `t()`, `community()`.
- `resources/views/owner_summary/sections.blade.php`: `:when` through `$summary->t()`, and the period column heading.
- `resources/views/pdf/owner-summary-export-layout.blade.php`, `owner-summary-export-summary.blade.php`, `owner-summary-export-details.blade.php` (new).
- `resources/views/pdf/partials/owner-export-glance.blade.php` (the 4 × 2 headline grid), `owner-export-metrics.blade.php` (the metrics statement, details only), `owner-export-trend.blade.php`, `owner-export-meta.blade.php` (what a report covers) (new).
- `routes/common.php`: `GET owner-summary/export` (host-partner).
- `lang/en/owner_summary.php`:
  - "last week" became `:when`
  - new `report.when` and `report.period_columns`
  - new `export_environments`, `export`, and the export type messages
- **Tests:**
  - `tests/Feature/OwnerSummary/OwnerSummaryExportTest.php` (new, 7): PDF and file name for both types, a custom range, `type` required, the period checked, owner only, every stalled deal in details only
  - `tests/Integration/OwnerSummary/OwnerSummaryExportReportTest.php` (new, 15):
    - title and dates
    - summary keeps to the headlines (no attention, trends or details sections)
    - summary shows net earnings by stream
    - summary has the cards of the agreed design (hero card, direction badges, receivables, network)
    - both reports share the branded design (accent bar, barred headings, cards)
    - both reports keep their text readable (no pale grey)
    - details contents
    - period wording
    - weekly still "last week"
    - **summary is one page**
    - plain palette
    - logo printed at 40px or more
    - details sets out every metric
    - the details cover page with its contents

## Testing

- **Seen failing first:**
  - the new endpoint and report tests
  - then the one-page and plain-palette tests
  - then the logo and cover tests (third pass)
  - then the short summary and net earnings tests (fourth pass)
  - then the agreed-design test and `community()` (fifth pass)
  - then the shared-design and readable-text tests (sixth pass)
- **`tests/Unit/OwnerSummary/OwnerReportPresenterTest.php`:** +1 (`community()`).
- **`tests/Feature/OwnerSummary`, `Integration/OwnerSummary`, `Unit/OwnerSummary`:** 116 passed after the sixth pass, including the weekly report tests, unchanged.
- **Full suite (second pass):** 5,595 tests passed (2 skipped, as before). Later passes changed only the export views, lang strings and `OwnerSummaryExportPdf`.

## Open items

- **Admin side:** see the admin handoff of the same name.
- **Very large books:** the details report lists up to 200 stalled deals.
- **Rendering time:** dompdf takes about 1–2 s per report.
