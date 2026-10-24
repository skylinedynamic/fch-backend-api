# Credit profile request: one per borrower, latest first, notifications

**Date:** 2026-10-15 · **Branch:** `bugfix/scrum-182` (uncommitted)

## Problem
1. **Duplicate rows in `credit_profile_requests`.** The borrower's submit (`POST v1/borrower/profile/credit-profile-request`) already used `updateOrCreate` by `borrower_id`, but nothing in the table enforced one row per borrower: two submissions arriving together (double click, retry) could both find nothing and both insert, and a soft-deleted request was invisible to the lookup, so the next submission added a second row beside it.
2. **Intake & Review → Request Credit Profile did not show the latest request first.** The list (`POST v1/admin/credit-profile-requests`) always sorted by `created_at` and ignored the `sort_field`/`sort_order` the screen sent; with a request updated in place, a borrower who resubmitted kept their original position and date.
3. **Submit notifications** — to be checked. The host is notified (bell + realtime push + queued mail, `host.credit_profile.requested`); this was working and is now pinned by tests including the push channel.
4. **No notice to the borrower when the host saved their iSoftPull data** (Client → Credit Profile, `POST v1/common/borrowers/save-credit-profile`).
5. Found on the way: `save-credit-profile` caught its own `ValidationException`, so a refused request (e.g. unknown `borrower_id`) answered **500** "Failed to save credit profile…" instead of 422.

## Solution
1. **Migration `2026_10_15_120000_make_credit_profile_requests_one_per_borrower`:** merges existing duplicates into each borrower's latest row (a live row over a soft-deleted one, then the most recently updated), repoints `credit_authorization_consents.credit_profile_request_id` from the merged rows to the kept one (no FCRA consent loses its request), hard-deletes the merged rows, then adds a **unique index on `borrower_id`**. **Service:** `CreditProfileRequest::withTrashed()->updateOrCreate(...)` restoring a soft-deleted row; Laravel's `createOrFirst` behind `updateOrCreate` falls back to the existing row when a simultaneous insert hits the unique index; `touch()` on a resubmission so even an unchanged one becomes the latest.
2. **List:** sorts by a whitelisted `sort_field` (`id, first_name, email, phone, created_at, updated_at`) and `sort_order`; default (and fallback for anything else) is `updated_at desc`, tie-broken by `id desc`. **Admin portal** (`Fch-admin-frontend-react`): the screen opens with `sort_field: updated_at`, and the Request Date column shows/sorts by `updated_at` (the latest submission).
3. No code change needed; tests added (see Testing).
4. New notification type **`borrower.credit_profile.updated`** (`config/notifications.php`, bell + broadcast + mail + sms like the other bell-and-mail types, link `/profile?tab=3` = the borrower's Credit profile tab) and **`App\Mail\Borrower\CreditProfileUpdatedMail`** (queued; the borrower's own partner's mailbox/branding; button to their portal — the white-label borrower domain, else `app.frontend_borrower_url`). Sent from `BorrowerController::saveCreditProfile()` after the commit and consent step, in its own try/catch (a notification problem is logged, never fails the save). Sent on every successful save, i.e. whenever the host (or anyone allowed to call the route) saves the report.
5. `ValidationException` is rethrown, so the route answers 422 with field errors.

## Files changed
- `database/migrations/2026_10_15_120000_make_credit_profile_requests_one_per_borrower.php` (new)
- `app/Services/Borrower/Profile/CreditProfileRequestService.php` — `withTrashed()->updateOrCreate`, restore, `touch()`
- `app/Http/Controllers/Api/V1/FCH/Admin/CreditProfileRequest/CreditProfileRequestAdminController.php` — sort whitelist, `updated_at desc` default
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerController.php` — borrower notification after `saveCreditProfile`; `ValidationException` rethrown
- `app/Mail/Borrower/CreditProfileUpdatedMail.php`, `resources/views/emails/borrower/credit_profile_updated.blade.php` (new)
- `config/notifications.php` — `borrower.credit_profile.updated`
- `lang/en/credit_profile_request.php` — mail texts, log message
- Tests:
  - `tests/Feature/CreditProfile/StoreCreditProfileRequestTest.php` — resubmission returns the same id; soft-deleted request restored, not duplicated; unchanged resubmission bumps `updated_at`; DB refuses a second row (unique index); resubmission notifies the host again; host bell is sent on `database` **and** `broadcast` (push).
  - `tests/Feature/CreditProfile/CreditProfileRequestListOrderTest.php` (new) — latest submission first; resubmitted request moves to the top; screen's default sort; column sort honoured; unknown/hostile sort field falls back to latest first; resubmission through the service lands on top.
  - `tests/Feature/CreditProfile/SaveCreditProfileNotifiesBorrowerTest.php` (new) — borrower mail queued (to, name, partner); mail renders with the Credit profile tab link; bell + broadcast with title and `/profile?tab=3`; a failing notifier does not fail the save; refused save → 422 and nothing sent.
  - `tests/Integration/CreditProfile/CreditProfileRequestsOnePerBorrowerMigrationTest.php` (new) — merge keeps the latest live row (over a newer deleted one), repoints consents, all-deleted keeps the latest, other borrowers untouched, unique afterwards.
  - Admin portal: `tests/pages/applications/credit-profile-requests/CreditProfileRequests.test.tsx` (+3), `e2e/credit-profile-requests-latest-first.spec.ts` (new, mocked).

## Testing
- New backend tests written first; failing before the change where it applied: the two that exposed real gaps failed on first run (unknown sort field kept `asc`; refused save answered 500), fixed after. Credit profile suites: **79 passed**.
- Full `php artisan test`: **1304 passed, 42 skipped, 18 failed** — all 18 in `tests/Integration/PreQualification` with `Call to undefined method OpenAiAgentsService::runStructuredAgent()`: pre-existing on this branch (methods lost in an earlier merge; fixed on `e2e-missing-tests` 2026-10-14, see `2026-10-14-prequal-agent-methods-lost-in-merge-and-readiness-404.md`), unrelated to this change.
- Admin portal: Vitest credit-profile-request suites 24/24 (2 of the 3 new tests failed before the change); `npm run typecheck` clean; Playwright `e2e/credit-profile-requests-latest-first.spec.ts` 3/3 against a fresh build.
- Borrower portal: no code changed. No E2E for the borrower's submit: each real submission writes an FCRA consent record that cannot be undone, so it is covered at the API level instead.

## Open items
- **The migration deletes rows on deploy** (merged duplicates; their consents are repointed first). Before deploying to stage/production, count them: `SELECT borrower_id, COUNT(*) FROM credit_profile_requests GROUP BY borrower_id HAVING COUNT(*) > 1;` (includes soft-deleted rows). The local database has none.
- Push = Reverb broadcast; it needs the queue worker and Reverb running on the server (the bell and both mails are queued).
- The 18 pre-qualification failures need the fix from `e2e-missing-tests` merged into this branch.

## Follow-up: credit profile notifications open the request (2026-10-15)
**Problem:** clicking "Credit profile update requested" in the host's bell opened the Request Credit Profile list, not the borrower's request: the type linked to the list and was sent without the request id. Old "New credit profile request" notifications could also point at a request removed by the one-per-borrower merge.

**Fix:**
- `config/notifications.php`: `host.credit_profile.update_requested` links to `/intake-review/credit-profile-requests/{{id}}`. `BorrowerCreditProfileController::requestUpdate()` sends the request `id`.
- `TemplateRenderer`: a link whose last value is empty opens the parent page (`/items/{{id}}` without an id becomes `/items`), so a borrower with no request on file gets the list.
- Migration `2026_10_15_130000_link_credit_profile_notifications_to_their_request` repairs stored notifications of both types that link to the list or to a request that no longer exists. Notifications carry only the borrower name, so it matches by name, and only where exactly one request has that name. Others keep their link. `down()` is a no-op. Runs after the merge migration.

**Tests (written, not run, as asked):**
- `StoreCreditProfileRequestTest`: the update request links to the borrower's request; with no request it links to the list.
- `TemplateRendererTest`: an empty last value opens the parent page.
- `CreditProfileNotificationLinksMigrationTest` (new, 6 tests).
- Admin portal: no change. Its bell already navigates to `data.url`.
