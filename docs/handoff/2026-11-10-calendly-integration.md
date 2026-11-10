# Calendly integration (company account)

**Date:** 2026-11-10
**Branch:** `feature/calendly-integration` (from `release`, in fch-backend-api, Fch-admin-frontend-react and fch-borrower-react)
**Author:** arafat-invento (with Claude Code)

## Problem
Meetings were booked in two places that didn't know about each other. Meetings booked in the company Calendly never showed on the FCH Appointments calendar, and meetings booked in FCH never reached Calendly. The host, borrowers and partners each saw only part of their schedule.

## Solution
One Calendly account, the company's on the **Free plan**, is connected over **OAuth 2.0** by a member of the host's team. Its meetings are mirrored as `appointments` rows, with **one row per Calendly meeting** (unique `calendly_event_uri`):

- **The appointment's two people.** `scheduled_by` is the host who connected the account. `assigned_for` is the FCH user whose email booked the meeting (case-insensitive match). So the meeting appears on the host's calendar and on the borrower's or partner's own calendar. When the invitee isn't an FCH user, `invitee_name` and `invitee_email` are kept instead.
- **Calendly → FCH, right away.** A user-scoped webhook subscription (`invitee.created`, `invitee.canceled`) calls `POST /api/v1/calendly/webhook`. Each delivery is checked against `Calendly-Webhook-Signature` (HMAC-SHA256 of `t.body` with `CALENDLY_WEBHOOK_SIGNING_KEY`, 3-minute tolerance against replays).
- **Calendly → FCH, catch-up.** `calendly:sync` runs hourly at :15 and covers 7 days back to 60 days ahead. The host also has **Sync now**, and a sync runs right after connecting.
- **FCH → Calendly.** `POST /common/calendly/book` uses Calendly's Scheduling API (`POST /invitees`) with one of the account's event types, at a slot Calendly reports open. If the host is booking, the invitee is the person they pick. If a borrower or partner is booking, the invitee is themselves, and only if the host is on their bookable list (`AppointmentService::canBookWith`, the O20 rule). The open-slot list also drops slots that clash with the host's or the invitee's FCH-only meetings, because Calendly doesn't know about those.
- **Cancel or delete in FCH** cancels the meeting in Calendly first (`POST {event}/cancellation`). If Calendly refuses, nothing changes in FCH and the user sees the error (502/422).
- **Editing** a Calendly meeting's time or length in FCH is refused (422, `appointment_date`), because Calendly owns the time. The Reschedule link moves it instead. Subject, description and meeting type can still be edited, and later syncs keep those FCH edits. A reschedule in Calendly carries them over to the new event (matched through `old_invitee`).
- **Security.**
  - Tokens are stored with the `encrypted` cast, hidden from serialisation and never logged.
  - Calendly refresh tokens are single-use, so refreshing happens under a cache lock.
  - The bearer token is only ever sent to `services.calendly.api_url`.
  - The OAuth `state` is a random, one-time value tied to the user who asked to connect, valid for 15 minutes.
  - Only the host's team (`host-partner` middleware) may connect, sync or disconnect. Borrowers may only call status, event-types, available-times and book, which were added to `RejectBorrowersFromPartnerPortal::BORROWER_ROUTES`.

Behaviour change: `AppointmentResource` now also returns `source`, `is_calendly`, `join_url`, `cancel_url`, `reschedule_url`, `invitee_name` and `invitee_email`.

### Setup (each environment)
1. In `.env`, set `CALENDLY_CLIENT_ID`, `CALENDLY_CLIENT_SECRET`, `CALENDLY_WEBHOOK_SIGNING_KEY` and, optionally, `CALENDLY_REDIRECT_URI` and `CALENDLY_WEBHOOK_URL`. See `.env.example`.
2. Register the redirect URI on the Calendly OAuth app **exactly**: `{APP_URL}/api/v1/calendly/callback`, or whatever `CALENDLY_REDIRECT_URI` is set to.
   On the same app, tick these **scopes**: `users:read`, `event_types:read`, `availability:read`, `scheduled_events:read`, `scheduled_events:write`, `webhooks:read`, `webhooks:write`. Each `:read` is needed as well as its `:write`: a token with only `scheduled_events:write` got 403 "Insufficient scope" on `GET /scheduled_events` in the live test on 2026-11-10. The authorize link asks for these (`CALENDLY_SCOPES`), and Calendly refuses any endpoint whose scope wasn't granted. The scope list was checked against Calendly's OpenAPI spec on 2026-11-10.
3. Run `php artisan migrate`.
4. In the partner portal, go to **Appointments → Connect Calendly** and sign in as the company Calendly account. You are sent back to `/appointments?calendly=connected`.

## Files changed
- `database/migrations/2026_11_10_000001_create_calendly_connections_table.php` — the connected account (encrypted tokens, webhook URI, status).
- `database/migrations/2026_11_10_000002_add_calendly_columns_to_appointments_table.php` — `source`, `calendly_*_uri`, `invitee_*`, `join_url`, `cancel_url`, `reschedule_url`.
- `app/Models/Calendly/CalendlyConnection.php` — new model.
- `app/Models/Appointment/Appointment.php` — new fillables, `SOURCE_*` constants, `isFromCalendly()`.
- `app/Services/Global/Calendly/`
  - `CalendlyClient` — API calls and token refresh.
  - `CalendlyOAuthService` — connect, disconnect, webhook subscription.
  - `CalendlySyncService` — upsert, range sync, webhook handling.
  - `CalendlyEventMapper` — pure mapping from a Calendly event to appointment columns.
  - `CalendlyWebhookSignature` — pure signature check.
  - `CalendlyException`.
- `app/Services/FCH/Common/Calendly/CalendlyService.php` — status, event types, open times, booking, webhook entry point.
- `app/Http/Controllers/Api/V1/FCH/Common/Calendly/CalendlyController.php`, plus `app/Http/Requests/Api/V1/FCH/Common/Calendly/*` — the endpoints.
- `app/Services/FCH/Common/Appointment/AppointmentService.php` — cancel and delete also cancel in Calendly; edits that change a Calendly meeting's time or length are refused.
- `app/Http/Controllers/Api/V1/FCH/Common/Appointment/AppointmentController.php` — a Calendly refusal on cancel or delete becomes an error response.
- `app/Http/Resources/FCH/Appointment/AppointmentResource.php` — the Calendly fields.
- `app/Http/Middleware/RejectBorrowersFromPartnerPortal.php` — the 4 borrower Calendly routes.
- `app/Console/Commands/SyncCalendlyEvents.php`, `routes/console.php` — the `calendly:sync` command, hourly.
- `routes/common.php` — the authenticated `/common/calendly/*` routes and the public `/calendly/callback` and `/calendly/webhook`.
- `config/services.php`, `.env.example`, `lang/en/calendly.php` — config and messages.

## Tests
- Added:
  - `tests/Unit/Calendly/CalendlyWebhookSignatureTest.php` — valid, stale, future and tampered signatures, and a missing key.
  - `tests/Unit/Calendly/CalendlyEventMapperTest.php` — UTC time, length, status, location, subject.
  - `tests/Integration/Calendly/CalendlySyncServiceTest.php`
    - Matching the invitee to an FCH user, and an invitee who isn't one.
    - Idempotent upserts that keep FCH's edits; cancellations; meetings deleted in FCH staying deleted; another user's events ignored.
    - Reschedule carry-over and pagination.
    - Token refresh (stored encrypted), a refused refresh marking the account `needs_reconnect`, and the token never sent to another host.
  - `tests/Integration/Console/SyncCalendlyEventsCommandTest.php`
  - `tests/Feature/Calendly/CalendlyConnectionTest.php`
    - The authorize link and the OAuth scopes it asks for (a Defect test: it first asked for none), host-only management, the callback storing the account and subscribing to webhooks, and reusing an existing webhook (409).
    - A forged or reused state, a declined authorization, status without tokens, disconnect, and sync.
  - `tests/Feature/Calendly/CalendlyWebhookTest.php` — booking and cancellation deliveries, a bad signature refused, unknown events and no account ignored.
  - `tests/Feature/Calendly/CalendlyBookingTest.php`
    - Event types and open times with FCH clashes removed.
    - A borrower booking and a host booking, an attendee required for the host, someone who may not book refused, a foreign event type refused, and no connection.
    - The Free-plan 429, Calendly refusing a slot, cancelling in FCH cancelling in Calendly, a cancellation Calendly refuses, and time edits refused.
  - `tests/Support/FakesCalendly.php` — shared Calendly payloads.
- Updated:
  - `tests/Integration/Console/ScheduleTest.php` — `calendly:sync` at `15 * * * *`.
- Suites run: `php artisan test` (Unit + Integration + Feature): 5285 passed, 2 skipped, 0 failed.

## Open / follow-up
- **D19**: Calendly bookings send no FCH email or bell, because Calendly emails both people.
- **O81**: rotate the Calendly client secret and signing key (they were shared in chat).
- **O82**: the Free plan limits (5 bookings a day through the API, 1 event type).
- **O83**: webhooks need a public HTTPS URL; on localhost, meetings arrive through sync.
- Only meetings **with the connected host** can be in Calendly. A partner↔borrower meeting with no host stays FCH-only. Partners who want their own Calendly would need per-user connections, which is a later change.
- The disabled Microsoft 365 sync (`syncWithMicrosoft`) is untouched.
