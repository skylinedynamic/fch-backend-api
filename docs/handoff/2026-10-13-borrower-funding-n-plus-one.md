# Borrower funding list: remove N+1 queries (GET /api/v1/borrower/funding)

**Date:** 2026-10-13 · **Branch:** `e2e-missing-tests` (uncommitted; the branch already had unrelated EmbedForm/Appointment changes) · **Found by:** borrower portal E2E, issue E2E-005 in `fch-borrower-react/docs/e2e/ISSUES.md`

## Problem
- `GET /borrower/funding` took ~12s on local `artisan serve`, and ~6.7s even when run in-process.
- One request ran **4,223 queries**:
  - `select exists(... funding_applications where borrower_id = ?)`: 1,659×
  - `select exists(... borrower_documents where borrower_id = ?)`: 1,659×
  - lenders / lender_visibilities lazy loads, plus the readiness-factor queries, about 120× each
- Causes:
  - `LenderVisibility::getEngineStatusForBorrower()` asks two borrower-only questions once for every lender.
  - `FundingSequencing::getEngineStatusAttribute()` is not cached. The service's HIDDEN filter, the resource, `engine_status_label` and `$appends` each re-evaluate it, so it runs about 7 times per sequence.

## Solution
- `Borrower::hasAnyFundingApplication()` / `hasAnyDocument()`: memoized per instance. `LenderVisibility` uses them when it is given a `Borrower` model, and keeps the old query for anything else.
- `FundingSequencing::getEngineStatusAttribute()`: the inactive-sequence and disabled-type checks and the borrower lookup still run on every call. The expensive evaluation (moved into `evaluateEngineStatusForBorrower()`) is cached per instance, keyed by borrower id.
- `FundingService::getGroupedSequencings()`: also eager-loads `lenderVisibilities.lender`.
- Result: **206 queries**. In-process time is 0.8s (was 6.7s); live `artisan serve` is ~2.5-3s (was ~12s).
- The response was diffed against the pre-change output: it is identical apart from `readiness_factors[2].borrower_value`, a time-in-business float computed from `now()`.

## Files changed
- `app/Models/Borrower/Borrower.php`: `hasAnyFundingApplication()`, `hasAnyDocument()` (memoized)
- `app/Models/Funding/LenderVisibility.php`: uses the memoized borrower checks
- `app/Models/Funding/FundingSequencing.php`: engine status cached per instance and borrower
- `app/Services/Borrower/Funding/FundingService.php`: eager-loads `lenderVisibilities.lender`
- `tests/Feature/Funding/BorrowerFundingEngineStatusQueryTest.php` (new, 4 tests)

## Testing
- New test: 4/4 pass. It was red first: 5 queries instead of 1, and 6 queries on a repeated `engine_status` read.
- Full `php artisan test`: 1300 passed, 2 skipped, 18 failed. All 18 failures are in `tests/Integration/PreQualification/*`, and they fail the same way with these four files stashed, so they were already broken.
- Borrower portal E2E: `funding.spec.ts › GET /borrower/funding answers within 8s`.

## Open items
- The cache lives on the model instance. If something changes a sequence's lenders, or the borrower's applications/documents, and then reads `engine_status` from the **same** instance within one request, it will see the old value. Nothing does that today; call `->fresh()` if that is ever needed.
- About 30 readiness queries per sequence remain (`FundingReadinessService` / `FundingQualificationService`: doc-type lookups per sequence). That is the next optimisation if this endpoint needs to get faster.
- The 18 failing PreQualification integration tests are unrelated and need their own look.
