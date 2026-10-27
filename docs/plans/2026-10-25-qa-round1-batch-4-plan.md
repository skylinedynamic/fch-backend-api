# QA round 1 - batch 4 plan (16 bugs) - detailed

**Date:** 2026-10-25
**Branch:** `bugfix/qa-round1-release-v2` in `fch-backend-api`, `Fch-admin-frontend-react`, `fch-borrower-react`
**Source:** live Google Sheet "Four Corner Regression Test Cases Round1", tab **Defect Log** (exported 2026-10-25), plus each bug's linked test case row
**Bugs:** BUG-022, 053, 060, 068, 074, 082, 087, 094, 103, 108, 112, 114, 115, 121, 122, 125 - all Medium, all assigned to Arafat, all "In Progress"
**Status:** plan only - no code changed yet

How to read each bug below:
- **Sheet** - QA's text from the Defect Log (Module, Title, Steps, Expected, Actual).
- **Why it happens** - the cause found in the code, in plain words, with the file and line.
- **What I will implement** - each change, repo by repo, file by file.
- **Tests** - what will be added, and which test must fail before the fix.
- **How QA retests** - the check after deploy.

---

## Summary

| # | Bug | Portal / module | State today | Work | Decision |
|---|---|---|---|---|---|
| 1 | BUG-022 | Owner - secrets in the page | **Already fixed** (20-Oct) | Retest only | - |
| 2 | BUG-053 | Owner - client delete | **Already fixed** (19-Oct) | Retest only | - |
| 3 | BUG-060 | Client - first AI run fails | Old error no longer in code | Logs first, then fix | - |
| 4 | BUG-068 | Approve funding request - amount | Not built | API + admin + borrower | **D-1** |
| 5 | BUG-074 | Funding application status jumps | Not built | API + admin | **D-2** |
| 6 | BUG-082 | Client opens another borrower's request (**P1**) | Real server hole | API + borrower | - |
| 7 | BUG-087 | Document review status / reason | API ok, UI missing, no bell | API + borrower | **D-3** |
| 8 | BUG-094 | Application PDF changes after signing | Open + 3 security issues | API + admin | **D-4** |
| 9 | BUG-103 | "Everyone" notes hidden from client | Open | API + borrower | **D-5** |
| 10 | BUG-108 | Ownership over 100% | Open | API (2 portals) + borrower | - |
| 11 | BUG-112 | Trial partner adds users | Matches today's rule | Depends on decision | **D-6** |
| 12 | BUG-114 | Certified installment 12 vs 11 months | Open | API + admin | **D-7** |
| 13 | BUG-115 | Certified config guard never shows | Root cause found | API | - |
| 14 | BUG-121 | Custom domain accepts "not a domain" | Open | API + admin | - |
| 15 | BUG-122 | Invoice modal amount | Fixed on `stage`, not on `release` | Retest + date fix | - |
| 16 | BUG-125 | "View leads" role opens 5 pages | Open (UI + API) | API + admin + permissions | **D-8** |

**Order of work:** BUG-082 → BUG-125 → BUG-094 → BUG-115 → BUG-108 → BUG-121 → BUG-087 → BUG-103 → BUG-122 date → (after decisions) BUG-068, 074, 114, 112 → BUG-060 (needs staging logs) → retests for 022 / 053 / 122.

---

## 1. BUG-022 - Secrets sent to the browser

**Sheet**
- **Module:** CMS Site Settings / Settings (Owner) - TC OP-CMS-03
- **Title:** Secrets are sent to the browser in full (reCAPTCHA secret shown in plain text; MicroBilt client secret loaded into the page)
- **Steps:** 1. Owner > CMS > Site Settings: look at reCAPTCHA Secret Key. 2. Owner > Settings > MicroBilt Config: inspect the client secret field
- **Expected:** Secrets masked / not returned once saved (like SMS auth token: "Saved – leave blank to keep")
- **Actual:** reCAPTCHA secret key (40 chars) is a plain text input with the full value. MicroBilt client_secret_production is a password field but its full value (16 chars) is in the page. Twilio token is correctly blank/masked.

**Why it happens:** it no longer happens. QA reported it on 15-Oct; it was fixed on 20-Oct (batch 2, finding F136), and the fix is on this branch:
- The API sends `••••••••` in place of any setting whose key contains `secret`, `token`, `password`, `api_key` or `private_key` (`app/Models/Common/AppConfiguration.php:31-61`). The MicroBilt keys `microbilt_client_secret_sandbox/production` match that pattern.
- Saving a blank value or the mask keeps the stored secret.
- The admin forms use password inputs that start blank, with "Saved - leave blank to keep" (`Fch-admin-frontend-react/src/pages/cms/SiteSettings.tsx:504`, `src/pages/settings/components/MicroBiltConfigForm.tsx`).
- The public website settings never include `recaptcha_secret_key` (`WebsiteSiteSettingsService`).

**What I will implement**
- No functional change.
- Small clean-up: delete `AppConfigurationService::getAll()` (`app/Services/FCH/Common/Setting/AppConfigurationService.php:98`). It returns every setting unmasked and has no caller, so nobody can wire it to a screen later by mistake.

**How QA retests:** open both screens and view the page source / network response. The secret fields should be blank with the placeholder, and the response should contain only `••••••••`. Change another field and save: the secret must still work (reCAPTCHA on the website form, a MicroBilt call).

---

## 2. BUG-053 - Client delete fails with a generic message

**Sheet**
- **Module:** Clients (Owner) - TC OP-CLI-33
- **Title:** Owner cannot delete a client even with no linked data - "Failed to delete borrower(s)."
- **Steps:** 1. Owner > Clients > search QA-TEST Owner Free Client (free plan, no invoices/applications). 2. Select > Delete Selected > Yes, Delete It
- **Expected:** Client deleted (or, if not allowed, a clear reason / suggestion to inactivate)
- **Actual:** POST /api/v1/common/borrowers-multiple-delete returns 400 {"message":"Failed to delete borrower(s)."}. BorrowerService::delete catches an exception from safeDeleteWithRelations() and returns a generic error; the UI never calls check-deletable, so no reason is shown. Same generic message for a client with an invoice (QA-TEST Run1 Client). Wanda (partner) could delete her free-plan client the same way.

**Why it happens:** it no longer happens. It was fixed on 19-Oct (`docs/handoff/2026-10-19-client-delete-paid-invoice-message.md`, F124-F126, O62), and the fix is on this branch:
- The free-plan client failed because the delete called `Subscription::allPhases()`, a method removed together with its table on 31-Aug. That call is gone.
- `BorrowerService::delete()` (`app/Services/FCH/Common/Borrower/BorrowerService.php:851`) checks the whole selection first. A client with a paid invoice gets a 422 with a plain reason and `errors.blocked`.
- The admin portal calls `check-deletable` first and shows "Client Cannot Be Deleted".

**What I will implement:** nothing, unless the retest fails. If it does, the staging log line `Borrower deletion failed` holds the real exception, and I fix from that.

**How QA retests:**
- QA-TEST Owner Free Client: should delete, show a success message, and disappear from the list.
- QA-TEST Run1 Client: if its invoice is Paid, should show "This client has related records and cannot be deleted..."; if the invoice is unpaid, the client and the invoice are deleted.

---

## 3. BUG-060 - First underwriting run fails; UI keeps "Evaluating"

**Sheet**
- **Module:** Pre Qualification (Client, Owner) - TC CP-PREQ-12
- **Title:** First automated underwriting run fails (gpt_status FAILED); UI keeps showing Evaluating for ~1 min after backend already failed
- **Steps:** 1. Client QA-TEST Owner Client Two submits pre-qualification (id 155) with valid data. 2. Watch Evaluating dialog
- **Expected:** Underwriting completes on first run; if it fails, the failure is shown as soon as the backend reports it
- **Actual:** First run returned Failed / gpt_status FAILED within seconds, but dialog kept polling ~60s before "We could not complete the automated review". Try again succeeded. 19-Oct website pre-qual #158: 1st Run AI (model gpt-4o-mini) → FAILED "OpenAI Responses API did not return a result"; re-run (gpt-5.6-luna) → COMPLETED in 54 s. 20-Oct: WL client QA-TEST Wanda Client One failed on first run.

**Why it happens (what is known)**
- The error "OpenAI Responses API did not return a result" **no longer exists in the code**. On 19-Oct (commit `c82d6b30`) the AI part was replaced by one agent configured in Settings > OpenAI Configuration. That agent's model is saved on the OpenAI platform, which is why the first run said gpt-4o-mini and the re-run said gpt-5.6-luna. Staging was at least partly on the older code.
- Today's flow: `PreQualificationService::submit` saves the request and replies right away (status PENDING), then runs `PreQualificationAiService::evaluate` after the response. That calls `OpenAiAgentsService::runStructuredAgent`, which retries up to 3 times on timeouts, bad JSON, schema errors and 408/429/5xx (`config/openai.php`).
- The borrower screen checks every 5 seconds and stops as soon as the status is not PENDING (`fch-borrower-react/src/pages/prequalification/PreQualification.tsx:69-82`). On current code a failure should show within about 5 seconds.
- **Unknown:** why the first run fails. Only the staging logs can show it.

**What I will implement**
1. **Investigate first (no code):** from the staging `storage/logs/laravel.log`, take the lines `FCH Pre-Qualification AUE could not be generated` and `FCH Agent structured run retrying` for pre-quals 155, 158 and Wanda's client. Read `failure_kind`, `http_status` and `error`.
2. **Fix depending on the cause:**
   - `TRANSPORT_ERROR` (timeout on the first call): raise the OpenAI timeout for the agent read and the run in `OpenAiAgentsService`, and make sure the read is retried. That retry code exists (`retrieveAgentWithRetries`); I'll check it is used on the first run.
   - `SCHEMA_VALIDATION_FAILED` / `INVALID_JSON`: the agent's saved schema or instructions on the OpenAI platform don't match. That is a platform setting, not code; I'll write down what to change.
   - API 400 (e.g. a parameter a model refuses): fix how the request body is built for that model.
3. **UI check:** reproduce locally with a faked OpenAI failure (`Http::fake`). If the dialog still keeps "Evaluating" after the API says FAILED, the fault is in the submit flow in `PreQualification.tsx`, and I'll fix it there.

**Tests**
- Integration: `PreQualificationAiService` with the first OpenAI call failing (timeout) and the retry succeeding → status COMPLETED.
- Integration: every attempt fails → status FAILED, and the retry button is offered.
- Borrower Vitest: the poll returns FAILED → the "could not complete" card shows on that poll, not later.

**How QA retests:** submit 3 fresh pre-qualifications (host client and WL client). Each should complete on the first run. If one fails, the failure card should show within about 5 seconds of the API answering FAILED.

---

## 4. BUG-068 - Approve never asks for an estimated amount

**Sheet**
- **Module:** Funding Request (Owner + Client) - TC OP-FREQ-09
- **Title:** Approving a funding request never asks for an estimated amount; client sees the option range as "Estimated Funding"
- **Steps:** 1. Owner > Intake & Review > Funding Request 41 (QA-TEST Owner Client Two, Term Loan $50,000). 2. Status Approved > Confirm. 3. Client dashboard / Funding Requests
- **Expected:** Approve captures an estimated amount; banner and Estimated Amount column show it
- **Test case also expects:** banner "Funding Request has been Approved! Estimated Funding $X"
- **Actual:** Confirm dialog has no amount field; banner says "Estimated Funding: $25,000 - $5,000,000" and Estimated Amount column shows the same full option range

**Why it happens:** the feature was never built. No estimated or approved amount exists anywhere:
- The table has only `requested_amount` (`app/Models/FundingRequest/FundingRequest.php:30-46`).
- Approving saves only status, `approved_at` and `approved_by` (`app/Services/FCH/Common/FundingRequest/FundingRequestService.php:200-204`).
- The borrower banner shows the funding option's whole range, built in `app/Services/Borrower/Dashboard/DashboardService.php:168` (`estimated_funding_range`). The list and detail show `funding_sequence.amount_range` (`fch-borrower-react/src/pages/funding-requests/FundingRequestList.tsx:293`, `FundingRequestDetailPage.tsx:887`).

**What I will implement** (amount rules depend on **D-1**; below assumes one figure, required on Approve)
- **API**
  - New migration: `funding_requests.estimated_amount` - `decimal(15,2)`, nullable.
  - `FundingRequest`: add to `$fillable`, cast `decimal:2`.
  - `UpdateFundingRequestStatusRequest` (`app/Http/Requests/Api/V1/FCH/.../FundingRequest/`): `estimated_amount` is `required_if:status,Approved|numeric|min:1`, plus the bounds D-1 sets (e.g. ≤ the option's maximum). Messages in `lang/en/funding_request.php`.
  - `FundingRequestService::fundingRequestStatusUpdate`: on Approved, save `estimated_amount`; on any other status, leave it untouched.
  - Borrower `DashboardService` (line 168): `estimated_funding_range` becomes the formatted `estimated_amount` when it is set, else the range as today (so requests approved before this change still show something).
  - Borrower funding-request resources (`app/Http/Resources/Borrower/Funding/FundingRequestResource.php`) and the admin resource: add `estimated_amount` and `estimated_amount_formatted`.
  - Approval email `BorrowerFundingRequestApproved`: show the amount when it is set.
- **Admin:** the Approve confirm dialog on Intake & Review > Funding Request gets an "Estimated Amount ($)" input, required when the new status is Approved. The API's 422 message shows under the field.
- **Borrower:** `GlobalFundingBanner.jsx:31`, `FundingRequestList.tsx:293` and `FundingRequestDetailPage.tsx:887` show the amount, with the range as the fallback.

**Tests**
- Feature: approve without an amount → 422; approve with $40,000 → saved and returned; reject → no amount needed.
- Feature: the borrower dashboard / active-funding-requests returns $40,000; an old approved request without an amount still returns the range.
- Admin and borrower component tests for the field and the banner.

**How QA retests:** approve request 41 with $40,000. The client banner should say "Estimated Funding: $40,000", and the list and detail should show $40,000.

---

## 5. BUG-074 - Any status jump allowed on a funding application

**Sheet**
- **Module:** Funding Application (Owner) - TC OP-FAPP-15
- **Title:** Any status jump is allowed without warning (e.g. Denied -> Approved (Final))
- **Steps:** 1. Funding application FUND-0008585 (QA-TEST Strange LLC). 2. Set Denied with note. 3. Immediately set Approved (Final)
- **Expected:** Out-of-order transitions blocked or at least warned
- **Actual:** Both changes saved with the standard confirm only; no lifecycle check. (Closed (Completed) is locked correctly.)

**Why it happens:** `RequestApplicationService::fundingApplicationStatusUpdate()` (`app/Services/FCH/Common/Application/RequestApplicationService.php:427-480`) saves whatever status is sent. Its only special case is Closed (Completed), which saves the funded amount. The status order already exists in one place, `config/funding_application.php` → `statuses.order` (On Hold 1 … Funded 17, Closed (Completed) 18, Denied 19), but nothing checks a move against it. The admin dropdown lists every status (`Fch-admin-frontend-react/src/pages/applications/funding/FundingApplicationDetails.tsx:1258`).

**What I will implement** (rules depend on **D-2**; below assumes "block")
- **API**
  - `config/funding_application.php` → new `statuses.transitions` setting next to `order`:
    - Moving forward is allowed (a higher order number). Skipping ahead is allowed unless D-2 says otherwise.
    - Moving back is allowed only to the listed statuses (e.g. Missing Documents ↔ Underwriting Review / In Verification).
    - Denied is final except for the reopen target D-2 names (e.g. Underwriting Review).
    - Closed (Completed) is final (as today).
  - `FundingApplication::canMoveTo(string $status): bool`, which reads that setting. The status spellings stay in the model constants.
  - `fundingApplicationStatusUpdate()`: if `canMoveTo()` is false → 422 "This application cannot move from Denied to Approved (Final)." (in `lang/en/application.php`), and nothing is saved.
  - The funding application detail resource returns `allowed_next_statuses`.
  - If D-2 is "warn" instead: the move is accepted only with `confirm_out_of_order: true`, and the activity entry says it was out of order.
- **Admin:** the status dropdown offers only `allowed_next_statuses` (others disabled, with a hint), and the 422 message shows if it happens.

**Tests**
- Unit: the transition map (forward ok, allowed step-back ok, Denied → Approved (Final) refused, Closed final).
- Feature: Denied → Approved (Final) → 422 and status unchanged; Submitted → Underwriting Review → 200; checked for host and partner.
- Admin Vitest: the dropdown on a Denied application.

**How QA retests:** FUND-0008585 set to Denied. The dropdown must not offer Approved (Final), and a direct API call must give 422.

---

## 6. BUG-082 - Client can open another borrower's funding request (P1)

**Sheet**
- **Module:** Funding Application (Client) - TC CP-FAPP-19
- **Title:** Client can open the funding application wizard for another borrower's funding request id
- **Steps:** 1. Log in as QA-TEST Owner Client Two. 2. Open /fund-application/40 (request 40 belongs to Michael Chen, Business Credit Builder Cards, Approved)
- **Expected:** Refused / not found (as /funding-requests/40 and /my-applications/funding/84 already do)
- **Actual:** Wizard opens "Start Your Business Funding Application (Business Credit Builder Cards)" with the logged-in client's profile prefilled and Save & Next enabled. Save was NOT tried - server-side check unknown; at minimum the other borrower's sequence name leaks and the UI does not block.

**Why it happens (confirmed in code)**
- The wizard sends the request id as `funding_request_id` / `fundingRequestId` (`fch-borrower-react/src/pages/fundapplication/FundApplication.tsx:205`).
- **Reading:** `FundingApplicationService::getFundingInfo()` looks for *this borrower's* application on request 40. It finds none and returns the borrower. Then `FundingRequestResource` loads request 40 **without checking whose it is** (`app/Http/Resources/Borrower/FundApplication/FundingRequestResource.php:38`) and sends its funding option name. That is the leak.
- **Saving:** `FundingApplicationService::saveFundingApplication()` also loads the request **without checking the borrower** (`app/Services/Borrower/FundApplication/FundingApplicationService.php:138`). So Save & Next would create an application for Client Two **attached to Michael Chen's request 40**. The server does not block it.
- The partner-portal copy (`app/Services/FCH/Common/PartnerFundApplication/PartnerFundingApplicationService.php:162`) has the same unscoped load. Its earlier lookups use `visibleTo` (lines 65, 117).

**What I will implement**
- **API (borrower)**
  - One private helper in `FundingApplicationService`, e.g. `ownFundingRequest(?int $id): ?FundingRequest`: `FundingRequest::where('borrower_id', auth()->user()->entity_id)->find($id)`.
  - `getFundingInfo()`: when a request id is given and it is not this borrower's → throw `ModelNotFoundException(__('borrower.funding_request_not_found'))`.
  - `saveFundingApplication()`: the same check before anything is written; line 138 uses the helper.
  - `FundingRequestResource.php:38`: the fallback `find` also gets `where('borrower_id', …)`. Line 76, which looks up a funding *sequence* by a *request* id, is removed (wrong id type).
  - `FundingApplicationController`: all 14 get/save methods currently turn every exception into a 500. Add `catch (ModelNotFoundException $e)` → `ApiResponse::notFound(...)` (404), in the same style as the existing 423 catch.
- **API (partner portal):** `PartnerFundingApplicationService.php:162` uses `FundingRequest::visibleTo(auth()->user())`, and a request outside the partner's reach → 404 the same way.
- **Borrower:** in `FundApplication.tsx`, when the request load (`useFundingRequest`) answers 404 → toast "Funding request not found" and redirect to `/funding-requests`, the same as `/funding-requests/40` does today. Nothing of the wizard is shown.

**Tests** (with a `Defect:` docblock; written first and seen failing)
- Feature: Client Two GET every wizard step with request 40 → 404, and the body has no "Business Credit Builder Cards".
- Feature: Client Two POST business-info / funding-request with request 40 → 404, and **no `funding_applications` row** is created.
- Feature: Client Two with their own approved request → still 200 (no regression).
- Feature: partner portal - partner A with partner B's borrower's request → 404.
- Borrower Vitest: 404 → redirect.

**How QA retests:** as Client Two open `/fund-application/40` → "not found" and back to Funding Requests. Their own request still opens.

---

## 7. BUG-087 - Document rejection reason / status not shown to client

**Sheet**
- **Module:** Documents (Client) - TC CP-DOC-05
- **Title:** Document rejection reason not shown to client; Documents page shows no review status
- **Steps:** Admin > Clients > QA-TEST Owner Client Two > Documents > Business Bank Statements row > Mark as Rejected with reason; Driver License > Mark as Accepted. Client > Documents
- **Expected:** Client sees Rejected badge and the reason (modal says the reason is shared with the borrower); Accepted badge on the other
- **Actual:** Admin shows Rejected/Accepted. Client Documents page shows the same green tick for both (no badge, no reason). Funding Request Details (request 42) does show Accepted/Rejected labels, but the rejection reason is not shown anywhere to the client; no new notification (bell stays 26)

**Why it happens**
- The API already sends each document's `status` and `rejection_reason` to the client (`app/Http/Resources/Borrower/Document/DocumentResource.php:23`).
- The client Documents page ignores them and shows a green tick for any uploaded file (`fch-borrower-react/src/pages/documents/components/DocumentSection.tsx:84`).
- Funding Request Details gets the reason (`app/Http/Resources/Borrower/Funding/FundingRequestResource.php:47`) but doesn't print it (`FundingRequestModal.tsx`).
- The admin update (`BorrowerDocumentService::updateStatus`, tenant-scoped) sends no notification.

**What I will implement**
- **Borrower**
  - `DocumentSection.tsx`: per file, a badge - **Pending review** (grey), **Accepted** (green), **Rejected** (red). Under a rejected file: "Reason: …" and a hint to upload a new file.
  - `FundingRequestModal.tsx` / request details: the reason under the Rejected label.
- **API**
  - `config/notifications.php`: new type `borrower.document.reviewed` (category `document`, title "Document {{status}}", body "Your {{document}} was {{status}}." plus the reason when rejected, url `/documents`). Channels per **D-3** (bell only, or bell + mail).
  - `BorrowerDocumentService::updateStatus`: after saving an Accepted/Rejected change, `app(Notifier::class)->send('borrower.document.reviewed', $borrower, [...])`, the way `FundingRequestService` does at line 259. A failed notification never fails the status change (the Notifier already swallows it).
  - If D-3 includes mail: a new Mailable `BorrowerDocumentReviewed` with the partner's branding (`MailableBranding`).
- Side clean-up: `App\Services\Borrower\Document\DocumentService::updateStatus()` uses an unscoped `BorrowerDocument::find`. No route calls it today, so I'll remove it or record it as O#.

**Tests**
- Feature: reject with a reason → the borrower's document list returns `Rejected` + reason, and the borrower gets one bell notification; accept → `Accepted`, the reason is cleared.
- Borrower Vitest: the badges and the reason line.

**How QA retests:** repeat the steps. The client sees a red Rejected badge with the reason on Bank Statements, a green Accepted badge on Driver License, and the bell count goes up.

---

## 8. BUG-094 - Application PDF rebuilt from the current profile

**Sheet**
- **Module:** Funding Application (Owner) - TC OP-FAPP-20
- **Title:** Downloaded application PDF is rebuilt from the current profile, not the submitted/signed application
- **Steps:** Client submitted+signed application 86 with business address 400 QA Biz Ave; later address change to 401 approved. Admin > Funding Application 86 > Download > Download Application
- **Expected:** PDF shows the data as submitted and signed (400 QA Biz Ave; time in business at submission)
- **Actual:** PDF shows Business Address 401 QA Biz Ave and Time in Business 5 Years 19 Days, while the screen shows 400 QA Biz Ave - the signed document changes after signing. (PDF also prints full SSN and EIN unmasked.) The ZIP password is sent in the URL query string (download-zip?password=...), so it lands in server/proxy logs. Re-checked 21-Oct with the same result.

**Why it happens**
- `FundingApplicationDocumentService::getBorrowerBusinessInfo()` (`app/Services/FCH/Common/Application/FundingApplicationDocumentService.php:545-610`) takes every field **from the live borrower profile first** and from the application only when the profile is empty. After the address change approval, the profile says 401, so the PDF says 401.
- Time in business is computed from **today** (`calculateTimeInBusiness($rawStartDate)`, line 562), so it grows every day.
- At signing, a frozen PDF is already saved (`generateAndLockFinalDocuments`, lines 43-104, path in `signature_data.signed_application_path`), but the Download buttons never use it. They render a new PDF each time (`downloadApplicationPdf` :106, `downloadZip` :256).
- The password travels in the URL: `?password=` read by `$request->query('password')` (`RequestApplicationController.php:152, 165, 178`), and the admin builds that URL (`Fch-admin-frontend-react/src/modules/application/services.ts:83, 106, 136`).

**Also found while reading (security, same code)**
- **The password is saved in plain text** in the download history: `'details' => ['password' => $password, …]` (lines 168, 246, 361).
- **Every downloaded PDF is also saved on the `public` disk** at a guessable path: `funding-applications/{id}/downloads/Funding_Application_{number}.pdf` (line 145). The public disk is served under `/storage/...`, so a PDF downloaded without a password (full SSN) can be opened by anyone who guesses the URL. The signed copies (`…/signed/v{n}-{uuid}/`) sit on the same public disk.

**What I will implement**
- **API - what the PDF shows**
  - `getBorrowerBusinessInfo()`: reverse the order. **The application's own submitted field first**; the profile only for a field the application doesn't have (older applications).
  - Time in business is computed **at the signing date** (the signed time in `signature_data`, else `submitted_at`, else today).
  - For a signed application, Download Application serves the **frozen signed PDF** from `signature_data.signed_application_path` (and the ZIP uses it too). The PDF is rendered again only for an application that has never been signed. With a password, the PDF is rendered from the same frozen data and encrypted, because an existing PDF can't be encrypted afterwards with dompdf.
- **API - password and files**
  - New `POST` routes for download-application / download-signature / download-zip that take `password` in the request body. The old GET routes keep working without a password; a `password` in the query string is ignored.
  - Stop saving the password: `details` keeps only `with_password: true/false` (the action already says `downloaded_with_password`).
  - New migration that removes `password` from the `details` of existing `download_histories` rows.
  - Downloaded and signed PDFs move to the private `local` disk; `downloadFromHistory()` reads from there. A migration moves the existing files and updates their stored paths. **This last step is bigger** - I will confirm with you before doing it in this batch.
- **SSN / EIN in the PDF:** waits for **D-4**. If masked: `ssnForDocument()` returns `XXX-XX-1234`, and the EIN `XX-XXX1234`.
- **Admin:** `services.ts` downloads call the new POST routes with `{ password }` in the body (blob response, as today).

**Tests**
- Integration: sign with 400 QA Biz Ave → change the profile to 401 → the downloaded PDF data still shows 400, and time in business is as of the signing date.
- Integration: download with a password → no `password` key in `download_histories.details`.
- Feature: POST download with a password → 200 encrypted file; GET with `?password=` → the password is not used.
- Feature (if the disk move is done): the file is not reachable under `/storage/`.

**How QA retests:** application 86 → Download Application → 400 QA Biz Ave and the time in business at signing. Download ZIP with a password → the network tab shows no password in the URL.

---

## 9. BUG-103 - "Everyone" activity notes never shown to the client

**Sheet**
- **Module:** Activity (Client) - TC E2E-20
- **Title:** Activity notes with visibility "Everyone" are never shown to the client
- **Steps:** Owner > Clients > (144 host client and 141 Wanda client) > New Event x3: Private, Internal, Everyone. Client portal: the only activity view is My Applications > funding application detail (BorrowerActivitySidebar); also checked dashboard
- **Expected:** Client sees the Everyone notes (only those)
- **Actual:** Client application activity lists only application status changes; the Everyone note (19-Oct 07:55 PM, after the listed status events) is missing; no other activity view exists on the client portal. Partner side OK: Wanda sees only the Everyone note on its client 141 (Private and Internal hidden). Re-checked 21-Oct 06:31: host created an Everyone (Including Borrower) activity on client 144 (and 06:18 on client 150); the client's application Activity panel still shows only the three status events. Evidence b (client view): https://drive.google.com/file/d/1SsOxCoamEhvox7lisUsnsRtGJ5emvsVH/view

**Why it happens**
- A note added on the client's page is saved **about the client** (subject = Borrower).
- The only client-portal activity view, `BorrowerActivitySidebar`, asks only for one application's entries (`funding_application_id`) (`fch-borrower-react/src/components/Common/BorrowerActivitySidebar.tsx:23`). `BorrowerActivityLogService::list()` then keeps only entries about that application (`app/Services/Borrower/ActivityLog/BorrowerActivityLogService.php:89-94`). So a note about the client can never appear.
- Visibility itself is right: `ActivityLog::scopeVisibleTo()` gives a borrower only "Everyone" entries **about themselves** (`app/Models/ActivityLog/ActivityLog.php:410-421`).

**What I will implement** (where it shows depends on **D-5**; below does both)
- **API:** `BorrowerActivityLogService::list()` takes a new option `include_client_notes`. With it, the application filter becomes "about this application **or** about me (the borrower)". Visibility still comes from `visibleTo`, so Private and Internal notes stay hidden. Validated in the list request (boolean).
- **Borrower**
  - `BorrowerActivitySidebar` sends `include_client_notes: true`, so the application's Activity panel shows status changes plus the client's Everyone notes, newest first.
  - If D-5 asks for it: a new "Activity" page (menu item) that lists all of the client's visible entries (no filter), using the same service call.

**Tests**
- Feature: Private + Internal + Everyone notes on client 144 → the client gets only the Everyone note (plus status events); client 150 gets none of 144's notes; without `include_client_notes` the old behaviour holds.
- Borrower Vitest: the sidebar renders a client-level note.

**How QA retests:** create the three notes on client 144 → in the client portal the application Activity panel shows the Everyone note only.

---

## 10. BUG-108 - Total ownership over 100% accepted

**Sheet**
- **Module:** Funding Application - Ownership (Client) - TC CP-FAPP-05
- **Title:** Total ownership over 100% accepted (owner 100% + co-borrower 50%)
- **Steps:** 1. Client > My Applications > application 88 > Edit (fund-application/42) > step 2 Ownership. 2. Owner Ownership % = 100. 3. Has Co-Borrower > Add Co-Borrower with Ownership 50% (all required fields + SSN) > + Add Co-Borrower. 4. Save & Next
- **Expected:** Refused: total ownership of owner + co-borrowers must not exceed 100% (TC: 0-100, total ≤ 100)
- **Actual:** "Co-borrower added" and Save & Next moves to step 3 with total 150%; no warning. Co-borrower edit then shows the full SSN in plain text; Remove deletes without a confirmation.

**Why it happens**
- The owner's `ownership` rule is only `required|numeric`, with no 0-100 range (`app/Http/Requests/Api/V1/Borrower/FundApplication/StoreOwnershipInfoRequest.php:74`).
- Each co-borrower is checked alone, 0-100 (`app/Http/Requests/Api/V1/Borrower/MyApplication/CoBorrowerValidationRequest.php:46`). **Nothing adds owner + co-borrowers together.**
- The partner-portal copies have the same gap (`app/Http/Requests/Api/V1/FCH/Partner/FundApplication/StoreOwnershipInfoRequest.php`, `CoBorrowerValidationRequest.php`).
- Borrower UI: the co-borrower SSN field is a plain text input (`fch-borrower-react/src/pages/fundapplication/components/shared/CoBorrowerModal.tsx:271-274`), and Remove has no confirmation (`OwnershipInformationStep.tsx`).

**What I will implement**
- **API (borrower and partner portal, same change in both)**
  - `StoreOwnershipInfoRequest`: `ownership` → `required|numeric|min:0|max:100`. In the existing `after()` hook: owner % + the sum of `co_borrowers.*.ownership` must be ≤ 100. Otherwise an error on `ownership`: "Total ownership of the owner and co-borrowers cannot exceed 100% (now 150%)." Messages in `lang/en/borrower_validation.php`.
  - `CoBorrowerValidationRequest` (the check when "+ Add Co-Borrower" is clicked): new optional field `other_ownership_total` (owner % + the other co-borrowers, not counting the one being edited). The rule becomes ownership ≤ 100 − that total.
- **Borrower**
  - `CoBorrowerModal.tsx`: send `other_ownership_total`; show the remaining % next to the field ("Up to 0% left").
  - `OwnershipInformationStep.tsx`: the same total check before Save & Next, so the user sees it without a round trip.
  - Co-borrower SSN field: hidden by default (dots) with a Show/Hide button. The value itself is unchanged, since the borrower may see their own data (SSN decision of 12-19 Oct).
  - Remove co-borrower: a "Remove this co-borrower?" confirmation first.

**Tests**
- Unit: the total rule.
- Feature (both portals): 100 + 50 → 422 with the message; 60 + 40 → 200; owner 120 → 422; co-borrower validation with `other_ownership_total` 100 and 50 → 422.
- Borrower Vitest: SSN hidden by default, the remove confirmation, the total message.

**How QA retests:** the original steps → refused with the total message. 60% + 40% → saved.

---

## 11. BUG-112 - Free-trial partner not stopped from adding users

**Sheet**
- **Module:** Users & Seats (Partner) - TC PP-USR-14
- **Title:** Free-trial partner is not stopped from adding users (no "Paid Version Only" modal)
- **Steps:** 1. Log in as free-trial WL partner QA-TEST Trial Partner Co (trial ends 20 Nov 2026). 2. Users > + Add User
- **Expected:** "Paid Version Only" modal; user creation is a paid feature.
- **Actual:** Add User form opens with Employee / Name / Email / Phone / Role / Password fields; no trial message.

**Why it happens:** the code follows a different rule from QA's. The "Paid Version Only" modal exists (`PaidVersionOnlyModal`, rule in `Fch-admin-frontend-react/src/utils/seatLimit.ts:37-53`, 23-Oct). Today a trial partner **may add users up to the seat limit (default 3)**, and the modal appears only at the limit, with the text "You can only add N users in Free trial". QA's trial partner was under the limit, so the form opened. QA's expectation is that a trial partner cannot add any users. That is a product rule, so **D-6**.
- Separate API gap (open since the 23-Oct handoff): `UserService::create()` (`app/Services/FCH/Common/User/UserService.php:58-76`) and `EmployeeService::create()` (around line 144-163) skip the seat limit when the request says `is_paid: true`, even for a trial partner. A trial partner could get extra seats without paying by calling the API directly.

**What I will implement**
- **Either way (API):** in `UserService::create()` and `EmployeeService::create()`, a trial partner (`is_free_trail` on its subscription, not host) cannot use `is_paid`. That request → 403 "This feature is available in the paid version."
- **If D-6 = "trial gets no added users":**
  - `seatLimit.ts`: for a trial partner, return `free_trial` straight away (seat count ignored), so Users > + Add User and Employees > Create account both open `PaidVersionOnlyModal`.
  - API: `UserService::create()` / `EmployeeService::create()` refuse any new login for a trial partner (403).
- **If D-6 = "up to the seat limit" (today):** no UI change. I'll tell QA it works as designed and should be retested at the limit (4th user → modal).

**Tests**
- Feature: trial partner + `is_paid: true` → 403; trial rule per D-6.
- Admin Vitest: `seatLimit` for a trial partner per D-6.

**How QA retests:** per D-6 - either the modal on the first + Add User, or the modal on the 4th user.

---

## 12. BUG-114 - Certified installment calculator defaults to 12 months

**Sheet**
- **Module:** Partners - Create Partner (Owner) - TC OP-PART-34
- **Title:** Certified installment calculator defaults to 12 months ($458.06) instead of the plan's 11 x $499.70
- **Steps:** 1. Admin > Partners > Add Partner. 2. Partner Type = Certified Partner. 3. Enrollment Type = Installment (open the calculator)
- **Expected:** Plan 'Certified Partner - Installment': 11 installments of $499.70 = $5,496.70 (config/enrollment.php, Plan & Fee) + $299 platform fee.
- **Actual:** Total $5,496.70 but INSTALLMENT DURATION 12 Months, MONTHLY INSTALLMENT $458.06; calculator modal also defaults to 12 months. Partner would be invoiced a different schedule than the plan.

**Why it happens:** two places disagree.
- `config/enrollment.php` says 11 × 499.70, **but no code reads that file**. It is reference only.
- The calculator reads the plan from the database (`subscription_plans`). `ModifySubscriptionPlansSeeder` set the installment plans' `total_installment_count_upto` to **24**. `SubscriptionPlanResource` then sends `installment_default_months = min(24, 12) = 12` (`app/Http/Resources/FCH/Common/SubscriptionPlan/SubscriptionPlanResource.php:41-43`).
- The admin also falls back to 12 in several places (`PartnerInformationSection.jsx:841, 1042`; `InstallmentDetailsModal.jsx` defaults `= 12`).
- $5,496.70 / 12 = $458.06. The partner is then invoiced 12 × $458.06, because the chosen months go into `installment_months`. The White-Label plan has the same issue (11 × $699.70).

**What I will implement** (depends on **D-7**)
- **If the plan is fixed 11 installments:**
  - API: new migration that sets `total_installment_count_upto = 11` on the Certified and WL installment plans. `SubscriptionPlanResource`: default = max = that count (no 12 cap), min = the same count.
  - Admin: no hard-coded 12. The default comes from `installment_default_months`, and the months input is fixed when min = max. The partner, client-convert and lead-convert screens all use the same modal.
  - Server check: an installment partner create/update with `installment_months` ≠ 11 → 422.
- **If the plan is flexible 2-24 with 11 as the default:**
  - API: new `subscription_plans.installment_default_months` column (or the default taken from the plan's installment count = 11). The resource sends 11.
  - Admin: the fallbacks use the plan's value. Plan & Fee and the config comment say "default 11, 2-24 allowed".

**Tests**
- Feature: the plans endpoint returns default 11 for Certified and WL installment.
- Admin Vitest: the calculator opens at 11 months / $499.70 for Certified (and $699.70 for WL).

**How QA retests:** Add Partner > Certified > Installment → 11 months, $499.70; the created partner's schedule has 11 invoices of $499.70.

---

## 13. BUG-115 - Certified partner with all gateways Inactive not stopped

**Sheet**
- **Module:** Configuration guard - Certified (Partner) - TC PP-CERT-04
- **Title:** Certified partner with every payment gateway Inactive is not stopped by Configuration Incomplete
- **Steps:** 1. Log in as QA-TEST Cert Partner (qatestcertpartnerco). 2. Settings > Payment Integration: all six gateways Inactive. 3. Open Clients > Add Client and Leads > create
- **Expected:** Configuration Incomplete modal listing 'Payment Integration' (ConfigurationGuardModal: Certified + payment missing)
- **Actual:** No modal; the client form saves (QA-TEST Cert Client One created) and its $2,497 enrollment invoice has no gateway to be paid with. Re-checked 21-Oct: no modal for Certified, while the WL partner gets it in the same state.

**Why it happens (confirmed)**
- The modal (`Fch-admin-frontend-react/src/components/Common/ConfigurationGuardModal.tsx:31-46`) is correct: Certified + `is_fresh_payment` not true → show.
- The flag is computed in `CommonController::getTheme()` (`app/Http/Controllers/Api/V1/FCH/Common/CommonController.php:88-111`) for the partner returned by `getConfigPartner()`.
- `getConfigPartner()` (`app/Helpers/helpers.php:232-241`) returns the **host** for anyone who is not host or White-Label. That is correct for branding, because a Certified partner trades under the host's brand.
- So for a Certified partner the flag checks **the host's gateways**, which are always active, and the modal never appears.
- The gateways the Certified partner switches off are saved under its own partner id (`ResolvesConfigPartner::configPartnerId` → the user's `partner_id`), so the right data exists; the flag just reads the wrong partner.

**What I will implement**
- **API:** in `getTheme()`, keep `getConfigPartner()` for branding only. The payment flag uses the signed-in user's own partner: `PartnerPaymentProvider::where('partner_id', auth()->user()->partner_id)->where('status', true)->exists()`. Host and WL results stay the same; only Certified changes.
- **API (recommended, same batch):** the same check on the server for **client create** and **lead create** by a Certified/WL partner: no active gateway → 422 "Set up Payment Integration before adding clients." This stops a direct API call or an old browser tab from creating unpayable invoices. Host is never blocked.

**Tests** (with a `Defect:` docblock; written first and seen failing)
- Feature: Certified partner, all providers inactive → `status_flags.is_fresh_payment` is null; one active → true; WL and host unchanged.
- Feature: Certified with none active → client create 422; with one active → 201.

**How QA retests:** the original steps → the modal lists Payment Integration; switch Cash back on → the modal is gone and Add Client works.

---

## 14. BUG-121 - Custom Domain saves an invalid borrower domain

**Sheet**
- **Module:** Settings - Custom Domain (Partner) - TC PP-SET-07
- **Title:** Custom Domain saves an invalid borrower domain with custom URL enabled and gives no validation or success message
- **Steps:** 1. WL partner (QA-TEST WL Partner Co) > Settings > Custom Domain. 2. Turn on Enable Custom Domain; leave Borrower Application Domain empty > Update Configuration. 3. Type 'not a domain' as Borrower Application Domain > Update Configuration. 4. Reload the page
- **Expected:** Step 2 and 3 refused with a field error (domain required / must be a valid host name); a successful save shows a confirmation.
- **Test case also expects:** saved; DNS instructions shown; staff user cannot hijack another partner's domain
- **Actual:** No message in either case; step 3 posts to /common/partner/tenant-config (200) and after reload the toggle is on with 'not a domain' stored as the borrower domain. Borrower links/mail for that partner would use the bad host. Reproduced again 21-Oct 05:00 for the recording; restored (toggle off, field cleared) afterwards. GIF of the steps: https://drive.google.com/file/d/1MsS9tIvH2EijYjss7q5uqo9Buz2AoXP-/view

**Why it happens**
- `UpdateTenantConfigRequest` (`app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdateTenantConfigRequest.php`) checks required, string, max 255, restricted names and uniqueness, but **never checks that the value is a host name**. So "not a domain" passes.
- Line 24 works out "this partner" as `$this->id ?: auth()->user()->entity_id`. On the partner-portal route there is no `{id}`, so it uses `entity_id`. For a staff user that is the Employee id, not the partner id. The uniqueness check then excludes the wrong partner, and the partner can collide with itself or another partner. (The controller itself correctly uses `partner_id`.)
- Uniqueness only compares partner-domain with partner-domain and borrower-domain with borrower-domain. One partner's borrower domain can equal another partner's portal domain.
- Step 2 should already give 422 (`requiredIf`). The admin page `TenantDomainConfig.jsx:61-85` has success and error toasts and `ToastContainer` is mounted, so "no message at all" has to be reproduced. Staging may have served an older bundle.

**What I will implement**
- **API**
  - New rule `app/Rules/HostName.php`, in the same style as the other shared rules (`Ssn`, `UsPostalCode`). It accepts lower-case labels separated by dots, letters/digits/hyphen, no leading/trailing hyphen, a TLD of 2+ letters, max 253. It refuses spaces, scheme, path and port. The value is normalised first (strip `http(s)://`, `www.`, trailing `/`, lower-case), and the normalised value is what gets saved.
  - `UpdateTenantConfigRequest`: `HostName` on `partner_domain` and `borrower_domain`. Partner id = `$this->route('id') ?: auth()->user()->partner_id`. Uniqueness across both columns of all other partners. A partner's two domains must differ. Messages in `lang/en/partner.php`.
- **Admin:** run steps 2 and 3 locally against the fixed API and check the field errors and the success toast in `TenantDomainConfig.jsx`. If no message shows, fix it there (e.g. the error shape or the toast call).

**Tests**
- Unit: `HostName` (valid: `apply.wanda.com`; invalid: `not a domain`, `http://x.com/path`, `-a.com`, `a`).
- Feature: toggle on + empty → 422; "not a domain" → 422 and nothing saved; valid → 200 and saved; another partner's borrower domain → 422; staff user of partner A saving A's own existing domain → 200.
- Admin Vitest: a 422 shows the field error and toast; 200 shows the success toast.

**How QA retests:** the original steps → step 2 "Borrower domain is required", step 3 "Enter a valid domain, e.g. apply.yourcompany.com"; a valid domain → "Domain configuration updated successfully", and it is kept after reload.

---

## 15. BUG-122 - Invoice detail modal shows the full enrollment amount

**Sheet**
- **Module:** Invoices - details modal (Owner) - TC OP-INV-08
- **Title:** Invoice detail modal shows the full enrollment amount instead of the invoice amount for an installment down-payment invoice
- **Steps:** 1. Host creates a WL partner on Installment with a $1,000 down payment (QA-TEST WL Install Co). 2. Admin > Invoices > INV-20261020009 (Down Payment, $1,000.00, Unpaid) > View Details. 3. Compare with Download (PDF)
- **Expected:** Modal shows the same invoice: Installment $1,000.00, Sub Total $1,000.00, Total Amount $1,000.00, date 20/21 Oct.
- **Actual:** Modal shows Installment $7,696.70, Sub Total $7,696.70, TOTAL AMOUNT $7,696.70 (the whole enrollment) and date 21 Oct, while the list and the PDF say $1,000.00 and 20 Oct. The partner would read the wrong amount due.

**Why it happens**
- **Amount:** already fixed in the code. The modal used to prefer the subscription's total ($7,696.70). Since commit `73dbec0d` (14-Oct) it uses the invoice's own `payment_amount` (`Fch-admin-frontend-react/src/pages/invoices/components/InvoicePreviewModal.jsx:181-185`). The API returns the invoice's own amount (`app/Http/Resources/FCH/Invoice/InvoiceDetailResource.php:35`). The fix is on `origin/stage` and `origin/master` but **not on `origin/release`**, so the build QA saw on 21-Oct did not include it.
- **Date:** the modal turns `created_at` ("2026-10-20") into a date with `new Date(...)`. That reads it as midnight UTC, so the day can shift in some timezones. The list and PDF show the plain date. Same family as O68.

**What I will implement**
- First confirm which admin build staging served on 21-Oct (deploy log / bundle date). If it was older than `73dbec0d`, the amount needs only a retest.
- If it still shows $7,696.70 on the current stage build: compare INV-20261020009's `/common/invoices/{id}` response with the modal and fix the field it reads.
- **Date fix (either way):** `InvoicePreviewModal.jsx` formats `created_at` with the existing `parseDateOnly` helper (as `subscriptionUtils.ts` does), so it shows the same day as the list and PDF.

**Tests**
- Admin Vitest: a down-payment invoice whose subscription total is 7,696.70 shows 1,000.00 in Installment / Sub Total / Total.
- Admin Vitest: "2026-10-20" renders as 20 Oct 2026 with the timezone set to UTC-5 and UTC+6.

**How QA retests:** INV-20261020009 > View Details → $1,000.00 everywhere and 20 Oct, the same as the list and the PDF.

---

## 16. BUG-125 - "View leads only" role can open Lenders / Credit Builder / Capital Access

**Sheet**
- **Module:** Roles & Permissions - route guards (Owner) - TC OP-GLOB-09
- **Title:** User with only a "view leads" role can open Lenders, Credit Builder (Manage Tier, Verification Center, Credit Accounts) and Capital Access by URL, with full data and Add buttons
- **Steps:** 1. Sign in on admin.skylinedynamic.dev as QA-TEST Host Staff (role QA View Leads Only). 2. /partners, /clients, /commissions, /invoices, /users, /employees, /roles-permissions, /settings/payment-integration, /cms/site-settings, /intake-review/pre-qualification → 403 Access Denied (correct). 3. Now type /lenders, /tiers, /verification-center, /credit-accounts, /capital-access
- **Expected:** Every page the role has no permission for goes to the 403 Access Denied page, and its data is not loaded
- **Actual:** The five pages open normally with live data (all lenders with contacts and phones + Add Lender; 5 tiers; Verification Center with borrower names and pending requests; all credit accounts with Create; Capital Access programs). The API returns the data too. Write actions were not tried. Related: BUG-097.

**Why it happens**
- **Admin:** those pages (and `/funding`, Funding Sequence) are wrapped only in `<HostRoute>`, which checks that the user belongs to the host, not a permission (`Fch-admin-frontend-react/src/App.jsx:284-305`). The pages that correctly gave 403 use `<PermissionRoute permission="...">`.
- **API:** the routes check only "logged in" and "admin user", with no `permission:` middleware:
  - credit-builder tiers / tier-tasks / vendors / credit-accounts, lenders, product categories, funding-types, funding-groups, funding-sequencings (`routes/admin.php:32-160`);
  - Verification Center and Capital Access CRUD (`routes/common.php:330-346`, `host-only` only).
- Permissions already defined in `config/permissions.php`: `view/update tier`, `view/update verification request`, `view/create/update/delete credit accounts`, `view/create/update/delete lenders`, `view/create/update/delete funding`. **No Capital Access permission exists.**

**What I will implement**
- **API - routes** (same `->middleware('permission:...')` style already used in `routes/common.php`):

  | Area | Reads (list / show) | Writes |
  |---|---|---|
  | Tiers, tier tasks, tier-related API | `view tier` | `update tier` |
  | Verification Center | `view verification request` | `update verification request` (status update, `tasks/update-status`) |
  | Credit accounts (incl. `credit-accounts/all`) | `view credit accounts` | `create` / `update` / `delete credit accounts` |
  | Lenders, product categories | `view lenders` | `create` / `update` / `delete lenders` |
  | Funding sequences, funding types, funding groups | `view funding` | `create` / `update` / `delete funding` |
  | Capital Access | `view capital access` (new) | `create` / `update` / `delete capital access` (new) |

  Lists other screens need for dropdowns (e.g. `lenders/get/all`, `credit-accounts/all` used by the Capital Access builder) are checked so they still work for the roles that use those screens.
- **API - new permissions** (needs **D-8**):
  - `config/permissions.php`: new module `capital access` with `view/create/update/delete capital access` (`is_host` 1, `is_certified` 0).
  - New `CapitalAccessPermissionSeeder`, a copy of the `LenderFundingPermissionSeeder` pattern: creates the permissions and gives them to the host roles that have `view credit accounts` today, so current users keep access.
  - **Deploys run only `migrate`, not seeders.** After deploy, `php artisan db:seed --class=CapitalAccessPermissionSeeder --force` has to be run on stage and production. This will be in the handoff.
- **Admin**
  - `App.jsx`: inside `<HostRoute>`, wrap each route in `<PermissionRoute permission="...">` with the names above (`/tiers` → `view tier`, `/verification-center/*` → `view verification request`, `/credit-accounts` → `view credit accounts`, `/capital-access` → `view capital access`, `/lenders` → `view lenders`, `/funding/*` → `view funding`). The `create` / `:id` routes need the create / update permission.
  - Hide Add / Create / Edit / Delete buttons on those lists without the matching permission (`hasPermission(...)`, as on other pages).
  - The sidebar shows only items the user may open.

**Tests**
- Feature: "view leads only" host staff → 403 on list, show and store for each area above; host Admin → 200; Capital Access seeder gives the permission to Admin.
- Admin Vitest/E2E: that user typing `/lenders` (and the other 5) lands on `/access-denied`, and no data request is sent.

**How QA retests:** the original steps → all five URLs (and `/funding`) go to 403; the network tab shows no data calls; host Admin still sees everything.

---

## Decisions needed (they go into `docs/test-findings.md` as D#)

| ID | Bug | Question | My suggestion |
|---|---|---|---|
| D-1 | BUG-068 | Estimated amount: one figure or a min-max? Required on Approve? Must it be inside the option's range and/or ≤ the requested amount? | One figure, required, ≤ the option's maximum |
| D-2 | BUG-074 | Block or warn? Which step-backs are allowed? Can Denied be reopened, and to what? | Block; allow Missing Documents ↔ review steps; Denied → Underwriting Review only |
| D-3 | BUG-087 | Document Accepted/Rejected: bell only, or bell + email? | Bell + email for Rejected, bell only for Accepted |
| D-4 | BUG-094 | Mask SSN/EIN in the application PDF, or keep full for lenders? | Keep full in the signed copy for lenders, but only behind a password and on private storage |
| D-5 | BUG-103 | Client-level "Everyone" notes: in the application panel, a new Activity page, or both? | Application panel now; Activity page later |
| D-6 | BUG-112 | Free trial: no added users at all, or up to the seat limit? | Product owner to confirm; the code currently allows up to 3 |
| D-7 | BUG-114 | Installment: fixed 11 × $499.70 / $699.70, or flexible 2-24 with default 11? | Fixed 11 (matches Plan & Fee and the QA case) |
| D-8 | BUG-125 | New permissions `view/create/update/delete capital access`; which roles get them? | Yes; every host role that has `view credit accounts` today |

## Extra findings (not QA bugs; recorded as O# unless you want them fixed now)
- **O-a (BUG-094):** download password stored in plain text in `download_histories.details` - **will fix in this batch**.
- **O-b (BUG-094):** application PDFs (full SSN) saved on the public disk at guessable paths - fix proposed in BUG-094; needs your OK because it moves existing files.
- **O-c (BUG-125):** many other admin routes (partners, plan-fee, demo requests, …) also have no `permission:` middleware; only the page guard protects them. Outside this batch; worth a full route audit.
- **O-d (BUG-087):** unused unscoped `DocumentService::updateStatus()` in the borrower service.

## Finish checklist (per CLAUDE.md)
- Bug fixes test-first with a `Defect:` docblock; any test that asserts the old behaviour is updated, never deleted.
- Run `php artisan test` (Unit, Integration, Feature) and the admin + borrower Vitest suites; report the results, including failures.
- Add F# / D# / O# rows to `docs/test-findings.md`; write `docs/handoff/2026-10-2x-qa-round1-batch-4.md` in each repo, including the seeder to run after deploy.
- No commits or pushes until you ask; never push to `release`.
