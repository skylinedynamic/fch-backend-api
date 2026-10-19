# Verify assistant (F96, was O49)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F96 (was O49)

## Problem
An enabled vector store with zero indexed files only warned and the check passed.

## Solution
It fails the check.

## Files changed
- `app/Console/Commands/VerifyAssistantKnowledge.php`
- `tests/Integration/Console/VerifyAssistantKnowledgeCommandTest.php`

## Tests
- `Integration/Console/VerifyAssistantKnowledgeCommandTest` (the old "only warned" test updated) — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
None.
