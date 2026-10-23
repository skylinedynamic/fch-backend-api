# White-label partners get the embed-form submission email through the fallback mailbox

**Date:** 2026-10-23
**Branch:** `hotfix` (uncommitted)
**Author:** awlad

## Problem
A white-label partner was not emailed when its embedded form was submitted, but the host was.
- Reproduced with submission #7 (`xumeneji@mailinator.com`), partner #41 Dickerson Justice Associates.
- `EmbedFormService::sendNotificationEmail()` dropped the partner's email whenever a white-label partner had no **verified** mail settings of its own. Partner 41 had SMTP saved but `verified_at` empty.
- The log said: `Embed form notification skipped: white-label partner has no usable mail configuration of its own`. It also happened today for partners #39 and #35.

All other mail on the platform falls back to the host-kept platform mailbox in this case (`EmailConfigurationService::configurationFor()`), still under the partner's own sender name (`MailSenderName`). This one notice was the only exception, and it is an email *to the partner itself*.

## Solution
The white-label skip is removed. The partner's notice now follows the platform rule: its own verified settings, otherwise the platform fallback, sent under the partner's name.
- Unchanged: the text message for white-label partners (own Twilio only), the host copy, and the bell.
- If the host has no verified fallback either, `MailConfig` still sends nothing, as for any other mail.

## Files changed
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: removed the white-label skip in `sendNotificationEmail()`, the now-unused `hasOwnMailConfiguration()` helper, and the `EmailConfigurationService` import.
- `lang/en/embed_form.php`: removed `log_notification_skipped_no_mail_config`.
- `tests/Feature/EmbedForm/EmbedFormNotificationMailTest.php`:
  - the white-label cases (none, incomplete, not enabled) now expect the email
  - a new case for saved-but-unverified settings
  - the bell case asserts the email too
  - the `mailConfig()` helper takes `verified => '0'`
  - the class docblock describes the new rule

## Tests
- The 5 updated or new white-label tests failed against the old code.
- After the change: `tests/Feature/EmbedForm` **128 passed**. With `tests/Unit/Notification` and `tests/Unit/EmbeddedForm` included: **401 passed**.
- Local check: partner 41 now resolves to fallback config #3 (`fallback@mail.com`, verified), sender name "May Company".

## Open items
- `NewSubmissionMail` is queued (`QUEUE_CONNECTION=database`), so a queue worker must be running for it to leave.
- Submission #7 is not re-sent. Only new submissions are affected.
- Not committed.
