# BUG-155: Client profile cards no longer count deleted rows

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2
**Author:** awlad

## Problem
The header cards on the client profile in the Owner and Partner portals (admin frontend `ClientDetails.tsx`) showed counts that didn't match the tabs. In QA's case, a Driver's License file was deleted and a second one uploaded. The Documents card then showed **2** while the Documents tab listed **1**. The same thing happened on 29-Oct with client 144 (card 7, tab 6).

The counts come from `BorrowerService::getBorrowerProfileStats` (`POST common/borrowers-profile-states-details-count/{id}`, behind `host-partner`). That method built every count with raw `SELECT COUNT(*)` sub-selects. Raw SQL ignores soft deletes, so deleted documents were still counted. The same applied to deleted meetings, invoices and pre-qualifications, since all four models use `SoftDeletes`. The Documents card also counted credit reports, which the Documents tab leaves out (`withoutCreditReports`).

## Solution
The counts now go through the Eloquent models. That means:
- soft-deleted rows are excluded;
- Documents uses `BorrowerDocument::withoutCreditReports()`, the same filter the Documents tab uses.

The response keeps its shape (`id`, `total_meetings`, `total_invoices`, `enrollment_type`, `total_prequalification`, `total_tickets`, `total_documents`) and returns an object as before, so the frontend needs no change.

`enrollment_type` is not a count. It still comes from the latest subscription row with `withTrashed()`, so its behaviour has not changed.

A side benefit: the old SQL embedded the class name with doubled backslashes. That matched on MySQL but not on SQLite, so Meetings always came back 0 in tests. Using the models removes that problem too.

## Files changed
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: `getBorrowerProfileStats` counts through the models. Added imports for `Appointment`, `Invoice`, `PreQualificationRequest` and `Subscription`.

## Tests
- Added `tests/Feature/Borrower/ClientProfileStatsTest.php`:
  - a deleted document is not counted;
  - a credit report is not counted, so the card matches the Documents tab;
  - deleted meetings, invoices and pre-qualifications are not counted.
- Red first: with the old code, all 3 tests fail (Documents shows 2 instead of 1).
- `tests/Feature/Borrower`, `PartnerAdminApiTest` and `IntRouteParametersAreNumericTest`: 358 tests, all passed.

## Open / follow-up
- The partner profile stats (`PartnerService.php:1199-1202`) and `StatisticsService.php:23-26` also use raw `COUNT(*)` sub-selects that ignore soft deletes (meetings, clients, invoices, tickets). These were not reported by QA and are not changed here.
- QA can recheck on stage after deploy: delete a document, upload another, and the card should match the Documents tab.
