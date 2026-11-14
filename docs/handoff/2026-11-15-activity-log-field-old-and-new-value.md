# Activity log shows a changed field's old and new value

Date: 2026-11-15

## Problem

When a field changed on a client, lead or partner, the Activity entry showed only the new value: "First Name was updated to Jonathan". What it was before could not be seen anywhere in the Activity. These entries also had no "Show changes" rows.

Only some entries already showed both values:
- Assigned To, Lead Owner and Agent
- funding application field edits
- status changes

Smaller issues found on the way:
- `partner.name_updated` had no translation, so a partner name change logged the raw key.
- A client save logged fields that went from empty to empty (`null` → `""`, e.g. Business Start Date, Date Of Birth) as changes.

## Solution

- **New `ActivityLogService::describeFieldChange($field, $from, $to, $label = null)`.** It returns:
  - the sentence, e.g. "First Name changed from Johnathan to Jonathan"
  - the `{label, from, to}` row for "Show changes", stored in `properties.changes`
- **Empty values:**
  - An empty value is written "empty".
  - Empty to empty is no change: no sentence and no row.
- **Sensitive fields** (SSN, EIN, date of birth, account number, routing) stay "SSN was updated". No values are written and there is no row, as before.
- **Where it is used:**
  - client edit (`BorrowerService::update`)
  - client business financials (`updateBusinessFinancials`)
  - lead details (`LeadService::updateDetails`)
  - lead contact info (`updateContactInfo`)
  - partner edit (`PartnerService::update`); "Name" for `full_name` and "Trial End Date" keep their labels
  - partner status (`updateStatusAgent`)
- **Old value:** `getPrevious()`, the same source the owner changes already use.
- **No admin change needed.** The admin's Activity "Show changes" (`ActivitySidebar.jsx`) renders `field_changes` for any entry.

## Files changed

- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: `describeFieldChange()`.
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: client edit and business financials use it, with "Show changes" rows.
- `app/Services/FCH/Common/Lead/LeadService.php`: lead details and contact info use it.
- `app/Services/FCH/Partner/PartnerService.php`: partner edit and status use it.
- `lang/en/activity_log.php`: `field_changed`, `field_empty_value`.
- `lang/en/partner.php`: `name_label`.
- `tests/Feature/ActivityLog/FieldChangeFromToActivityLogTest.php` (new, 8):
  - a client field, old to new, with its row
  - emptied and first-filled values say "empty"
  - an SSN is never shown
  - business financials
  - a lead detail
  - a lead contact field
  - a partner field and its name
  - a partner's status
- `tests/Feature/ActivityLog/OwnerChangeActivityLogTest.php`: the two "among other edits" tests expect the new wording for the other field.

## Testing

- **Seen failing first:** all 8 new tests.
- **`tests/Feature/ActivityLog`:** 166 passed.
- **Full suite:** 5,587 tests, all passed (2 skipped).

## Open items

- **Labels and ids:** labels still come from column names, and id fields show the raw id. For example, "Referral Partner Id changed from 56 to 60". Only the owner fields are shown by name.
- **Old entries** keep the old wording. Only new changes show both values.
- **Not covered:** contacts and employees log changes elsewhere and were not checked here.
