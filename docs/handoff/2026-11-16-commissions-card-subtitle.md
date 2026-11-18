# Funding Commissions subtitle (email and PDF)

2026-11-16

## Problem
The owner report's Funding Commissions section (weekly email and PDF export) said "Booked on deals funded :when; not cash until the lender pays". The section only counts Closed - Completed deals (`OwnerSummaryService::fundedQuery`), and a deal closes once the lender pays, so "not cash until the lender pays" was wrong.

## Solution
`owner_summary.report.commissions_sub` is now **"Commission on deals closed :when"**. The admin panel card got the same wording (`react-fch/docs/handoff/2026-11-16-commissions-card-subtitle.md`).

## Files changed
- `lang/en/owner_summary.php`: `commissions_sub` reworded.
- `tests/Integration/OwnerSummary/WeeklyReportCommandTest.php`: the email must contain the new subtitle and must not contain the old one.

## Testing
- Test changed first: failed (red), as expected.
- After the fix: `tests/Integration/OwnerSummary` + `tests/Unit/OwnerSummary` 68 passed.

## Open items
- None.
