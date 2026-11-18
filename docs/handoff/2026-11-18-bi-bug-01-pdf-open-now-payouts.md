# BI-BUG-01 and Business Insights by period: every figure follows the period picked

**Date:** 2026-11-18
**Branch:** feature/owner-summary
**Author:** Awlad Hossain

## Problem
BI-BUG-01 came from the Business Insights feature test, Run 1 (BI-28, BI-29). Both Export PDF reports showed **Payouts still due $2,033** under "Open right now, as of this report". That is the payouts on deals closed in the period. Needs Attention showed $8,232.50, which was every payout owed regardless of the deal's date.

The real issue was that the screen and reports mixed two kinds of figure. Some followed the period: earnings, funded deals and leads. Others were "as of now": owed invoices, payouts due and most of Needs Attention.

**Decision (product owner):** a Business Insights report shows the data of the selected period. Only what cannot be dated stays as of now, and is labelled so.

## Solution
**Now follow the period** (`OwnerSummaryService`):

| Figure | Counted as |
|---|---|
| Outstanding / Owed, Unpaid over 30 days, Failed invoices | Host invoices raised in the period, still unpaid or failed. Unpaid ones are aged as of today. |
| Payouts still due (Funding Commissions, Needs Attention, PDF card) | Payouts still owed on deals funded in the period |
| Partners owing us | Partners with an unpaid or failed invoice raised in the period |
| Manual payments to approve | Processing manual payments created in the period |
| Embedded-form applications, Pre-qualifications pending | Created in the period and still new or pending |
| Free trials ending | Trial end date inside the period |

**Stay as of now, labelled "as of now" on the screen and in the PDFs:**
- Suspended partners and Stalled deals. No history of either is kept.
- Open applications (pipeline by stage).
- Partners and Clients totals and active counts, plus the network card. New partners and clients already follow the period.
- The 12-month trend chart is unchanged.

**PDF card:** `OwnerReportPresenter::payoutsDue()` (amount and deal count) gives the Receivables & Payables card the same figure as Needs Attention. Its subtitle is now "Raised :when, still unpaid".

**Labels and definitions changed:**
- Backend `lang/en/owner_summary.php`: attention, owed, receivables, pipeline, stalled, network, payouts and trials subtitles; the definitions paragraph; the cover note; the empty-state text.
- Screen: `NeedsAttention`, `OwedCard`, `PipelineCard`, `StalledDealsCard`, `PartnerNetworkCard`, `KpiCards` ("Active now"), and `Definitions`.
- Unused `TRIAL_ENDING_DAYS` removed from the admin config.

**Behaviour change QA will see:**
- Owed and Needs Attention figures drop to what belongs to the period. On stage, This month will no longer show $142.9K / 57 invoices or $8,232.50 payouts.
- The weekly report covers last week's invoices, payouts and trials.

## Files changed
- fch-api: `app/Services/FCH/Common/OwnerSummary/OwnerSummaryService.php`, `OwnerReportPresenter.php`, `resources/views/pdf/partials/owner-export-cards.blade.php`, `lang/en/owner_summary.php`
- react-fch: `src/pages/owner-summary/components/{NeedsAttention,OwedCard,PipelineCard,StalledDealsCard,PartnerNetworkCard,KpiCards,Definitions}.tsx`, `src/config/ownerSummary.ts`

## Tests
- fch-api, new: `OwnerSummaryTest`
  - `what_is_owed_to_the_host_is_the_periods_invoices_split_by_age_and_failure`
  - `payouts_due_are_those_on_deals_funded_in_the_period`
  - `needs_attention_counts_what_came_in_during_the_period`
- fch-api, also new: the presenter `payoutsDue` unit test, and the PDF card integration test (both reports).
- fch-api, updated: Needs Attention and attention-label expectations.
- Result: Unit, Integration and Feature owner-summary tests plus Console, **137 passed**.
- react-fch:
  - `OwnerSummary.test.tsx` updated, with a new "which cards follow the period and which are as of now" test.
  - Owner summary, auth and layout suites: 187 passed. Typecheck clean.
  - E2E `business-insights.spec.ts`: 20/20 passed after `npm run build`, `--workers=1`.

## Open items
- QA: re-run BI-28 and BI-29. Update the expected figures for the Owed / Needs Attention cases to the period rules above.
- Stalled deals and open applications stay as of now by decision. Dating them would need a different rule, for example deals opened in the period.
