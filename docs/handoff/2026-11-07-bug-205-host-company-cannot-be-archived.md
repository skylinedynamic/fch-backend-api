# BUG-205: the host company cannot be archived or deleted, and says so

**Date:** 2026-11-07

## Problem
As Host Admin, Partners → tick the host company (Four Corner Holdings) → Actions → Archive Selected showed "Partner(s) archived successfully.". Nothing was archived: the host stayed Active and "Archived Only" stayed empty.

`PartnerService::archive()` skipped the host (`where('is_host', false)`) and answered with the success message whatever it archived (`archived: 0`). `delete()` did the same and answered "Partner deleted successfully.".

## Solution
Decided 07-Nov: the host company cannot be archived and its row cannot be ticked.
- **API:** `archive()` and `delete()` refuse a selection that includes the host, with 422 and `partner.error.host_protected`: "The host company cannot be archived or deleted. Unselect it and try again.". Nothing in the selection is changed. `selectsHost()` is the shared check.
- **Panel:** the host's row checkbox is disabled. See the admin handoff.

## Files changed
- `app/Services/FCH/Partner/PartnerService.php`: `archive()` and `delete()` refuse the host; `selectsHost()`.
- `lang/en/partner.php`: `error.host_protected`.
- `tests/Feature/Partner/PartnerAdminApiTest.php`: new `archiving_or_deleting_the_host_is_refused_with_a_message_not_reported_as_done`. Archive and delete, with the host alone and with another partner: each gives 422 with the message, and nothing is archived or deleted.
- Admin panel: `Fch-admin-frontend-react/docs/handoff/2026-11-07-bug-205-host-row-not-selectable.md`.

## Testing
- **New test:** failed first (200 instead of 422), then passed. `tests/Feature/Partner`: 116 passed.
- **Live, admin spec against the real API:** the API answers 422 with the message for archive and for delete of the host.

## Open items
- `check-deletable` already reported the host as not deletable. Unchanged.
- Not committed.
