# Pre-qualification (F69, was U1/O3)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F69 (was U1/O3)

## Problem
`PreQualificationInternalAueService` called `OpenAiAgentsService::runStructuredAgent()`, which no branch defined any more (dropped by merge resolutions; `origin/stage` had the same call, so U1's note that stage was consistent no longer held). The internal AUE step failed in production; 18 Integration tests failed.

## Solution
Restored `runStructuredAgent()` and its helpers (`retrieveAgentWithRetries`, `schemaNameFor`, `attemptStructuredAgent`, `stripFileCitations`, `structuredFailure`) from `31e6154f`; every dependency still exists on the parent `OpenAiResponsesService`.

## Files changed
- `app/Services/Global/OpenAI/OpenAiAgentsService.php`

## Tests
- `Integration/PreQualification` (55, incl. the 18) — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None in code. Deploy to production soon: the internal AUE step is broken there until this ships.
