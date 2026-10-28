# Client SSN and Business EIN masked, revealed one field at a time (BUG-014, reopened)

Date: 2026-10-29

## Problem
Owner › Clients › Client Info. The QA retest on 28-Oct left BUG-014 open (2 of 3 not fixed):
- `GET /api/v1/common/borrowers/{id}` returned the client's full SSN and full Business EIN. Anyone who could open the client had both in the network response, even where the screen masked the SSN.
- The change request queue (`POST /api/v1/admin/change-requests`) listed an SSN or EIN change with both the old and new numbers in full.

## Solution
- **Client details are masked.** `BorrowerDetailsResource` sends `ssn` as `***-**-6789` (`Ssn::mask()`) and `business_ein` as `**-***4321` (new `BusinessEin::mask()`). It covers show, store and update, which all use this resource.
- **One reveal endpoint, the field named in the payload:** `POST /api/v1/common/borrowers/{id}/reveal` with `{ "field": "ssn" | "business_ein" }` returns `{ field, value }` (digits, or `null` when none is on file).
  - Anything else in `field` is a 422 (`RevealBorrowerFieldRequest`; the list is `BorrowerService::REVEALABLE_FIELDS`).
  - The client is found through `getDetails()`, so `visibleTo()` applies: another partner's client is a 404.
  - No extra permission. As decided, anyone who can open the client may reveal.
  - Each reveal writes an internal activity-log entry on the client, "SSN Viewed" / "Business EIN Viewed", naming who looked and never the number.
- **A mask sent back is no change.** `UpdateBorrowerRequest::prepareForValidation()` drops an `ssn` or `business_ein` that is a mask (`BusinessEin::isMask()`: it contains `*`), so a form that echoes what it was shown keeps the numbers on file. A real number typed in is still validated and saved.
- **Change requests are masked.** `ChangeRequestResource` masks `old_value` / `new_value` with the model's existing `maskedValue()` (the one the emails use) and adds `is_masked`. The reviewer reveals both numbers with `POST /api/v1/admin/change-requests/{id}/reveal` (host team only, as the rest of `v1/admin`). It returns `{ id, old_value, new_value }`, is logged on the client the same way, and is a 422 for a field that is not masked.

## Files changed
- `app/Rules/BusinessEin.php`: `mask()` and `isMask()`.
- `app/Http/Resources/FCH/Borrower/BorrowerDetailsResource.php`: `ssn` and `business_ein` masked.
- `app/Http/Requests/Api/V1/FCH/Common/Borrower/UpdateBorrowerRequest.php`: a masked value sent back is dropped.
- `app/Http/Requests/Api/V1/FCH/Common/Borrower/RevealBorrowerFieldRequest.php` (new): `field` must be `ssn` or `business_ein`.
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: `REVEALABLE_FIELDS`, `revealField()` (logs, returns the number).
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerController.php`: `reveal()`.
- `routes/common.php`: `POST borrowers/{id}/reveal`.
- `app/Models/Borrower/BorrowerChangeRequest.php`: `isMaskedField()`.
- `app/Http/Resources/FCH/Common/ChangeRequest/ChangeRequestResource.php`: masked values, `is_masked`.
- `app/Services/FCH/Common/ChangeRequest/ChangeRequestService.php`: `reveal()`.
- `app/Http/Controllers/Api/V1/FCH/Admin/IntakeReview/ChangeRequest/ChangeRequestController.php`: `reveal()`.
- `routes/admin.php`: `POST change-requests/{id}/reveal`.
- `lang/en/profile.php`: `change_request_nothing_to_reveal`.

## Testing
- New `tests/Feature/Borrower/ClientSensitiveFieldRevealTest.php` (12):
  - details masked, with no full number anywhere in the response; `null` when none is on file
  - reveal by field, for both fields; each reveal logged without the number
  - `null` when nothing is on file
  - staff who can open the client may reveal; another partner's client is a 404; only `ssn` / `business_ein` accepted
  - saving the masks back keeps the numbers; a new number typed in is saved
- New `tests/Feature/Borrower/ChangeRequestSensitiveValueTest.php` (4): SSN and EIN changes listed masked and other fields as they are; the reveal returns both and is logged; 422 for an unmasked field; 403 for a non-host.
- Both files failed first (404s, full numbers, 422 on the echoed mask), then passed: 16/16.
- `--filter "Borrower|Client|ChangeRequest|Profile|Ssn|Ein|ActivityLog|Lead|Identifier"`: 1655/1655 before the permission was dropped; the two new files again after it.
- Full suite: see the session report.

## Open items
- **Other screens still get a full SSN** from their own endpoints: funding applications (`ssn`, co-borrowers) and credit profile requests. They were not part of BUG-014.
- The borrower portal's own profile (`BorrowerProfileResource`) was not changed. It sends the client their own last four only.
- Reveals are logged but not limited. Add a permission if only some roles should see the numbers; the route is the one place to add it.
- Masks are recognised by `*`. A real SSN or EIN never contains one, but a client app that sends something else with a `*` in it would have that dropped silently.
