# BUG-173: Partner profile cards don't count deleted rows

**Date:** 2026-11-06
**Branch:** bugfix-round1-run2

## Problem
Host admin > Partners > QA-TEST WL Partner Co (`/partners/79`) showed **Total Clients 2** while its Client List tab listed 1 client. QA had created client #196 under this partner and then deleted it, and the card still counted that client.

The cause is `PartnerService::getPartnerProfileStats()` (`POST /admin/partners-profile-states-details-count/{id}`). It built every card from raw `DB::raw('(SELECT COUNT(*) FROM ...)')` sub-selects. Raw SQL ignores soft deletes, so a deleted client was counted. The same applied to **invoices, support tickets and meetings**, because `Borrower`, `Invoice`, `SupportTicket` and `Appointment` all use `SoftDeletes`. The tabs use Eloquent and leave deleted rows out, which is why the card and the tab disagreed. BUG-155 was the same issue on the client profile cards.

## Solution
The counts are now Eloquent queries, so soft-deleted rows are excluded:
- `total_clients`: `Borrower::where('partner_id', $id)->notArchived()`. Archived clients are left out too (the user's decision): an archived client leaves the working list, as on the Clients menu, where the default is "Active Only".
- `total_invoice`: `Invoice::where('partner_id', $id)`.
- `total_tickets`: `SupportTicket::where('partner_id', $id)`.
- `total_meetings`: same rule as before. It counts the partner's own appointments, plus those scheduled by or assigned to the partner's users (`users.partner_id`), each appointment once.
- `enrollment_type`: unchanged. It is the latest subscription row, including trashed rows as before, or 'N/A'.
- An unknown partner id still returns zeros and 'N/A'. The response keys are unchanged; the counts are now integers instead of numeric strings.

## Files changed
- `app/Services/FCH/Partner/PartnerService.php`: `getPartnerProfileStats()` rewritten; imports `Appointment` and `SupportTicket`.
- `tests/Feature/Partner/PartnerProfileStatsTest.php` (new).

## Testing
- `PartnerProfileStatsTest`, 4 tests:
  - `total_clients_does_not_count_an_archived_client`: red first (2 instead of 1), now green.
  - `total_clients_does_not_count_a_deleted_client`: red first (2 instead of 1), now green.
  - `the_other_cards_do_not_count_deleted_rows` (invoices, tickets, meetings): red first, now green.
  - `meetings_still_count_those_scheduled_by_or_assigned_to_the_partners_users_once_each`: a characterization test of the meetings rule. It passed before and after the change.
- `tests/Feature/Partner`, `ClientProfileStatsTest` and `IntRouteParametersAreNumericTest`: 119 passed.
- Local DB: the host partner has 41 client rows. One is deleted and one is archived (#127), so the card now shows 39, the same as Clients menu > host partner filter (Active Only).
- `tests/Feature/Partner`: 115 passed after the archived change.
- Full suite: see the final result in the session.

## Open items
- `FCH\Common\Statistics\StatisticsService::getPartnerProfileStats()` (the partner portal's own profile cards) uses the same raw `COUNT(*)` sub-selects for meetings, clients, invoices and tickets, so it also counts deleted rows. QA did not report it, and it is not fixed here.
- The partner profile's Client List tab (`getBorrowers`) has no archive filter, so it still lists archived clients. It can show one more client than the card. Hiding archived clients there was not requested.
