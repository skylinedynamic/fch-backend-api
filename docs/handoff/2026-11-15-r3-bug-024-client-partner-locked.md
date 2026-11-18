# R3-BUG-024: a client's partner can't be changed after it is created

Date: 2026-11-15

## Problem

1. Signed in as Wanda (partner 63), QA re-sent client 159's details on `POST common/borrowers/159` with `partner_id: 88` (another partner).
2. The answer was 200 "Borrower updated successfully."
3. Wanda could no longer read the client. The host saw it under partner 88.

Any partner could hand its client, and its PII, credit and documents, to another tenant (Critical / P1). QA restored the client to partner 63.

Causes:
- `UpdateBorrowerRequest` accepted any existing `partner_id`.
- `BorrowerService::update()` wrote it.
- Creating a client already ignored another partner's id for non-hosts. Editing had no such guard.
- The client's login user also kept the old `partner_id`, so the client was left split across two partners.

**Decision (2026-11-15):** once a client is created, its partner never changes. This applies to the partner and to the host. The host and host staff can still edit every other detail of another partner's client.

## Solution

- **Request:** `partner_id` on a client edit must be the client's own partner, which the admin edit form sends.
  - Any other value gets 422 "A client stays with the partner it was created under and cannot be moved to another partner." (`borrower_validation.partner_id_locked`).
  - This applies to everyone, the host too.
- **`clientPartner()`** (used by the Assigned To team check) is always the client's own partner. The host-move branch is gone.
- **Service:** `BorrowerService::update()` never writes `partner_id` (it is in `$exceptFields`), in case another caller passes one.
- **Creating a client is unchanged.** The host still picks the partner a new client is filed under.
- **Admin:** no change needed. The client edit form already sends the client's own `partner_id` and has no partner picker.

## Files changed

- `app/Http/Requests/Api/V1/FCH/Common/Borrower/UpdateBorrowerRequest.php`: `partner_id` must match the client's partner, and `clientPartner()` no longer moves.
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: `update()` drops `partner_id`.
- `lang/en/borrower_validation.php`: `partner_id_locked`.
- `tests/Feature/Borrower/ClientPartnerLockedTest.php` (new, 6):
  - QA's move by a partner is refused, and the client stays
  - the host can't move it either
  - sending its own partner saves, for the partner and the host
  - the host and host staff still edit another partner's client details
  - an edit without `partner_id` saves
  - the service never writes a new partner

## Testing

- **Seen failing first:** 3 of the 6: the partner move, the host move and the service write. The other 3 pin edits that keep working.
- **`tests/Feature/Borrower`, `tests/Feature/General`:** 363 passed.
- **Full suite (after R3-BUG-021 to 024):** 5,655 tests, all passed (2 skipped).
  The host-staff test was added during that run; it passed on its own (6 of 6).

## Open items

- **Stage data:** client 159 was restored by QA. Check stage for any other client whose `borrowers.partner_id` differs from its login user's `users.partner_id`. That would be a client moved earlier.
- **If a client ever must move** (e.g. a partner merger), it now needs a deliberate data change, with the client's user, roles and documents moved together.
