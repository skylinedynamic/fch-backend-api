# QA round 1 — bug batch: plan and status (2026-10-26)

Source: QA defect log (Google Sheet, gid 499536418), rows BUG-117, 116, 088, 086, 085, 084, 076, 071, 070, 061, 059, 058, 057, 054, 049, 044, 039, 038, plus the Loan Applications "Desired Funding" sort question.
Branch in every repo: `bugfix/qa-round1-release-v2`, except `fch-website-react`, which is on `release` and gets a new branch from `stage` before any change. Nothing is committed or pushed yet.

Decisions taken with the user:
- **BUG-116:** follow F131. A Pending request does not block the card.
- **BUG-086:** hide the empty ad panel.
- **BUG-085:** plan only (below), for review before any code.

## Status at a glance

| Bug | Repo | Status |
|---|---|---|
| Desired Funding sort | backend | ✅ Done |
| BUG-054 | backend | ✅ Works correctly on this branch, no code change needed. Regression test added; QA to retest |
| BUG-059 | backend + admin | ✅ Done (F161): duplicates refused, chips renamable, app confirm dialog, newest first, full messages |
| BUG-070 | backend + admin + borrower | ✅ Done: activity names the lender; guideline copy fixed; tracker keeps each step's date (`status_dates`) |
| BUG-076 | backend | ✅ Done |
| BUG-084 | borrower | ✅ Done (missing card image is data — see Open) |
| BUG-117 | borrower | ✅ Done |
| BUG-086 | borrower | ✅ Done |
| BUG-116 | borrower | ✅ Done |
| BUG-061 | borrower + admin | ✅ Done: readable labels in the borrower portal and the admin client view / snapshot tables |
| BUG-044 | borrower | ✅ Done |
| BUG-088 | borrower | ✅ Done |
| BUG-071 | borrower + admin | ✅ Done: 5 parts fixed; field relabelled "Approved Funding Product" (no approved-amount source exists - adding an amount is a product decision) |
| BUG-038 | admin | ✅ Done |
| BUG-039 | admin | ✅ Done (F160) |
| BUG-049 | admin | ✅ Works correctly, no code change needed: add says "added", edit says "updated" in both modals (since June); pinned by `ManageNamesModals.test.tsx` (6 tests). QA to retest |
| BUG-057 | admin | ⏸️ Discarded 26-Oct at the user's request: the fix (no +1h default, typed time kept) was reverted; behaviour unchanged |
| BUG-058 | website | ✅ Done (branch `bugfix/qa-round1-release-v2` from `origin/stage`) |
| BUG-085 | borrower + backend | ⏸️ Dropped 26-Oct at the user's request - not implemented (plan kept below) |

## Done

### Desired Funding sort (Loan Applications)
- **Why it failed:** the column shows `requested_amount ?? desired_funding_amount`, but the API sorted on `desired_funding_amount` only. Since the 10-Sep form rewrite, new submissions store `requested_amount` and leave `desired_funding_amount` null, so the sort compared nulls.
- **Fix:** `EmbedFormController` sorts by `COALESCE(requested_amount, desired_funding_amount)`.
- **Caveat:** amounts in other currencies sort by face value.
- **Test:** `Feature/EmbedForm/EmbedFormSubmissionsApiTest::desired_funding_sorts_by_the_amount_the_column_shows`.

### BUG-054: archived client under "Active Only" (owner)
- The owner's request (`archived = ''` plus the DataTable's empty default filters) excludes archived clients on this branch.
- **Test:** `Feature/Borrower/BorrowerRecordsApiTest::an_archived_client_is_left_out_of_the_owners_active_only_list`.
- QA to retest on the next build. If it still reproduces, capture the request payload from DevTools.

### BUG-059: duplicate blog categories/tags (backend)
- `CategoryRequest` and `TagRequest`: the name is unique per partner, ignoring soft-deleted rows, with the messages `blog.category_name_taken` and `blog.tag_name_taken`. No DB index was added, so existing duplicates are untouched.
- **Tests:** `Feature/Website/CmsBlogAdminApiTest` (name cannot be repeated; a deleted name can be reused).

### BUG-070: assignment activity names the lender
- `FundingRequestService::assignLender` writes the subject as "Lender Assigned: Big Bank" (or "Lenders Assigned: A, B").
- **Test:** `Feature/FundingRequest/FundingRequestReviewApiTest::the_assignment_activity_names_the_lender`.

### BUG-076: client sees why a change request was rejected
- `borrower.change_request.decided` body is now `...was {{status}}.{{reason}}`. `ChangeRequestNotifier` fills `reason` (" Reason: <note>") for rejections only; the email already carried the note.
- **Deploy note:** a `notification_types` row with a `body_template` override in an environment replaces this text. Check that the admin notification settings have none for this key.
- **Tests:** `Feature/Borrower/ChangeRequestReviewApiTest` (reason on reject; none on approve).

### BUG-084: PG Required wording (borrower)
- `utils/creditAccounts.ts` `pgRequiredLabel()` maps the free text to Required / Not Required / Typically Required, and keeps specific conditions as written. The difficulty tag is hidden when unset (no "N/A").
- **Test:** `tests/utils/creditAccounts.test.ts`.

### BUG-117: "Your changes were not saved." on Cancel
- `EditConfirmationModal`: declining the on-open dialog shows the info toast, then leaves.
- **Test:** `tests/pages/fundapplication/components/shared/EditConfirmationModal.test.tsx`.

### BUG-086: empty "Ad space" panel
- Removed from `MyApplication.tsx` (mobile) and `RightSideSection.tsx` (desktop).
- **Test:** `tests/pages/myapplication/MyApplication.test.tsx`.

### BUG-116: Pending request card vs detail page (F131)
- `FundingCard`: only an engine-locked option is disabled. A Pending request shows "Under Review" with a tooltip ("You can still start a new funding request for this option.") and opens the option.
- **Tests:** `tests/pages/funding/components/FundingCard.test.tsx` updated (the old "stays blocked" test asserted the rule F131 replaced). E2E description in `e2e/sidebar/funding/funding.spec.ts` updated.

### BUG-061: internal keys in the borrower's underwriting result
- `utils/underwritingLabels.ts` `readableLabel()` converts:
  - `borrower.full_name` → "Full Name"
  - `NOT_PROVIDED` → "Not Provided"
  - `DERIVED_FROM_SUPPLIED_DATA` → "Derived Data"
- Ordinary text ("FICO", "U.S.", names, amounts) is left alone.
- It applies to snapshot areas, extra-section tables and text. The source badge gained "Not Provided", which used to fall through to "Borrower Reported".
- **Tests:** `tests/utils/underwritingLabels.test.ts`, `tests/pages/prequalification/components/UnderwritingResultStep.labels.test.tsx`.

### BUG-044: disallowed file type gives no reason
- `UploadModal`: a file is checked when chosen. A refused file never enters "Selected Files"; an inline alert gives the reason and the allowed types, and the picker has an `accept` list. Helpers: `fileRejectionReason` and `FILE_MANAGER_ACCEPT` in `modules/filemanager/config.ts`.
- **Test:** `tests/components/FileManagerModal/UploadModal.test.tsx`.

### BUG-088: manage documents on a Pending funding request
- `FundingRequestDetailPage`, while the request is Pending:
  - **Upload** per document type: the file manager in selection mode, then `POST /borrower/funding/requests/documents/upload`.
  - **Remove** per file: confirm dialog, then `DELETE /borrower/funding/requests/documents/{id}`.
- The table is read-only once the request is decided. It reuses the wizard's endpoints, so no API change.
- Removing a file also removes it from Documents, and the dialog says so.
- **Test:** `tests/pages/funding-requests/FundingRequestDetailPage.documents.test.tsx`.

### BUG-071: funding application wizard and list (5 of 6 parts)
| Part | Fix |
|---|---|
| Progress jumped to 63% "5 of 8" on arriving at step 5 | `stepProgress.ts` `isWizardStepComplete()`: Document Upload counts once the borrower has moved past it, not on arrival |
| Closed application read 0% | A locked application (`is_editable === false`) counts every step; converted or older applications carry no per-step flags |
| Locked (Matched to Lender) application was editable | The wizard now reads `is_editable` / `locked_reason`. Steps render inside `<fieldset disabled>` with a banner, and tab switches don't auto-save |
| Disabled Save & Next gave no reason | `StepNavigation` shows "Complete the required fields (*) to continue." |
| My Applications Funding Type showed the uses list | The column shows `preferred_funding_type` |
| Step 4 "Approved Funding" shows "Term Loans" | **Not changed — D#**. `approved_funding` holds the approved *product name* by design: `FundingSequencingResource` matches on it. Showing an amount needs a source for the approved amount. Product to decide: relabel to "Approved Funding Product", or add an approved amount |

- **Tests:**
  - `tests/pages/fundapplication/stepProgress.test.ts`
  - `FundApplication.lockedAndProgress.test.tsx`
  - `components/shared/StepNavigation.test.tsx`
  - `tests/pages/myapplication/components/FundingApplications.test.tsx` (updated)

### BUG-038: empty role name saved silently (admin)
- **Why:** `CreateRole.tsx` and `EditRole.tsx` `handleSave` had `if (!name) return`, so Save/Update stopped before the request: no request, no message, field not marked. The API already refuses it (`StoreRoleRequest`/`UpdateRoleRequest`: `errors.name` = "The role name is required.").
- **Fix:** the early return is gone. The request is sent, the API message shows under the field, and the field gets `is-invalid`. The message has one source: the API. Likewise a `permission_ids` error ("Permissions are required.") puts a red border (`border-danger`) on the Module Permissions box. Only `border-danger` is used: `assets/css/custom.css` sets `.border { border: 1px solid #ced4da !important }` after Bootstrap, which turned the red border back to grey.
- **Tests:**
  - Admin `tests/pages/roles/Roles.test.tsx`: the two tests that asserted "no request" (the bug) now assert the request, the message and the marked field. Both failed before the fix.
  - Backend `Feature/RolePermission/RoleAndPermissionApiTest::an_empty_role_name_is_refused_with_role_name_is_required` (create and update) pins the message.

### BUG-058: website titles repeat the brand (fch-website-react)
- **Is it dynamic?** Yes. Each page's title is the CMS **SEO Settings** `meta_title` for its path (`GET /v1/website/seo?path=/terms`, edited in Admin › CMS › SEO Settings). The brand is the CMS **site name** (`/v1/website/settings`).
- **Why it repeated:** the brand was added in three places.
  1. The CMS title itself already ends in "| Four Corner Funding".
  2. `lib/metadata.ts` appended the hardcoded `seoConfig.titleTemplate`.
  3. The root `layout.tsx` `title.template` appended it again, because pages returned a plain string.
- Stage served "Terms of Service | Four Corner Funding | Four Corner Funding | Four Corner Funding" (checked 26-Oct).
- **Fix:**
  - New `lib/pageTitle.ts` `brandedTitle(title, siteName, fallback?)` adds " | <site name>" only when the title doesn't already contain it.
  - `getDynamicMetadata` and `getBlogPostMetadata` return `title: { absolute }`, so the layout template can't add it again.
  - The site-name fallback is now the brand ("Four Corner Funding"), not the long default title.
  - Home falls back to `seoConfig.defaultTitle` when its CMS entry has no title.
- **Verified:**
  - On the local dev server against local CMS data, `/terms`, `/privacy-policy`, `/disclosure`, `/`, `/faq` and `/contact` each carry the brand once.
  - The helper was checked against stage's CMS values (8 cases), and `tsc` is clean.
  - The website has no test runner (ESLint only); adding Vitest is a separate decision.
- **Data note:** the stage Home SEO entry has no `meta_title`. Code now falls back to the default title, but someone should enter one in the CMS.
- **All `seo_settings` rows wired (follow-up):**
  - Problem: 8 pages are client components with no metadata, so they showed the home title. They are `/sba-programs`, `/real-estate`, `/asset-and-equipment`, `/bridge-short-term`, `/business-acquisition`, `/receivables-and-cash-flow`, `/working-capital-and-growth` and `/pre-qualification`.
  - Fix: each now has a `layout.tsx` that reads its own SEO Settings row.
  - `getDynamicMetadata(path, pageName?)` ignores the API's generic default (a response with no `id`) and titles such a page by its name, so `/blog` shows "Blog | Four Corner Funding".
- **Verified:** the 17 rows whose page exists render their `meta_title`, brand once. Titles that lack the brand get it added (`/partner-program`).
- **Full field check (scripted, local dev server vs `seo_settings`):** all 17 pages that exist pass. Each was checked for 8 values against its row: `<title>`, og:title, twitter:title, description, og:description, keywords, og:image and twitter:image.
  - Run as a browser, Googlebot, Facebook, Twitter, LinkedIn and Slack.
  - Next.js streams metadata into the body for browsers and Googlebot, which run JavaScript. Link-preview bots get it in `<head>`.
- **Title rule (confirmed with the user, 26-Oct):**
  - A `meta_title` saved in `seo_settings` (or on a blog post) is shown **exactly as saved**, with nothing added.
  - Only when it is empty, or the path has no row, does the page show "`page_name` | Four Corner Funding". It uses the row's `page_name`, else the name the page passes in.
  - Home with no `meta_title` gets the default title.
  - Code: `lib/pageTitle.ts` `resolvePageTitle()`, checked against 10 cases (saved, saved with typo, empty, null, spaces, no row, API down, home ×2).
- **Live, not cached:** the website fetches the CMS with `revalidate: 0`, so an edit in Admin › CMS › SEO Settings shows on the next page load.
- **CMS data to fix (no code):**
  - `/about-us` and `/career` have rows, but the website has no such pages (404).
  - Typos: "…Credit Support1" (`/contact`), "…Join Our Team1" (`/career`), "…Review | Four Corner1" (`/pre-qualification`, which therefore renders "… | Four Corner1 | Four Corner Funding"), and "Homes" (`/`).
  - There is no row for `/blog`.

## Remaining (in order)

1. **Admin frontend**, tests first in its suite:
   - **BUG-061 admin:** the same readable labels in `PreQualificationDetails.tsx` (the client view and the snapshot table).
   - **BUG-070:**
     - "Admin can reviewing the request." → "Admin is reviewing the request." (`config/funding.ts` in admin *and* borrower)
     - The tracker keeps intermediate step dates after approval (admin `FundingRequestDetail.tsx`)
2. **Records:**
   - `docs/test-findings.md`: F-entries for each fix, plus the D# for BUG-071 "Approved Funding".
   - One handoff per repo (backend, admin, borrower, website).
3. **Verification:**
   - Backend: `php artisan test`.
   - Borrower and admin: `npm test` plus `npm run typecheck`. Check that `documentsData` in `FundApplication.tsx` is still used after the progress change, and remove it if not.
   - Report any failures with their output.

## BUG-085: support chat session history and delete — plan for review

**Today:**
- **Backend** (`BorrowerAssistantController`, `routes/borrower.php`):
  - `GET  /borrower/assistant/sessions`: the caller's conversations (`session_id`, `status`, `last_message_at`, `created_at`).
  - `GET  /borrower/assistant/conversations/{sessionId}/messages`: one conversation's messages, owner-scoped.
  - `POST /borrower/assistant/sessions`: closes the open conversation and starts a new one.
  - `DELETE /borrower/assistant/sessions`: soft-deletes **all** of the caller's conversations. Nothing deletes a single one.
- **Borrower widget** (`components/Common/ChatWidget/SupportChatWidget.tsx`): restores only the latest session on open, and "+ New Session" starts a new one. There is no list of earlier sessions, no way to reopen one, and no delete.

**Proposed:**
1. **Backend**, tests first (`Feature/Assistant/...`):
   - `GET /sessions`: add a `title` (the first user message, trimmed to ~60 chars) and `message_count`, so the list is readable without opening each session.
   - New `DELETE /borrower/assistant/sessions/{sessionId}`: soft-deletes one conversation owned by the caller; 404 for anyone else's. Keep `DELETE /sessions` (all) for "Clear history".
   - Messages of a soft-deleted conversation are no longer returned (already true via `findOwnConversationByUuid`; pin it with a test).
   - Audit: log the delete in the activity log without message content (PII).
2. **Borrower widget**, tests first (Vitest + MSW):
   - A "History" button in the widget header opens a panel listing sessions: title, date, and "Current" on the open one.
   - Clicking a session loads its messages (`fetchAssistantHistory`) and makes it the current session. Earlier (closed) sessions are **read-only** unless product wants them resumable (question 2).
   - Each row has a delete icon with the app confirm modal; after deleting the current session, the widget starts fresh.
   - "Clear history" at the bottom calls `DELETE /sessions`, with a confirm.
   - "+ New Session" is unchanged.
3. **Open questions for you:**
   1. Should delete be a soft delete (kept for compliance/audit, hidden from the borrower), as today, or a hard delete? The recommendation is soft delete.
   2. Can a borrower continue an old session, or only read it? Read-only is simpler and matches "New Session" closing the old one.
   3. Is the same needed for the WLP assistant in the admin portal (`/common/assistant/wlp/*`)? Out of scope unless you say so.
- **Size:** backend about half a day with tests; widget about a day with tests.
