# QA round 2: Arafat's defects, plan (2026-10-28)

> **Superseded 2026-11-01:** done - see `2026-11-01-qa-round2-arafat.md`. The sheet has been renumbered since; that file maps these numbers to the current ones (e.g. 011 here is now BUG-009).

Source: QA Round 2 defect log (Google Sheet, gid 27742395), the 26 rows with **Assigned Dev = Arafat**:
BUG-011, 012, 021, 026, 027, 033, 034, 035, 037, 038, 039, 040, 041, 044, 045, 046, 055, 056, 058, 060, 061, 065, 066, 073, 075, 079.
(The other 46 rows are Awlad's and are not covered here.)

**Branch:** `qa-round2-bugfix`, created from `origin/stage` in three repos. It has no upstream, so a plain `git push` cannot go to `stage`; the first push must be `git push -u origin qa-round2-bugfix`. Nothing is committed or pushed yet.

| Repo | Base commit |
|---|---|
| `fch-backend-api` | `2138c1c5` |
| `Fch-admin-frontend-react` | `a2ffd220` |
| `fch-borrower-react` | `05fac41` |

`fch-website-react` is not touched by any of these rows.

**Status:** plan only, waiting for review. No production code has been changed.

QA ran Round 2 on **21–22 Oct**. Several fixes reached `stage` after that, on 24–26 Oct (PR #33 onwards). Rows already fixed on `stage` are marked **"already fixed, verify"**. For those, the work is a regression test plus a QA retest, not a code change.

---

## Status at a glance

| Bug | Sev / Pri | Repo(s) | Kind | Effort |
|---|---|---|---|---|
| BUG-011 Min FICO > 850 | Low P3 | admin (+ backend test) | API already fixed, UI bounds | S |
| BUG-012 Same state allowed + restricted | Low P3 | backend + admin | Validation | S |
| BUG-021 9-digit phone / 6-digit ZIP | Low P3 | backend test only | Already fixed, verify | XS |
| BUG-026 "Lender Required" wording | Low P4 | admin | Copy | XS |
| BUG-027 Inactive lender still matched | Med P2 | backend | Bug | S |
| BUG-033 Long business name breaks Review | Low P3 | borrower | CSS | XS |
| BUG-034 Pre-qual draft loses Funding Needs | Med P2 | backend + borrower | Bug | M |
| BUG-035 Closed application opens editable | Low P3 | borrower (+ backend) | Part already fixed, part bug | S |
| BUG-037 Employee status "[object Object]" | Low P3 | backend + admin | Bug + data | S |
| BUG-038 Inactive lender "Unknown Lender" | Low P3 | admin | Bug | S |
| BUG-039 Owner / co-borrower under 18 | Med P2 | backend + borrower + admin | Validation | S |
| BUG-040 Purpose note 256–500 chars gives 500 | Med P2 | backend + borrower + admin | Schema bug | S |
| BUG-041 Two tabs: stale save overwrites | Med P3 | backend + borrower + admin | Concurrency (shared) | M |
| BUG-044 Success Fee refusal silent | Low P3 | admin | UI | S |
| BUG-045 Double-click applies status twice | Med P3 | backend + admin | Concurrency (shared) | S |
| BUG-046 Funded amount far above requested | Low P4 | admin | UI warning | XS |
| BUG-055 Accepted, in-use document deletable | Med P2 | backend + borrower | Business rule | M |
| BUG-056 Client can't cancel admin-booked appointment | Low P3 | backend + borrower | Small feature | M |
| BUG-058 Stale tab flips Approved to Rejected | Med P2 | backend + admin | Concurrency (shared) | S |
| BUG-060 Can't clear partner Address / Contact Person | Low P3 | admin (+ backend test) | Bug | S |
| BUG-061 Inactive employee in "Assign to" | Low P3 | backend + admin | Bug | S |
| BUG-065 Lead Country default not saved | Low P3 | admin | Bug | S |
| BUG-066 PDF leaves out purpose note / timeframe / collateral | Low P3 | backend | Bug | XS |
| BUG-073 Partner emails carry host tagline | Low P3 | backend | Bug | XS |
| BUG-075 Appointment email has no title / type / link | Low P3 | backend | Email content | M |
| BUG-079 Two partner users: stale save reverts | Med P2 | backend + admin | Concurrency (shared) | M |

**Effort:** XS < 1 h, S ≈ half a day, M ≈ 1 day.

---

## Decisions needed before coding

Each row lists the option I recommend. These will go to `docs/test-findings.md` as `D#` entries if they stay open.

| # | Bug | Question | Recommended |
|---|---|---|---|
| 1 | 041 / 045 / 058 / 079 | How to stop silent lost updates | **Optimistic check.** The page sends the version it loaded (`expected_updated_at`, or `expected_status` for status changes). If the record changed in the meantime, the server answers **409** "This record was changed by someone else, reload". The field is optional, so older clients keep working. |
| 2 | 040 | Purpose note limit (the QA case expected 2,000; the API allows 500) | Make the column `TEXT` and keep the rule at **500**, which matches the embedded form. BA can raise the limit later without another migration. |
| 3 | 044 | Success Fee minimum: 0 or 1? | **1**, the existing API rule. The UI input moves to `min=1`. |
| 4 | 046 | When to warn about the funded amount | Whenever **funded > requested**. Show both amounts and require an extra confirmation before Confirm & Update. |
| 5 | 055 | Which requests make a document "in use"? | Funding requests that are Pending, In Progress or Approved, plus applications not yet in a closed or terminal status, whose sequence requires that document type. |
| 6 | 056 | Client cancel scope | The client can **cancel** (not delete) an appointment they attend but did not create. It must be in the future and not already cancelled or completed. The organiser gets one email. |
| 7 | 073 | Partner without a slogan | Show **no tagline**. Never fall back to the host's. Partners already have their own `site_slogan` in Settings → Branding. |
| 8 | 021 | ZIP input mask stops at 5 digits | **Keep the mask**: stopping at 5 is the standard input behaviour, and the API now refuses a bad ZIP anyway. No change. |
| 9 | 012 | Apply the same rule to industries? | **Yes.** An industry can't be both allowed and restricted either. Same rule, same code. |

---

## Plan per bug (root cause checked in the code)

### Funding Sequence / Underwriting Logic

#### BUG-011: Min FICO 851 accepted (already fixed in the API, verify)
- **Cause:** On `stage`, `lender_visibilities.*.min_fico` uses `App\Rules\FicoScore` (300–850) in `StoreFundingSequencingRequest`, `UpdateFundingSequencingRequest` and `UpdateFundingSequencingVisibilityRequest`. That change landed on 15 Oct and reached `stage` on 24 Oct, after QA's run. The admin input still has `min="0"` and no maximum (`LenderVisibilityGrid.tsx:903`).
- **Fix (admin):** set the Min FICO input to `min=300`, `max=850` and show the API's error inline.
- **Tests:**
  - Backend: add a case to `Feature/Funding/FundingCatalogueAdminApiTest` for the **per-category** endpoint (`.../lender/{id}/category/{cat}/visibility`). The per-lender endpoint is already covered.
  - Admin: unit test on the input bounds.

#### BUG-012: Texas both Allowed and Restricted
- **Cause:** `allowed_states` and `restricted_states` are validated independently as arrays in all three requests. Nothing checks that they overlap.
- **Fix (backend):** add a new rule, `App\Rules\NoOverlapWith` (or an `after()` hook shared by the three requests). It fails `lender_visibilities.N.restricted_states` when a value also appears in `allowed_states`, with the message "A state cannot be both allowed and restricted: Texas". Apply the same rule to industries (decision 9).
- **Fix (admin):** in `LenderVisibilityGrid`, a value selected in one multiselect is left out of the other's options.
- **Tests:**
  - Unit test for the rule.
  - Feature test: create, update and per-lender update each return 422 on an overlap.

#### BUG-038: Inactive lender shown as "Unknown Lender"
- **Cause:** `LenderVisibilityGrid.tsx:674–676` looks the name up in `lendersList`, which comes from `getAllActiveLenders()` (`LenderService.php:82`, active only). The visibilities API already eager-loads `lender` (`FundingSequencingService::getPaginatedVisibilities`, `with(['lender', ...])`).
- **Fix (admin):**
  - Name: `lender?.name ?? v.lender?.name`, with an **Inactive** badge when `v.lender.status !== 'Active'`.
  - Filter by Lender: active lenders plus any lender that already has a rule in this sequence, the latter tagged Inactive.
  - Add Lender: still offers active lenders only.
- **Tests:** admin unit test on the row and filter options (inactive lender keeps its name and badge).

### Intake & Review: Funding Request

#### BUG-027: Inactive lender still matched / assignable
- **Cause:** `FundingRequest::getMatchedLendersAttribute()` (`FundingRequest.php:142`) loads every `LenderVisibility` for the category with no lender-status filter. The admin "All Lenders" filter is built from `matched_lenders`, so fixing the source fixes both. `assign-lender` validates only `exists:lenders,id` (`FundingRequestController.php:68`).
- **Fix (backend):**
  - `whereHas('lender', fn ($q) => $q->where('status', Lender::STATUS_ACTIVE))` in the accessor.
  - `assign-lender`: `lender_ids.*` must be an **active** lender, so the API refuses an inactive one with 422.
  - A lender that is already assigned stays assigned and is shown; it is just not offered for new assignments.
- **Tests:** in `Feature/FundingRequest/FundingRequestReviewApiTest`:
  - inactive lender not in `matched_lenders`;
  - assigning an inactive lender returns 422;
  - an existing assignment still shows.

#### BUG-026: "Lender Required" modal wording
- **Fix (admin):** in `FundingRequestDetail.tsx:986`, change the text to "Assign a lender before approving this request."
- **Tests:** admin test on the modal text.

#### BUG-058: Stale tab flips Approved to Rejected (decision 1)
- **Cause:** `FundingRequestService::fundingRequestStatusUpdate()` (`:205`) writes whatever status is sent. It has no check of the status the page was showing. Change requests already refuse a second decision (BUG-049).
- **Fix (backend):**
  - `UpdateFundingRequestStatusRequest` accepts an optional `expected_status`.
  - The service reads the row with `lockForUpdate()` inside a transaction. If `expected_status` is sent and differs from the stored status, it returns **409** "This request was already {status} by someone else. Reload to see the latest." Nothing is written and no notification is sent.
- **Fix (admin):** send `expected_status` (the status shown) from `updateFundingRequestStatus`. On 409, show the message and reload the request.
- **Tests:**
  - Feature: stale `expected_status` returns 409, the status is unchanged, and `Notification::fake` sees no notification.
  - Feature: a matching status, or no `expected_status`, still works.

### Intake & Review: Funding Application (admin)

#### BUG-045: Double-click on Update applies the status twice (decision 1)
- **Cause:**
  - Admin: `ConfirmationModal.jsx` disables the button from the `loading` prop, which only changes after a re-render, so two quick clicks both call `onConfirm`.
  - Backend: two concurrent requests both read the old status in `RequestApplicationService::fundingApplicationStatusUpdate()` (`:438–450`), so both log "Status Changed".
- **Fix (admin):** an in-flight `useRef` guard in `ConfirmationModal`, so a second click is ignored until the first settles. This helps every confirm in the app. `FundingApplicationDetails` also sends `expected_status`.
- **Fix (backend):** the same `lockForUpdate()` + `expected_status` check as BUG-058. A repeat with the same status and the same note becomes a no-op, with no second activity entry.
- **Tests:**
  - Backend feature: a second identical update writes one activity log.
  - Backend feature: a stale `expected_status` returns 409.
  - Admin: double-clicking the confirm calls `onConfirm` once.

#### BUG-044: Success Fee refusal is silent (decision 3)
- **Cause:** On a 422 from `save-commission`, the card still shows the unsaved local value and the error isn't shown. The input allows `min=0`, but `SaveCommissionRequest` requires `total_commission` and `admin_commission` to be `min:1`.
- **Fix (admin, `FundingApplicationDetails.tsx` commission section):**
  - Input `min=1`, `max=100`, `step=0.01`.
  - Show the API error inline under the field and as a toast.
  - The card shows only the values the server saved: revert to them on error, and refetch on success.
- **Fix (backend):** message for `.max` stays "may not be greater than 100". Add a feature test that 101 gets the **max** message, not "at least 1".
- **Tests:** backend feature test on the messages; admin test that the card keeps the stored value after a 422.

#### BUG-046: No warning when funded amount ≫ requested (decision 4)
- **Fix (admin):** in `FundedAmountModal.tsx`, when funded > requested, show a warning ("Funded amount $60,000 is above the requested $12,000; closing locks the application"). It needs a tick, "I confirm this amount", before Confirm & Update is enabled.
- **Tests:** admin unit test.

#### BUG-066: Application PDF leaves out the purpose note, timeframe and collateral
- **Cause:** `resources/views/pdf/funding-application.blade.php:68–74` prints only Requested Amount, Funding Type, Additional Types, Use of Funds and Status. The model has `purpose_note`, `timeframe_needed` and `collateral_available`.
- **Fix (backend):** add "Purpose Note", "Timeframe Needed" and "Collateral Available" rows. Labels are mapped the way the detail page shows them.
- **Tests:** `Integration` render test: the rendered view contains the three values.

### Borrower: Pre-qualification

#### BUG-034: Draft loses the Funding Needs answers
- **Cause:** `PreQualificationService::prequalificationRequest()` (`:269–278`) always sets every Funding Needs field to `null` on GET, even when the borrower's own ON_HOLD draft holds them. It has done so since the service was first written on 14 Sep. Separately, the borrower wizard always starts at step 0 (`usePreQualificationForm.ts:11`).
- **Fix (backend):** blank the Funding Needs fields **only on the no-draft prefill path** (pre-filling from the profile must not carry an old submission's answers). When an ON_HOLD draft exists, return its own saved values.
- **Fix (borrower):** on load, resume at the first step that is not saved. Personal saved → Business; Business saved → Funding; all saved → Review.
- **Tests:**
  - Backend feature: GET returns the draft's funding fields; with no draft, they are still blank.
  - Borrower vitest: resume step.

#### BUG-033: Long business name without spaces breaks the Review layout
- **Fix (borrower):** `ReviewStep.tsx` value cells get `overflow-wrap: anywhere; word-break: break-word; min-width: 0`.
- **Tests:** vitest checks the class is applied; manual check at 1536 px and at phone width.

### Borrower: Profile

#### BUG-021: 9-digit phone saved, 6-digit ZIP truncated (already fixed, verify)
- **Cause:** `UpdatePersonalProfileRequest` uses `UsPhoneNumber` (10 digits, NANP) and `UsPostalCode` (5 digits). They landed on 15 Oct and reached `stage` on 24 Oct (PR #33), after QA's run. The portal already shows API field errors (`PersonalInfo.tsx:390, 595`).
- **Fix:** none needed. For the ZIP mask, see decision 8.
- **Tests:** backend feature `Borrower/BorrowerProfileApiTest`: `555555012` gets 422 on `phone_number`; `123456` gets 422 on `zip_code`. QA to retest.

### Borrower: Funding Application

#### BUG-035: Closed application opens editable; `/fund-application/<application id>`
- **Part 1 (already fixed, verify):** the read-only wizard (`FundApplication.tsx:484`, `is_editable === false`, a `fieldset disabled` and a banner) landed on 26 Oct (Round 1 BUG-071). The server already refuses with 423.
- **Part 2:** the URL takes a **funding request** id. `/fund-application/86` is the borrower's own request 86 (ownership is checked by `assertOwnFundingRequest`). That request has no application, so `getFundingInfo()` falls back to the Borrower record and the wizard opens blank.
- **Fix (backend):** when the request has no application and is not Approved, `getFundingInfo` answers 404 / "not available".
- **Fix (borrower):** on 404, show a "Funding application not found" state with a link to My Applications, not an empty wizard.
- **To verify first:** that saving a step for a non-approved request is refused server-side too.
- **Tests:**
  - Backend feature: a request with no application that is not approved returns 404.
  - Borrower vitest: not-found state.

#### BUG-039: Owner or co-borrower under 18
- **Cause:** `date_of_birth` is `required|date` in:
  - `Borrower/FundApplication/StoreOwnershipInfoRequest.php:66`
  - `Borrower/MyApplication/CoBorrowerValidationRequest.php:54`
  - the partner copies of both under `FCH/Partner/FundApplication/`
  
  Co-borrowers saved with the step are checked only for SSN, ZIP and phone in `after()`. The embedded form already enforces 18 or older (`SubmitEmbedFormRequest:214`).
- **Fix (backend):**
  - New rule `App\Rules\AdultDateOfBirth`. The minimum age comes from config (`funding_application.minimum_age`, default 18, the same value the embed form uses).
  - Apply it to the four requests and to `co_borrowers.*.date_of_birth` in the `after()` loop.
  - Message: "Must be at least 18 years old."
- **Fix (borrower + admin wizard):** DOB pickers use `maxDate = today − 18 years`.
- **Tests:**
  - Unit test for the rule (exactly 18 today passes; 18 tomorrow fails).
  - Feature tests: ownership-info and co-borrower-validation refuse age 17 (borrower and partner routes).

#### BUG-040: Purpose note 256–500 chars gives a 500 (decision 2)
- **Cause:** both `funding_applications.purpose_note` and `pre_qualification_requests.purpose_note` are `string` (255) (migrations `2026_03_02_*`). The funding-application rule allows `max:500`. Embed-form submissions store 500 and are converted into applications, which is the same crash path.
- **Fix (backend):** new migration that changes `funding_applications.purpose_note` to `text`. Keep `max:500`. Pre-qual stays 255, which matches its `max:255` rule.
- **Fix (borrower + admin):** `maxLength=500` and a character counter on the field.
- **Tests:** backend feature: 500 chars saves; 501 gets 422 (SQLite doesn't enforce the 255 length, so the test pins the rule, and the migration is checked on MySQL locally).

#### BUG-041: Two tabs, stale save overwrites (decision 1)
- **Cause:** every step save sends the whole step, and the server applies it with no version check.
- **Fix (backend):**
  - A shared `App\Support\Concurrency\StaleWriteGuard`: compares an optional `expected_updated_at` with the row (on a locked read), and throws `StaleRecordException`.
  - `withExceptions()` renders that as 409 with the latest `updated_at`.
  - Used in the borrower and partner funding-application step saves.
  - Step GET resources return `updated_at`.
- **Fix (borrower + admin wizard):**
  - Send `expected_updated_at` with each step and keep the new value from the response.
  - On 409, show the modal "This application was changed in another tab or by someone else" with a **Reload** button.
- **Tests:**
  - Backend feature: a stale save returns 409 and the data is unchanged.
  - Backend feature: a fresh save works; a save without the field still works.
  - Borrower vitest: the 409 modal.

### Borrower: Documents

#### BUG-055: An Accepted document in use can be deleted (decision 5)
- **Cause:** `Borrower\Document\DocumentService::removeById()` (`:157`) deletes any document the borrower owns, with no check against what needs it. Readiness (`FundingReadinessService::getMissingDocuments`) then reports it missing.
- **Fix (backend):** before deleting, if the document is **Accepted**, and it is the only non-rejected document of its type, and an open request or application (decision 5) has a sequence that requires that type: refuse with 422 "This document is required by funding request #45. Upload a replacement first." Replacing still works: upload the new one, then remove the old one.
- **Fix (borrower):** show the API message. The confirm text says when the document is Accepted.
- **Tests:**
  - Feature: in-use Accepted document gets 422 and is not deleted.
  - Feature: deletable when there is a second copy, when nothing needs it, or when it is Pending or Rejected.

### Appointments

#### BUG-056: Client can't cancel an admin-booked appointment (decision 6)
- **Fix (backend):**
  - `POST common/appointments/{id}/cancel`. Allowed for the creator or an attendee (the existing `entityIsAttendees()` check), only while in the future and not cancelled or completed.
  - Sets `status = cancelled` and logs activity.
  - Sends the existing "Appointment Cancelled" mail (`AppointmentNotification` `deleted` view) once to the organiser and the other attendees.
- **Fix (borrower):** a **Cancel appointment** button in the details modal, for an appointment the client attends but did not create, with a confirm step. Delete stays hidden.
- **Tests:**
  - Feature: an attendee can cancel; a stranger gets 404/403.
  - Feature: a past appointment gets 422.
  - Feature: `Mail::fake` sees one mail per other party.
  - Borrower vitest: the button shows only in the right case.

#### BUG-075: Appointment email has no title, type, attendee or link
- **Cause:** `resources/views/emails/appointment/create.blade.php` (and `update`) show the description, Date, Time Slot, Time Zone and Scheduled By. The `subject` and `meeting_type` fields are never printed, and there is no link.
- **Fix (backend), in `create`, `update` and `delete` blades plus `AppointmentNotification`:**
  - Title heading (`subject`).
  - Meeting Type.
  - "With" (attendee names).
  - **View appointment** button: the recipient's portal URL, borrower or admin, from the partner's domain.
  - An `.ics` attachment ("Add to calendar") built from the stored UTC time.
  - Hide the description block when it is empty (that is the blank block above the details).
- **Tests:** `Integration/Mail` render test: subject, type, attendee and link present; `.ics` attached with the correct DTSTART.

### Email branding

#### BUG-073: Partner emails carry the host tagline (decision 7)
- **Cause:** `app/Mail/Traits/MailableBranding.php:157`:
  ```php
  $slogan = $brandingConfig['site_slogan'] ?? $hostBrandingConfig['site_slogan'] ?? null;
  ```
  It falls back to the host's slogan for every partner.
- **Fix (backend):** for a non-host partner, use only the partner's own `site_slogan`, or none. Colours keep their partner → host → default inheritance (that part is intended).
- **Tests:** `Integration/Mail`:
  - partner without a slogan: no host tagline;
  - partner with a slogan: theirs;
  - host: unchanged.

### Partners / Employees / Clients / Leads (admin)

#### BUG-060: Clearing Address or Contact Person keeps the old value
- **Cause (to confirm by reproducing):** `buildPartnerFormDataPayload` (`modules/partner/logic.ts:107`) skips every key whose value is `null` or `undefined`. Cleared inputs that become `null` are therefore never sent, and the API keeps the old value. The API side (`UpdatePartnerRequest`: `contact_person`, `address_line_1` nullable) saves an empty value when one is sent.
- **Fix (admin):** on update, send `''` for clearable fields (address lines, contact person fields and the other optional text fields) instead of skipping them.
- **Fix (backend):** none expected.
- **Tests:**
  - Backend feature in `PartnerAdminApiTest`: sending an empty `address_line_1` / `contact_person` clears them. This pins the API.
  - Admin unit test on `buildPartnerFormDataPayload`.

#### BUG-061: Inactive employee offered in "Assign to"
- **Cause:** `CommonService::getAllUsers()` (`:32`) returns `User::teamOf($partner)` with no check on the employee's `job_status`.
- **Fix (backend):** leave out users whose employee is Inactive or Suspended. An optional `include_user_id` keeps the client's current assignee in the list, so their label still shows.
- **Fix (admin):** `ClientInfoSection` passes the current `assign_id` and marks that user "(Inactive)" when flagged.
- **Tests:**
  - Feature: an inactive employee is not listed;
  - Feature: `include_user_id` keeps them and flags them.

#### BUG-037: Employee status "[object Object]"
- **Cause:** `job_status` is only `nullable|string|max:255` in `StoreEmployeeRequest` and `UpdateEmployeeRequest`, so any string, including `"[object Object]"`, is stored. On the frontend, the employee FormData loop (`EmployeeDetails.jsx:628–660`) appends values as they are, so an object becomes `"[object Object]"`. The exact create-with-account path that produced an object is to be reproduced first.
- **Fix (backend):** `job_status` becomes `Rule::in(Employee::STATUS_*)`, so the API refuses junk.
- **Fix (admin):** only primitive values are appended (objects become their `value`).
- **Data:** a one-off migration sets `job_status = 'Active'` where it is `'[object Object]'`. It runs on deploy; the affected row is EMP-00002.
- **Tests:**
  - Feature: an unknown status gets 422;
  - Feature: the migration cleans the bad value (Integration).

#### BUG-065: Lead Contact Info shows "United States" but saving says "Country is required"
- **Cause (to confirm by reproducing as Wanda):** `LeadDetails.jsx` displays `formData.country`, normalised from the API (`:428`), but the payload (`:207`) can still send an empty or unknown code. The display and the form value come from different sources for a lead created without a country.
- **Fix (admin):** on load, when the lead has no country, put the default ("United States") into `formData`. That way what is shown is what is sent.
- **Tests:** admin unit test: loading a lead with no country gives `formData.country` = "United States", and it is in the payload.

#### BUG-079: Two partner users, the older page reverts a change (decision 1)
- **Cause:** the client update in `common/borrowers` saves the whole form, with no version check (same pattern as BUG-041).
- **Fix (backend):** the same `StaleWriteGuard` (`expected_updated_at`) on the client-info update. On 409, nothing is written.
- **Fix (admin):** `ClientInfoSection` sends the `updated_at` it loaded. On 409: "This client was changed by someone else. Reload."
- **Tests:**
  - Backend feature: a stale save returns 409; the phone keeps the newer value; no activity log is written.

---

## Order of work

1. **Quick, safe backend fixes:** 027, 012, 039, 040, 066, 073, 037 (rule + migration), plus regression tests for 011, 021 and 035 part 1.
2. **Behaviour fixes:** 034, 055, 061, 035 part 2.
3. **Concurrency (one shared guard):** 058, 045, 041, 079.
4. **Admin UI:** 011, 012, 026, 038, 044, 046, 060, 065, plus the frontend side of steps 1–3.
5. **Borrower UI:** 033, 034, 035, 039, 040, 041, 055, 056.
6. **Appointments:** 056 (backend), 075.

Each fix follows CLAUDE.md: a failing test first (with a `Defect: R2 BUG-0xx` docblock), the fix, then the affected suites run. Each fix gets an `F#` entry in `docs/test-findings.md`, and a handoff file is written when the batch is finished.

## Deploy notes (once implemented)
- **Migrations:**
  - `funding_applications.purpose_note` → `text`;
  - clean up `employees.job_status = '[object Object]'`.
- **API contract additions (all optional, backward compatible):** `expected_status`, `expected_updated_at`, `include_user_id`, and `POST common/appointments/{id}/cancel`. A new **409** response must be handled by the admin and borrower portals.
