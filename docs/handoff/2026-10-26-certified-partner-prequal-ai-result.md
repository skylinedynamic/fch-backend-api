# Certified Partner sees the pre-qualification AI result (client view)

## Problem
QA: on `intake-review/pre-qualification/{id}` a Certified Partner saw only Personal Info / Business Details / Funding Needs. A White-Label Partner saw a 4th step, **AI Underwriting Result**, in its Client Readiness view. Certified Partners should see the same as WLP.

The block existed in both repos: `PreQualificationRequestResource` set `ai_result` (and `gpt_status`, `gpt_generated_at`, `validation_status`) to null for any non-WLP partner, and `PreQualificationDetails.tsx` hid step 4 unless the viewer was host or WLP.

## Solution
- API: `PreQualificationRequestResource` treats `PARTNER_TYPE_CERTIFIED` like `PARTNER_TYPE_WHITE_LABEL` (`$isPartnerViewer`). Partners get the client AUE only; `internal`, `admin_status_note`, `model` and `latency_ms` are still stripped. Validator summary/issues stay host-only. Viewers with no partner, or an unknown partner type, still get nothing.
- Admin frontend: `PreQualificationDetails.tsx` shows step 4 for White-Label **and** Certified partners. Non-host viewers are already forced into the client view.

## Files
- `app/Http/Resources/FCH/Common/Application/PreQualificationRequestResource.php`
- `Fch-admin-frontend-react/src/pages/applications/pre-qualification/PreQualificationDetails.tsx`
- `docs/test-findings.md` (F151)

## Tests
- New `tests/Feature/Application/PreQualificationAiResultVisibilityTest.php`: certified (failed before the fix), white-label and host visibility.
- `php artisan test tests/Feature/Application`: 42 passed.

## Open
- Re-run Pre-Qualification is still host-only (unchanged, intended).
