# Profile change requests: the same value, and a second request while one is pending, are refused (BUG-039, round 2)

**Date:** 2026-11-02
**Author:** awlad

## Problem
QA round 2 **BUG-039** (R2-CP-PROF-02/03, Client portal › Profile › Business Details › Phone Number):
- A Change Request for the value already on file (`(555) 555-0122` → `(555) 555-0122`) was accepted ("submitted for review").
- Two more requests for the same field were accepted before the first was reviewed. The host saw three pending Business Phone requests for one client at once (ids 46, 47, 48).

`Borrower/Profile/ChangeRequestService::submitRequest()` read the value on file and created the request with no checks. `StoreChangeRequest` had no rule for either case. The host side's BUG-040 fix (`rebaseOtherPendingRequests`) coped with several pending requests after the fact.

## Solution
Product owner's choices:
- refuse both;
- the portal shows a field that is waiting for review;
- the borrower can **cancel** a pending request, which **force deletes** it (follow-up below).

Changes:
- **New `App\Services\Borrower\Profile\ChangeRequestGuard`:**
  - `current()`: the value on file and its entity, a profile column or one of the borrower's own rows (bank account). Null if unknown or not theirs.
  - `isSameValue()`: compares as written:
    - phones, SSN, EIN and DUNS by digits;
    - emails ignoring case;
    - decimal and integer fields as numbers;
    - dates as Y-m-d;
    - everything else trimmed (case kept: "acme" → "Acme" is a real change).
  - `hasPending()`: a Pending request for the same field and entity (bank accounts per row).
  - `pendingFields()`: `[{field_name, entity_id}]` (entity_id null for a profile column). No values, so no PII.
- **`StoreChangeRequest::withValidator`:** a 422 on `new_value`:
  - "This is already the value on your profile." (`profile.change_request_same_value`);
  - "You already have a change request for this field waiting for review." (`profile.change_request_already_pending`).

  An unknown field or a foreign row is still left to the service (400, as before).
- **`ChangeRequestService::submitRequest()`:** reads the value on file through `ChangeRequestGuard::current()`, one place instead of two.
- **New `GET /api/v1/borrower/profile/change-requests/pending`** (`ChangeRequestController::pending`): the borrower's pending fields.
- **New local-only `E2EClearBorrowerChangeRequestsSeeder`:** deletes the E2E borrower's **Pending** requests, for the borrower portal's change-request E2E. The borrower cannot withdraw one, and a leftover would block the next run.

## Files changed
- `app/Services/Borrower/Profile/ChangeRequestGuard.php`: new.
- `app/Http/Requests/Api/V1/Borrower/Profile/StoreChangeRequest.php`: `withValidator`.
- `app/Services/Borrower/Profile/ChangeRequestService.php`: uses the guard for the current value.
- `app/Http/Controllers/Api/V1/Borrower/Profile/ChangeRequestController.php`: `pending`.
- `routes/borrower.php`: the pending route.
- `lang/en/profile.php`: `change_request_same_value`, `change_request_already_pending`, `change_request_pending_list`.
- `database/seeders/E2EClearBorrowerChangeRequestsSeeder.php`: new.
- `tests/Feature/Borrower/ChangeRequestDuplicateTest.php`: new, 8 tests.
  - The same value: phone in three formats, email by case, revenue `12000.00`, SSN.
  - A different value is taken.
  - A second pending request is refused; once reviewed, a new one may be filed.
  - Another field does not block.
  - Bank accounts are per row.
  - The pending list is mine only, without reviewed requests.
- `tests/Feature/Borrower/ChangeRequestReviewApiTest.php`: the BUG-040 rebase test filed two pending requests through the API, which is now refused. Its second request is now a row created directly ("filed before this rule"; such rows exist on staging), so the rebase stays covered.

## Testing
- The new tests for the refusals and the pending list failed first (5 red; 3 guards passed from the start), then passed.
- `tests/Feature/Borrower`, `tests/Feature/Validation`, `tests/Unit/Profile`: 472 passed.
- Full suite (before cancel was added): 4,459 passed, 2 skipped, **2 failed**, both `Tests\Unit\Rules\SafeUploadTest` (SVG logo). They fail the same without this change; that is the recent SVG upload work, not this.

## Follow-up, same day: the borrower cancels a pending request (force delete)
- **New `DELETE /api/v1/borrower/profile/change-requests/{id}`** (`ChangeRequestController::cancel` → `ChangeRequestService::cancel`):
  - the borrower's own **Pending** request is removed with `forceDelete()` (the model uses SoftDeletes; nothing was decided, so no history is kept);
  - another borrower's or an unknown id is a **404** (`profile.change_request_not_found`);
  - a reviewed one is a **422** (`profile.change_request_already_processed`);
  - success says "Your change request has been cancelled." (`profile.change_request_cancelled`).
- **The pending list** now carries each request's `id`.
- **`E2EClearBorrowerChangeRequestsSeeder`** force deletes too (`withTrashed()->forceDelete()`). Its first version used `delete()`, which only trashed rows; the 8 trashed rows it left locally were removed.
- **Tests** (`ChangeRequestDuplicateTest`, +3; the list test now expects ids):
  - cancelled and gone even `withTrashed`, and the field takes a new request;
  - a reviewed one is refused and kept;
  - another borrower's is 404.

  The cancel and list tests failed first. `tests/Feature/Borrower` + `tests/Unit/Profile`: 336 passed.

## Open items
- The host is not told when a borrower cancels a request.
- Pending duplicates filed before this change (e.g. QA's 46/47/48 on staging) stay pending until the host reviews them. The BUG-040 rebase keeps their old values right.
- The borrower portal shows "Pending review" and the refusals; see its handoff of the same date.
- QA sheet BUG-039 → Fixed in build, pending stage retest.
