# Partner Mail Notification recipients: lead and client emails

**Date:** 2026-11-19
**Branch:** feature/agreement-builder-modification

## Problem
The admin's Partner profile got a **Mail Notification** tab with **Lead Notification Recipient** and **Client Notification Recipient**, but nothing stored or used them.

What was wanted:
- **Lead email:** sent when a lead is created.
- **Client email:** sent when a client record is created, when a lead is converted to a client, or when +Add Client completes.
- Each goes to the partner's recipient for it when set (**replacing** the partner's main email), otherwise to the partner's main email.
- Storage in a **separate table**, so more notification types can be added later.

## Solution
- **Table `partner_notification_recipients`** (`partner_id`, `type`, `email`; unique on `(partner_id, type)`; cascade on partner delete).
  - Model `PartnerNotificationRecipient`: `TYPE_LEADS`, `TYPE_CLIENTS`, and `TYPES`, the one list to extend.
  - No row means the partner's own email.
- **`Partner::notificationRecipients()`** and **`Partner::notificationEmail($type)`**: the recipient for that type, else `partners.email`.
- **API** (`routes/admin.php`; no permission middleware for now, like Commissions):
  - `GET /admin/partners/{id}/mail-notification` lists every type `{type, label, help, email|null}` (labels in `lang/en/partner.php` › `mail_notification.types`). The admin draws its fields from this list.
  - `PUT /admin/partners/{id}/mail-notification` with `{recipients: {leads, clients}}`. An address saves, null/empty removes, a type left out stays as it is.
  - `UpdatePartnerMailNotificationRequest`: known types only; `nullable|email:rfc|max:255`, errors under `recipients.{type}`.
  - `PartnerNotificationRecipientService` handles list and sync, and logs a "Mail notification updated" internal activity entry with old → new per type.
- **Lead email:** `lead.created` / `LeadNotificationMail`.
  - `LeadService::storeDetails`: now sent to `$lead->partner` (or the host when the lead has no partner) via `notificationEmail(TYPE_LEADS)`.
  - Behaviour change: a lead a non-host partner adds used to email the person who added it; it now goes to the partner's lead recipient or main email. The bell keeps its old recipients.
  - Website `LeadService::store`: the host's lead recipient, else its email, else its contact person's.
- **Client email:** new type `partner.client.created` (bell and mail) with new `ClientCreatedMail` ("New Client Added", view `emails.partner.client_created`).
  - Sent from `BorrowerService::store`, the one place +Add Client, lead conversion and embedded-form conversion all create a client.
  - `store()` takes `Borrower::CREATED_VIA_MANUAL` / `CREATED_VIA_LEAD` / `CREATED_VIA_EMBED_FORM`, which sets the wording.
  - It is sent in `DB::afterCommit`, so a rolled-back conversion emails nobody. Failures are logged, not thrown.
  - It goes to the client's partner's `notificationEmail(TYPE_CLIENTS)`. A non-host partner or its staff adding a client means their own partner's recipient, never the staff member and never the host.
  - The bell goes to the partner's own login.

## Files changed
- `database/migrations/2026_11_19_000001_create_partner_notification_recipients_table.php`: new table.
- `app/Models/Partner/PartnerNotificationRecipient.php` (new); `app/Models/Partner/Partner.php`: relation and `notificationEmail()`.
- `app/Services/FCH/Partner/PartnerNotificationRecipientService.php` (new): list, sync, activity log.
- `app/Http/Requests/Api/V1/FCH/Admin/Partner/UpdatePartnerMailNotificationRequest.php` (new).
- `app/Http/Controllers/Api/V1/FCH/Admin/Partner/PartnerController.php`: `mailNotification`, `updateMailNotification`.
- `routes/admin.php`: GET/PUT `partners/{id}/mail-notification`.
- `app/Models/ActivityLog/ActivityLog.php`: `LOG_TYPE_PARTNER_MAIL_NOTIFICATION_UPDATE`.
- `app/Services/FCH/Common/Lead/LeadService.php`, `app/Services/Global/Website/Lead/LeadService.php`: lead email recipient.
- `app/Models/Borrower/Borrower.php`: `CREATED_VIA_*`.
- `app/Services/FCH/Common/Borrower/BorrowerService.php`: `store($data, $source)`, `notifyPartnerOfNewClient()`.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: passes `CREATED_VIA_EMBED_FORM` and `CREATED_VIA_LEAD` (LeadService).
- `app/Mail/Partner/ClientCreatedMail.php`, `resources/views/emails/partner/client_created.blade.php` (new).
- `config/notifications.php`: `partner.client.created`.
- `lang/en/partner.php` (labels, messages, activity), `lang/en/borrower.php` (`client_created_mail`).

## Tests
- New:
  - `tests/Feature/Partner/PartnerMailNotificationApiTest.php` (9);
  - `tests/Feature/Lead/LeadCreatedRecipientTest.php` (6);
  - `tests/Feature/Borrower/ClientCreatedNotificationTest.php` (7: +Add Client, fallback, bell, lead conversion, embedded-form conversion, failed save sends nothing, email wording).
- `php artisan test tests/Feature/Partner tests/Feature/Lead tests/Feature/Borrower tests/Feature/EmbedForm tests/Feature/Notification tests/Feature/Website tests/Integration/Mail`: **1105 passed**.
- Migration applied locally (`migrate:status`: Ran).

## Open items
- **Restart `php artisan queue:work`** so it picks up the new `ClientCreatedMail`.
- No permission middleware on the two routes yet (as decided); add it with the rest of the partner-profile permissions.
- Other partner emails (documents, funding requests/applications, embedded form, payments) still go to the partner's main email. Each could get its own type later: a constant in `TYPES`, a label, and `notificationEmail()` at its send site.
- Admin frontend: `Fch-admin-frontend-react` handoff `2026-11-19-partner-mail-notification-tab-and-one-line-tabs.md`.
