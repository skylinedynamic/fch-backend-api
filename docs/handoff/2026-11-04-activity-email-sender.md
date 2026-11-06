# Activity New Email: the host picks which address it goes out from, and the email reads as a letter

**Date:** 2026-11-04
**Branch:** bugfix/round-2

## Problem
- **Wrong sender:** a host's New Email on a client went out with the host's own mail settings. `ActivityLogMail` uses `getConfigPartner()`, which is the caller's partner. The record's partner was computed in `store()` but not used for the mail.
- **No sender on screen:** the portal could not show which address the email goes out from.
- **What the user asked for:**
  - only the host sees the sender;
  - by default the email goes out from the client's partner's mail settings;
  - a switch sends it from the host's own;
  - one without mail settings of its own shows, and sends from, the fallback.

## Solution
- **`ActivityLog::EMAIL_SENDER_PARTNER` and `EMAIL_SENDER_HOST`** (`EMAIL_SENDERS`): whose mail settings a host's email goes out with.
- **`ActivityEmailSender`:**
  - `optionsFor($recordPartnerId)` returns `{default, options: {partner, host}}`. Each option is `{partner_id, name, address, uses_fallback}`, resolved as `MailConfig` sends: `resolveFor()` for the address, `usesOwnConfiguration()` for the fallback flag, and `MailSenderName::for()` for the name. A host record, or one with no partner, has only the host option, and the default is `host`.
  - `partnerIdFor($user, $sendFrom, $recordPartnerId)` returns the partner whose settings the mail uses. For the host, that is the record's partner, or the host when `send_from=host` (or for a host record). For anyone else it returns `null`, so their mail keeps `getConfigPartner()` and works as before.
- **`GET api/v1/common/activity-logs/email-sender?partner_id=`:**
  - host only; anyone else gets 403 with `activity_log.email_sender_host_only`;
  - `partner_id` is validated by `FetchEmailSenderRequest`;
  - the route is registered before `/{id}`.
- **`POST activity-logs`:** accepts `send_from`, which must be one of `EMAIL_SENDERS` (message `activity_log.send_from_invalid`). `sendEmail()` passes `partnerIdFor(...)` to `ActivityLogMail::setMailPartnerId()`.
- **`MailConfig::sendingPartnerId()`:** reads which partner's settings a message goes out with.

**The email itself (user requests: "mail body-r design valo na", then "follow the other designs"):** `emails/activity_log_mail.blade.php` used to read as a system notice wrapped around a message a person wrote: "Hello! You have received a new activity log update", then "ACTIVITY DETAILS / SUBJECT:", then "ACTIVITY CONTENT". It now follows the other emails (`activity_log/task_assigned`):
- the greeting the other emails open with, a 15px `#495057` `<h4>`: "Hello <name>," (user request, "greeting missing"). The name comes from `App\Support\Mail\RecipientName::for($address)`: the user with that address, else the client's or lead's first name, matched case-insensitively. With no match it says "Hello,";
- one intro line naming the sender (the mail's branded site name);
- the `#f8fafc` 4px card with the subject in bold (16px, `#0f172a`);
- an uppercase `#64748b` "Message" label and the message as written;
- the attached files as rows, each with a 32px `#eceef6`/`#405189` badge showing the extension (the task email's initials badge), and a note that they are attached.

- no sign-off after the card. One (the company, then "Support Team") was added and then removed at the user's request: the message is the sender's own.

The words are in `activity_log.mail.*`. The fallback subject `'New Email from Activity Log'`, hard-coded in `ActivityLogMail` and both activity-log services, is now `activity_log.mail.default_subject`.

## Files changed
- `app/Models/ActivityLog/ActivityLog.php`: the sender constants.
- `app/Services/FCH/Common/ActivityLog/ActivityEmailSender.php`: new.
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php`: `sendEmail()` uses the chosen sender.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/FetchEmailSenderRequest.php`: new.
- `app/Http/Requests/Api/V1/FCH/Common/ActivityLog/StoreActivityLogRequest.php`: the `send_from` rule and message.
- `app/Http/Controllers/Api/V1/FCH/Common/ActivityLog/ActivityLogController.php`: `emailSender()`.
- `app/Mail/Traits/MailConfig.php`: `sendingPartnerId()`.
- `routes/common.php`, `lang/en/activity_log.php` (also `mail.*`).
- `resources/views/emails/activity_log_mail.blade.php`: the shared email layout, with the greeting.
- `app/Support/Mail/RecipientName.php`: new; the name to greet an address by.
- `app/Mail/ActivityLog/ActivityLogMail.php`, `app/Services/Borrower/ActivityLog/BorrowerActivityLogService.php`: the default subject comes from lang.
- `tests/Feature/ActivityLog/ActivityLogMailDesignTest.php`: new.
- `tests/Feature/ActivityLog/ActivityEmailSenderTest.php`: new.

## Testing
- **`ActivityEmailSenderTest` (14):**
  - **Shown to the host:**
    - The client's partner is the default, with its own address.
    - The host option shows the host's own address.
    - An option without settings of its own shows the fallback, with `uses_fallback`.
    - The branded site name comes before the company name.
    - Nothing that can send gives a `null` address.
    - A host record has only the host option.
  - **Refused:** a partner gets 403, an unknown partner 422, a guest 401. Both messages exist.
  - **Sent:** a host's email on a client goes out with the client's partner's settings; `send_from=host` uses the host's; a partner cannot choose, and its mail goes out as before; an unknown `send_from` gets 422.
  - It failed first: 12 failures.
- **`ActivityLogMailDesignTest` (9):** a client is greeted by first name, a user by name (whatever the address's case), and an unknown address with a plain "Hello,"; the intro line naming the sender follows the greeting; the subject and message sit in the shared card; none of the old notice wording remains; files are listed under the message with their count; there is no attachments block without files; it ends with the card, with no sign-off after it; the words come from lang. It failed first.
- **Results:**
  - `tests/Feature/ActivityLog`, `tests/Integration/Mail`, `tests/Feature/MailConfiguration` and `tests/Feature/Borrower`: 588 passed.
  - After the greeting: `tests/Feature/ActivityLog` and `tests/Integration/Mail`, 211 passed.
  - `tests/Feature/Notification`: 44 passed.

## Open items
- **Unescaped body:** the message body is still output unescaped (`{!! $body !!}`). It is staff-written rich text, but it is not sanitized on the server. That is worth a separate change.
- Otherwise none. The portal side is in react-fch's `2026-11-04-new-email-recipient-from-and-to-style.md`.
