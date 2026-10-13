# File manager: borrower and staff folders scoped to the partner (F120, part of D2)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F120 (part of D2; other folder types stay open)

## Problem
Every file manager action (`/filemanager/list`, `tree`, `upload`, `folder`, `rename`, `delete`, `paste`, `download`) works below a root folder named by the request itself: `{entity_type}/{entity_id}`, or the whole `{entity_type}` with `get_all`. Borrowers were already held to their own folder (F10). The partner side had **no ownership check**, so a partner or any of its staff could reach other partners' files:
- **List, download, upload, rename, delete and paste** in another partner's borrower folders (`borrowers/{id}`). These hold tax returns, IDs and bank statements.
- **The same** for another partner's staff folders (`employees/{id}`).
- **Every borrower's folder at once** with `get_all`.

This was tenant isolation, not role permissions, which D1 left to the UI.

There was also a bug in the partner portal. The application document step opened `borrowers/{the signed-in user's entity id}`. That is a partner's or employee's id, so it opened some unrelated borrower's folder.

## Solution
**Decision (user):**
- A non-host partner and its staff see only their own partner's borrowers' and staff's files, never another partner's.
- The host partner and its staff see all.
- Only borrower and staff folders are covered for now.

**`FileManagerController`**
- The existing route middleware now also calls `partnerMayOpen()` for every non-borrower user:
  - `entity_type = borrowers`: the borrower's `partner_id` must be the caller's partner.
  - `entity_type = employees`: the employee's `partner_id` must be the caller's partner.
  - `get_all` on either: refused.
  - A host user (owner or staff): allowed, as before.
  - Any other `entity_type`: not checked, unchanged.
- A refusal is 403 `filemanager.folder_not_yours`, the same for all 8 actions.

**`FCH\Partner\FundApplication\FundingRequestResource`** now returns `borrower_id`, the application's or else the funding request's borrower.

**react-fch** `DocumentUploadStep` opens `borrowers/{fundingData.borrower_id}` instead of the signed-in user's id. See `react-fch/docs/handoff/2026-10-13-application-documents-open-the-borrowers-folder.md`.

## Files changed
- `app/Http/Controllers/Api/V1/Global/FileManager/FileManagerController.php`
- `app/Http/Resources/FCH/Partner/FundApplication/FundingRequestResource.php`
- `lang/en/filemanager.php` (`folder_not_yours`)
- `tests/Feature/FileManager/FileManagerApiTest.php`
- `tests/Feature/FundApplication/PartnerFundingApplicationStepsTest.php`

## Tests
All in `Feature/FileManager/FileManagerApiTest` unless noted.
- **Written to fail first, then passing:**
  - `another_partners_borrower_and_staff_folders_are_refused_for_every_action` covers list, tree, download, upload, folder, rename, delete and paste, for a non-host owner and for staff. It also checks that their files stay untouched.
  - `a_non_host_partner_cannot_open_every_borrower_or_staff_folder` covers `get_all`.
  - `Feature/FundApplication/PartnerFundingApplicationStepsTest::the_funding_request_names_its_borrower`.
- **Guards, passing before and after:**
  - `a_non_host_partner_and_its_staff_reach_only_their_own_borrowers_and_staff`
  - `the_hosts_team_reaches_any_partners_borrowers_and_staff`
- The earlier file manager tests still pass, including the borrower's own-folder rule (F10).
- **Suites run:** fch-api full suite 2309 passed, 42 skipped, 0 failed; real `storage/app` unchanged. react-fch `npm test`: 666 passed, typecheck clean.

## Open / follow-up
**D2 stays open for the other folder types,** which the portal also opens with ids or `get_all`: `partners`/`partner`, `contact`, `agreements`, `credit-accounts`, `resources`, `blogs`, `site-settings`, `seo-settings`.

**Separate finding (not changed):** a fallback in `FCH\Partner\FundApplication\FundingRequestResource` still has the same mistake. When no funding request is found, it looks one up with `borrower_id = auth()->user()->entity_id`.
