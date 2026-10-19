# Pre-qualification agent methods lost in a merge; readiness factors 404; no skipped tests

**Date:** 2026-10-14 · **Branch:** `e2e-missing-tests` (uncommitted) · **Found by:** the full `php artisan test` run, and the borrower-portal E2E (`fch-borrower-react/docs/e2e/ISSUES.md` E2E-020)

## At a glance

| # | Problem | Solution | Files |
|---|---|---|---|
| 1 | `PreQualificationInternalAueService` calls `OpenAiAgentsService::runStructuredAgent()`, which **did not exist**: the merge `06db90a37` ("ai-assistant-134 into stage") dropped it and four helpers. Every pre-qualification underwriting run would end in "Call to undefined method"; 18 tests in `tests/Integration/PreQualification` failed. | The merge was redone with `git merge-file` from both parents (`7432e434` and `5b2090a6`). Its one conflict was two branches adding different methods at the same place; both were kept. Compared with HEAD the result only adds the lost code (0 lines removed). | `app/Services/Global/OpenAI/OpenAiAgentsService.php` (+370) |
| 2 | `GET /common/funding-qualification/{id}/readiness-factors` (and the client variant) answered **500** for an unknown funding id: the service's `findOrFail()` was caught by the generic `catch` and reported as "Failed to evaluate readiness factors". | `ModelNotFoundException` answers 404 "Funding not found." in both actions. | `app/Http/Controllers/Api/V1/FCH/Common/Funding/FundingQualificationController.php`, `tests/Feature/Funding/ReadinessFactorsUnknownFundingTest.php` (new, 2) |
| 3 | `NavigationMapTest` skipped the `/notifications` destinations ("lives on the portals' notifications branches — remove once merged"). Both portals now have the route. | The skip list removed; the two cases run and pass. | `tests/Unit/Assistant/NavigationMapTest.php` |

## 1. Agent methods lost in the merge
- **Problem:** `31e6154f` ("gpt agents prequalification") added `runStructuredAgent()`, `retrieveAgentWithRetries()`, `schemaNameFor()`, `attemptStructuredAgent()`, `stripFileCitations()` and `structuredFailure()`. The other side of `06db90a37` added `stripCitationMarkers()` / `withoutCitationMarkers()` at the same spot; the conflict was resolved by keeping only that side. The caller in `PreQualificationInternalAueService::133` was kept, so the code path is broken at runtime.
- **Verified:** `php artisan test tests/Integration/PreQualification tests/Unit` — 784 passed, 0 failed (18 failed before).
- **Status:** Fixed and verified. **Worth checking:** other files touched by `06db90a37` for the same kind of loss; only this one had tests that showed it.

## 2. Readiness factors for an unknown funding id
- **Verified:** the new test failed first (500), then passed; `tests/Feature/Funding` 6/6. Borrower E2E `sidebar/funding/funding-review.spec.ts` 4/4 (it failed on the 500 before).
- **Status:** Fixed and verified

## 3. Skipped tests
- **Verified:** `tests/Unit/Assistant/NavigationMapTest.php` 84 passed, 0 skipped.
- **Status:** Done
