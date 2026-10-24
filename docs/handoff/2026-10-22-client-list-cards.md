# Clients list cards: host/partner split and suspended count

**Date:** 2026-10-22
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
The Clients list statistics (`POST common/borrowers/fetch-all-borrowers`, `statistics`) only gave total, active, inactive and new this month. The admin frontend now shows the host how its book splits between its own clients and its partners', and shows partners their suspended clients. Neither figure existed.

## Solution
`BorrowerService::list()` statistics gain three counts, from the same partner-scoped, non-archived snapshot as the existing cards:
- `suspended_borrowers`: status Suspended
- `host_borrowers`: clients of a host partner
- `partner_borrowers`: clients of any other partner

The existing keys are unchanged.

## Files changed
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: three new statistics

## Tests
- Added `tests/Feature/Borrower/ClientListCardsTest.php` (3 tests):
  - host split
  - partner by status
  - cards ignore the table's search
  - deleted and archived clients are excluded throughout
- The tests failed before the change and pass after.
- Feature/Borrower (175 tests) and Feature/Archive: OK.

## Open / follow-up
- None.
