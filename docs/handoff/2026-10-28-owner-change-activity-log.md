# Activity log: name the people when a client's Assigned To, a lead's Lead Owner or a partner's Agent changes; keep SSNs out

**Date:** 2026-10-28
**Branch:** feature/activity-task-assign
**Author:** Awlad Hossain

## Problem
Changing who is responsible for a record left an entry in the activity log that didn't say who:

| Record | What the entry said |
|---|---|
| Client › Assigned To | "Client Updated", "Assign Id was updated to 14." (a user id) |
| Lead › Lead Owner | "Lead Details Updated", "Assigned Salesperson was changed." |
| Partner › Agent | "Partner Updated", "Assigned User was changed." |

None of them named the person before or after, and there was nothing to show under "View changes".

The client form also sends the SSN back on every save. Re-encrypting the same number gives a different ciphertext, so every client save also logged "Ssn Encrypted was updated to eyJpdiI6…", which wrote the encrypted SSN into an entry staff can read. A date of birth or EIN was written out in plain text the same way.

## Solution
- `ActivityLogService::describeOwnerChange($label, $fromId, $toId)` turns the two user ids into names:
  - It returns the sentence: "Lead Owner changed from Avery Staff to Blake Ops", "… set to Blake Ops" or "… removed (was Avery Staff)".
  - It also returns a `{label, from, to}` row.
  - A removed (soft-deleted) user is still named. A row that is gone entirely reads "user #<id>".
- `BorrowerService::update`, `LeadService::updateDetails` and `PartnerService::update` use it for `assign_id`, `salesperson_id` and `assign_to`. They take the previous value from `getPrevious()`.
  - The sentence goes in the entry's description, in place of the id or "was changed".
  - The row goes in `properties.changes`, which the resource already sends as `field_changes`, so the timeline shows it under **View changes**.
  - When the owner is the only thing that changed, the entry gets its own title: "Client Assigned To Changed", "Lead Owner Changed" or "Partner Agent Changed".
  - Mixed with other edits, the generic update entry names the people alongside the other fields.
- `Borrower::setSsnAttribute` ignores the SSN already on file, so sending the same number again is no change.
- `ActivityLog::isSensitiveField()` decides which fields are sensitive. It uses the markers the funding-application audit already used (`ssn`, `ein`, `tax_id`, `date_of_birth`, `account_number`, `routing`), now kept in one place, and `FundingApplicationAuditLogger` uses it too.
  - The client, lead and partner update entries record such a field as "SSN was updated", "Business EIN was updated" or "Date Of Birth was updated", with no value.
  - `ActivityLog::fieldLabel()` gives the readable name.
- The labels (Assigned To, Lead Owner, Agent), titles and sentences are in `lang/en/activity_log.php` (`owner_*`). The now-unused `partner.assigned_user` and `partner.field_changed` strings were removed.

## Files changed
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: `describeOwnerChange()`
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: Assigned To by name, its own title, `changes` row
- `app/Services/FCH/Common/Lead/LeadService.php`: Lead Owner by name, its own title, `changes` row
- `app/Services/FCH/Partner/PartnerService.php`: Agent by name, its own title, `changes` row
- `app/Models/ActivityLog/ActivityLog.php`: `SENSITIVE_FIELD_MARKERS`, `isSensitiveField()`, `fieldLabel()`
- `app/Models/Concerns/HasEncryptedSsn.php`: the same SSN is not re-encrypted (moved here from `Borrower.php` when merging into `bugfix/all-bugfix`, which moved the SSN accessors into this trait; leads get the guard too). Test: `tests/Unit/Models/HasEncryptedSsnTest.php` (4, borrower and lead; red without the guard)
- `app/Services/FCH/Common/Application/FundingApplicationAuditLogger.php`: uses the shared check
- `lang/en/activity_log.php`: `owner_*` and `sensitive_field_updated` strings
- `lang/en/partner.php`: removed `assigned_user` and `field_changed`

## Testing
- `tests/Feature/ActivityLog/OwnerChangeActivityLogTest.php` (9, new). All 9 failed before the fix and pass after:
  - client: changed, set, removed, and named among other edits
  - lead: changed, removed, and named among other edits
  - partner: changed, first set, and a deleted user named by id
- `tests/Feature/ActivityLog/SensitiveFieldActivityLogTest.php` (6, new). All 6 failed before the fix and pass after:
  - the same SSN is no change
  - changing only Assigned To logs nothing about the SSN
  - a changed SSN, a date of birth and a lead's EIN are logged without their value
  - which field names count as sensitive
- `php artisan test --filter='Lead|Partner|Borrower|ActivityLog|Client'`: 1391 passed. The 1 failure is `NavigationMapTest`, which fails without these changes too.

## Open items
- **Entries already saved** before this fix still hold the encrypted SSN (and any plain date of birth or EIN) in their description. Clearing them needs a one-off data fix, which was not done here.
- Older entries keep their old wording; only new changes are named.
- Other "who" fields (e.g. a funding request's assigned lender) still log the old way. They were not part of this report.
