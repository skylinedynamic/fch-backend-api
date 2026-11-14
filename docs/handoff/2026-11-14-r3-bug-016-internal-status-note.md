# R3-BUG-016: a funding application's status note is internal unless marked "Visible to client"

Date: 2026-11-14

## Problem

Host Admin › Intake & Review › Funding Application › app 107 (a Wanda client). QA changed the status with the note "QA-TEST internal: lender X declined, try Y". The borrower's portal (My Applications › Activity) showed "Status updated to: Approved (Final) Note: QA-TEST internal: lender X declined, try Y".

- **No visibility hint.** The admin form says only "Status Notes" and gives no sign that the client will see the note.
- **Pre-filled box.** The box held the last note, so an old note could be sent again by accident.

Causes:
- `applyFundingApplicationStatus()` appended every note to the status-change activity entry, which is saved with `VISIBILITY_EVERYONE`.
- `FundingApplicationResource` returned `status_note` to everyone, the borrower too. The borrower portal opens its application through `common/request-application/funding-application/{id}`.

## Solution

- **New flag `status_note_visible_to_client`** on the request and on `funding_applications` (boolean, default false). A note is internal unless the team sends `true`.
- **Activity:**
  - **The status change** is still one entry for everyone: "Status updated to: …".
  - **A visible note** goes in that entry, as before: "Note: …".
  - **An internal note** gets its own "Internal Status Note" entry.
    - Written by the host's team: `VISIBILITY_INTERNAL`, so only the host's team sees it.
    - Written by a partner's team: `VISIBILITY_PARTNER`, so that partner's team and the host see it.
    - This follows `FundingApplicationDocumentService`.
  - **An internal note without a status change** adds nothing the client can see.
- **Application data:** a borrower gets `status_note: null` unless the note is visible. The team also gets `status_note_visible_to_client`.
- **Repeat check:** the same note with the flag changed (internal → visible) counts as a change, not a double-click.
- **Audit trail** is unchanged. It records `status` and `status_note` for the team only.

## Files changed

- `database/migrations/2026_11_14_000001_add_status_note_visible_to_client_to_funding_applications_table.php` (new): the column.
- `app/Models/Borrower/FundingApplication.php`: fillable and boolean cast.
- `app/Http/Requests/Api/V1/FCH/Common/Application/UpdateFundingApplicationStatusRequest.php`: `status_note_visible_to_client` (sometimes, boolean) and its message.
- `app/Services/FCH/Common/Application/RequestApplicationService.php`:
  - saves the flag with the note
  - the repeat check includes the flag
  - the activity logging moved into `logFundingApplicationStatusChange()`, which writes the shared entry and the internal one
- `app/Http/Resources/FCH/Common/Application/FundingApplicationResource.php`: hides an internal note from a borrower and adds the flag.
- `lang/en/application.php`: `activity_internal_note_title`, `status_note_visible_boolean`.
- `tests/Feature/Application/FundingApplicationInternalStatusNoteTest.php` (new, 9):
  - internal by default
  - the host's internal note is not seen by the partner
  - a partner's internal note is seen by its team and the host
  - a visible note reaches the client
  - an internal note on its own adds nothing for the client
  - the client's application data hides an internal note
  - the team gets the note and the flag
  - flipping the flag is not a repeat
  - the flag must be a boolean
- `tests/Feature/Application/RequestApplicationReviewTest.php`: the two note-logging tests now send `status_note_visible_to_client: true`. They check the client-visible format, which is unchanged.

## Testing

- **Seen failing first:** all 9 new tests. The borrower-data test was also run without the resource change: it fails ("'Internal only' is identical to null").
- **`tests/Feature/Application`, `Audit`, `ActivityLog`, `Commission`:** 405 passed.
- **Full suite:** 5,579 tests, all passed (2 skipped).

## Open items

- **Migration:** run it on stage. Existing notes become internal (false). They were all shown to clients before, and stay in the activity entries already written.
- **Pre-qualification:** has its own Status Notes and bell/mail note (`PreQualificationStatusNotificationTest`). It is not in this bug and is unchanged.
- **Retest:** QA's app 107 note from 11-Nov is already in the borrower's Activity. Only new notes follow the new rule.
