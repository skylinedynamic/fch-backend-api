# OpenAI settings readers (F82, was O22)

**Date:** 2026-10-12
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F82 (was O22)

## Problem
The platform-wide `openai` readers took `->first()` with no partner filter.

## Solution
`AppConfiguration::platform()` (host row, or unowned; never another partner's) used by `OpenAiResponsesService::setting()`, `PreQualificationAgentConfig::find()`, `AiAssistantConfig::find()`, `StoreOpenAiConfigurationRequest::hasStoredApiKey()`. **Still to do:** run O22's production query for stray non-host rows.

## Files changed
- `app/Models/Common/AppConfiguration.php`
- `app/Services/Global/OpenAI/OpenAiResponsesService.php`
- `app/Models/PreQualification/PreQualificationAgentConfig.php`
- `app/Models/Assistant/AiAssistantConfig.php`
- `app/Http/Requests/Api/V1/FCH/Common/Setting/StoreOpenAiConfigurationRequest.php`
- `tests/Unit/Settings/OpenAiSettingsHostScopeTest.php (new)`

## Tests
- `Unit/Settings/OpenAiSettingsHostScopeTest` — written to fail first, then passing.
- Suites run: fch-api full suite — 2246 passed, 42 skipped, 0 failed; real `storage/app` unchanged before/after.

## Open / follow-up
Run on production: `SELECT * FROM app_configurations WHERE module='openai' AND partner_id <> (SELECT id FROM partners WHERE is_host=1);` and delete or move any rows it finds.
