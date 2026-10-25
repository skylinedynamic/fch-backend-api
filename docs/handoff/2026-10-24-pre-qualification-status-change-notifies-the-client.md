# Pre-qualification — a status change notifies the client (BUG-063)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-063** (CP-NOTI-03): the owner moved pre-qualification 155 Pending → In Review → Approved; the client's bell showed nothing for either change.

`RequestApplicationService::preQualificationStatusUpdate()` saved the status and note and returned — no notification, and no notification type for it existed (only `partner.pre_qualification.submitted`, for the partner). The sheet had it as Fixed, but no fix was on any branch.

## Solution
- New type `borrower.pre_qualification.status_changed` in `config/notifications.php` — **bell only** (`database`, `broadcast`), as agreed: title "Pre-qualification {{status}}", body "Your pre-qualification is now {{status}}." plus " Note: …" when a note is given, link `/pre-qualification` (the borrower portal's page).
- `preQualificationStatusUpdate()` sends it to the borrower through `Notifier` when the status actually changes (old ≠ new). Saving the same status sends nothing. The partner is not notified (they made the change).
- No portal change needed: the client bell lists any type.

## Files changed
- `config/notifications.php` — the new type.
- `app/Services/FCH/Common/Application/RequestApplicationService.php` — the send on a status change.
- `tests/Feature/Application/PreQualificationStatusNotificationTest.php` — new, 4 tests.

## Tests
- New: In Review then Approved → two bell items with the right title, body and link; a note is carried; the same status again sends nothing; only the borrower is notified. Three failed first (nothing sent), pass after.
- `tests/Feature/Application`, `tests/Unit/Notification`, `tests/Feature/Notification`: 165 passed. Full suite: 3463 passed, 2 skipped.

## Open items
- No email for this yet — add a Mailable and switch the type to `$bellAndMail` if the client should get one.
- Mark BUG-063 in the QA defect log In Progress, then Fixed after the stage retest.
