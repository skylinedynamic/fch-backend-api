# QA round 1 - batch 4: plan and status (2026-10-26)

Source: QA defect log (Google Sheet `1YUsdZTfnyG_91-H2IlJwn1qetg16xFqL4vvS8DFGfOM`, gid 499536418), rows BUG-015, 017, 018, 019, 020, 021, 028, 029. None of them appeared in batches 2, 3 or the 26-Oct batch.
Branch in every repo: `bugfix/qa-round1-release-v2`. Nothing is committed.

## Status at a glance

| Bug | Repo | Cause found | Status |
|---|---|---|---|
| BUG-015 | admin + borrower | Typos in `config/funding.ts` status guideline (both apps) | ✅ Done (F152) |
| BUG-017 | admin + borrower (+ backend tests) | Lead form checks only Contact Person (and email/phone) before the request; borrower step 1 relies on the browser's email check | ✅ Lead + step 1 done (F153). Add Client: QA to retest |
| BUG-018 | backend | Already fixed in code (`lead_source` nullable on update since `ec3960281`) | ✅ Regression test (F154) |
| BUG-019 | backend | `subject.required` says "Subject"; `description` required although Message has no * | ✅ Done (F155) |
| BUG-020 | admin | Timeline title uses `event_label` ("Activity Created"); an edited entry shows the edit time | ✅ Done (F156) |
| BUG-021 | backend | Already validated (`BusinessEin`/`DunsNumber` on contact-info) | ✅ Regression test (F157); lead 175 data to fix by hand |
| BUG-028 | backend | My Applications shows `updated_at`, Dashboard shows `created_at` | ✅ Done (F158) |
| BUG-029 | borrower | Typo "matc.hed" in `PreQualification.tsx` | ✅ Done (F159) |

## Plan per bug

### BUG-015: status guideline typos
- `Fch-admin-frontend-react/src/config/funding.ts` and `fch-borrower-react/src/config/funding.ts`:
  - "Admin can reviewing the request." → "Admin is reviewing the request."
  - "Request declined missing information." → "Request declined due to missing information."
- This is also the copy part of BUG-070.
- Test: a unit test per app on the guideline text.

### BUG-017: required errors in two rounds
- **Lead (admin `LeadDetails.jsx`):** `validateForm()` checks only Contact Person (and, on Contact Info, email/phone) and stops the request. The API's other required errors (Probability, Lead Type, Status, Organization Name, Country) only come on the next Save.
  - Fix: drop the client-side gate (as in BUG-038). The API returns every required error in one response, and the form already shows them under the fields.
  - Tests: admin, Lead Details and Contact Info with an empty form send the request and show every API error. Backend, `leads/details` and `leads/{id}/contact-info` empty return every required field at once.
- **Client Pre-Qualification step 1 (borrower `PersonalInfoStep.tsx`):** the `<form>` has no `noValidate`, so the browser blocks Next on an email like "bad@" with its own tooltip. No request is sent and the API's errors never show.
  - Fix: `noValidate`, so the API's messages for every field show at once.
  - Tests: borrower, an empty first name plus "bad@" sends the request and shows both errors. Backend, `pre-qualification/personal-info` returns first name, email and state together.
- **Add Client (admin):** not reproducible in code. The Save button is outside the form, `handleSave` has no client-side gate, and the API returns `enrollment.plan_id` with the other required errors in the first response (pinned by a test). QA to retest. If it still happens, capture the request payload and response.

### BUG-018: Lead Source optional on create, required on update
- `UpdateLeadDetailsRequest` already has `lead_source` nullable, and `lead.lead_source_required` is used by no request.
- Test: create without Lead Source, then update Status without it → 200.

### BUG-019: New Event validation messages
- `StoreActivityLogRequest`:
  - For an activity, `subject.required` says "Summary is required." (`activity_log.summary_required`). An email keeps "Subject is required."
  - `description` is required only for an email. For an activity it is optional, matching the form (no * on Message), and is stored as an empty string since the column is NOT NULL.
- Date: the form fills it with the current time, so an "all empty" form has a date. Clearing it gives "Activity Date is required." (pinned by a test).
- Tests: backend `Feature/ActivityLog`.

### BUG-020: timeline title and date
- `ActivitySidebar.jsx`:
  - A manual activity's title is "Reference: Summary" (as already written for `log_type` activity). The API's `event_label` ("Activity Created") no longer hides it.
  - The date is always the activity's own date. An edited entry keeps it and marks "(Edited)", with the edit time in the tooltip.
- Test: admin `ActivitySidebar` test.

### BUG-021: EIN / DUNS accept invalid values
- Contact Info already rejects them (`BusinessEin`, `DunsNumber`). The values on `/leads/175` were saved before that rule.
- Test: QA's exact values ("12.5", "12") → 422.
- Data: lead 175 still holds the old invalid values. It needs correcting by hand.

### BUG-028: pre-qualification dates differ
- `PreQualificationApplicationResource` `date` = `updated_at ?? created_at`, while the dashboard shows `created_at`.
- Fix: My Applications shows `created_at`, the submission date, like both dashboards. The list order is unchanged.
- Test: backend `MyApplicationsApiTest`.

### BUG-029: "matc.hed"
- `fch-borrower-react/src/pages/prequalification/PreQualification.tsx:261` → "matched".
- Test: borrower page test.

## Done
(filled in as each bug is finished)
