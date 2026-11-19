# Calendly: meetings in both people's Calendly, Pull / Push / Two-way sync, details in Calendly, secrets write-only

**Date:** 2026-11-18
**Branch:** `feature/calendly-integration` (fch-backend-api, Fch-admin-frontend-react, fch-borrower-react)
**Author:** arafat-invento (with Claude Code)
**Follows:** [2026-11-16-calendly-booking-in-add-appointment.md](2026-11-16-calendly-booking-in-add-appointment.md)

## Problem
Testing live on 2026-11-18 turned up these issues:
- **The meeting showed in only one Calendly.** Calendly lists a meeting only in its host's account. When a partner booked with a borrower who had connected their own Calendly, the borrower's Calendly stayed empty; they only got the invitation email.
- **Sync now only pulled.** There was no way to send FCH meetings to Calendly, or to choose which dates to sync.
- **The description never reached Calendly.** To name the meeting after the FCH title, FCH booked a Calendly *one-off* meeting. One-off meetings have no description field (only in-person or inbound-call locations take `additional_info`), so the description was dropped.
- **Settings → Calendly showed the Client Secret and Webhook Signing Key in full.** The API returned them decrypted, so they also showed in the browser's network tab and in screenshots.
- **Meetings stuck on a removed connection.** A borrower who connected Calendly again got a new connection row; their old meetings kept pointing at the deleted one, and every sync logged 403 "Skipped a meeting of another Calendly account".
- **The Disconnect modal used a party-popper icon.**

## Solution
- **A copy in the other person's Calendly.** When the person invited has their own Calendly connected, the meeting is also booked there, with the booker as its invitee. Both accounts then host it, so both Calendar pages list it. FCH keeps one appointment.
  - New columns `calendly_mirror_event_uri` (unique), `calendly_mirror_invitee_uri`, `calendly_mirror_connection_id` (migration `2026_11_18_000001_add_calendly_mirror_columns_to_appointments_table`).
  - Sync and webhooks recognise the copy and never take it in as a second appointment. A short cache guard (`MIRRORING_CACHE_PREFIX`) covers the moment between booking the copy and recording it.
  - Cancel and delete cancel both meetings. A new time or title cancels and rebooks the copy too. The other person's copy is skipped quietly if they have no Calendly; their Calendly refusing it never undoes the meeting (it is logged).
- **Sync now → Pull / Push / Two-way.** Both portals open a modal with one choice, for the dates the calendar shows (Month / Week / Day), else the current month.
  - `POST /common/calendly/sync` takes `direction` (`pull` | `push` | `both`), `from`, `to` (`Y-m-d`, up to 93 days) and `timezone`. Without `direction` it behaves as before (the page's quiet sync on opening).
  - **Pull:** Calendly meetings in the dates come into FCH, and upcoming FCH meetings others set with the caller go into the caller's Calendly.
  - **Push:** upcoming FCH meetings the caller set go into the caller's Calendly and into the other person's own Calendly.
  - **Two-way:** Pull, then Push.
  - Only upcoming meetings are sent; nothing is booked twice; a meeting still linked to a deleted connection is booked again in the current accounts. Calendly refusing a meeting is reported by name; a 429 (booking limit) stops the rest.
  - Push needs the caller's own Calendly: a borrower without one gets Pull only (Push and Two-way disabled), and the host account cannot push into another partner's Calendly (422 `calendly.push_needs_own`).
- **Meetings named after the FCH title (updated 2026-11-19).** Every booking (Add Appointment, reschedule, the copy, Push / Pull) is a Calendly one-off meeting named after the FCH title, so Calendly's lists show the title. Calendly's one-offs have no description field: the description goes with it only for an in-person or inbound-call place. The booking always names the place (Google Meet etc.) - without it Calendly refuses with "invalid location choice", the cause of the errors on 2026-11-18/19. If Calendly refuses the named meeting (no `event_types:write`, plan, place), it is booked on the event type instead, the title and description in its details (`detailsAnswer`). The `calendly:retitle` command stays removed.
- **Secrets are write-only, as on OpenAI Configuration.**
  - The Client Secret, Webhook Signing Key and both OAuth tokens stay encrypted at rest (Laravel `encrypted` cast, `APP_KEY`).
  - `GET/POST /common/calendly/settings` no longer returns `client_secret` or `webhook_signing_key`; only `has_client_secret` and `has_webhook_signing_key`.
  - Settings → Calendly shows a saved secret as a dots stand-in in a password field. Left alone it keeps the saved one; typed over, it replaces it. The Client ID is masked with an eye button, as the OpenAI Agent IDs are.
- **Nothing syncs by itself (2026-11-19).** Connecting only signs the account in - no Calendly meeting is read and no appointment is made. The page-open quiet sync (both portals) and the hourly `calendly:sync` schedule are removed: an appointment is made from a Calendly meeting only when someone presses **Sync now** (Pull / Two-way). `php artisan calendly:sync` still runs by hand.
- **No Calendly settings in `.env`.** Partner keys are in Settings > Calendly (database); the redirect and webhook addresses are worked out from the API's own address (127.0.0.1 written as localhost), and the redirect address of the sign-in is reused for the token swap.
- **Disconnect modal** (both portals) shows the Calendly logo instead of the party popper.

## Files changed
**fch-backend-api**
- `app/Services/FCH/Common/Calendly/CalendlyService.php`: the copy (`bookMirror`, `moveMirror`, `cancelMirror`, `bookInto`), `syncDirected` and `ensureInCalendly`, `detailsAnswer`, write-only settings; one-off booking and retitle methods removed.
- `app/Services/Global/Calendly/CalendlySyncService.php`: copies recognised; guard while a copy is booked.
- `app/Services/FCH/Common/Appointment/AppointmentService.php`: cancelling cancels the copy.
- `app/Http/Controllers/Api/V1/FCH/Common/Calendly/CalendlyController.php`, `app/Http/Requests/.../CalendlySyncRequest.php` (new): sync direction and dates.
- `app/Models/Appointment/Appointment.php`, the new migration, `lang/en/calendly.php` (`push_needs_own`).
- `app/Console/Commands/RetitleCalendlyMeetings.php`: deleted (meetings are no longer named after the title).

**Fch-admin-frontend-react** and **fch-borrower-react**
- `CalendlySyncModal.tsx` (new, same in both), `modules/calendly/syncDates.ts` (new), Calendly card, Appointments page, `modules/calendly/services.ts` and `types.ts`.
- Disconnect modal icon: the shared `ConfirmationModal` takes an optional `iconNode`.
- Admin only: `pages/settings/components/CalendlySettings.tsx` (write-only secrets, masked Client ID).

## Tests
- **Backend** `Feature/Calendly`: 90 pass. New or rewritten: the copy (booked, synced, cancelled, refused), Pull / Push / Two-way, default month, booking limit, removed connection, details answer (with and without a question, phone call), secrets never in the response and encrypted at rest. The retitle and one-off tests are removed.
- **Admin**: Calendly card (sync modal), Settings → Calendly (write-only secrets, masked Client ID), Appointment modal.
- **Borrower**: Calendly card (sync modal; Pull only without their own Calendly).
- Full-suite results: see the summary sent with this handoff.

## Left open
- **Run the migration** on each environment.
- **Meeting length follows the Calendly event type.** Pushing a 60-minute FCH appointment onto a 30-minute event type books 30 minutes.
- **The account needs at least one event type switched on** (its length, place and hours are used), even for named meetings.
- **`event_types:write` is required** for meetings named after the title; without it every booking falls back to the event type's name.
- **Calendly's booking limit is 5 per 24 hours on these accounts** (its 429 says `X-RateLimit-Limit: 5`, `Retry-After: 86400`). The message now says so, in hours, and the log keeps Calendly's figures.
- **Saving a different Client ID signs out the partner and every borrower** connected through the old app; each must connect again.
- **Calendly's own limits apply:** the booking limit (Free plan, 429), the owner's availability hours, and past times. The sync result names each refused meeting.
- Webhooks can't reach `localhost`; locally, meetings arrive through sync only.
- Meetings booked between 2026-11-18 and 2026-11-19 on the event type keep that name ("30 Minute Meeting"); their details hold the title.
- The secrets shown in earlier screenshots should be regenerated at developer.calendly.com.
