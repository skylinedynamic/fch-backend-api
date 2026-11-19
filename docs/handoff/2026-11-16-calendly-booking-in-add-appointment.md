# Calendly: booking from Add Appointment, phone-call event types, auto-sync

**Date:** 2026-11-16
**Branch:** `feature/calendly-integration` (fch-backend-api and Fch-admin-frontend-react)
**Author:** arafat-invento (with Claude Code)
**Follows:** [2026-11-10-calendly-integration.md](2026-11-10-calendly-integration.md)

## Problem
Testing the integration live on 2026-11-16 turned up these issues:
- **Booking was a separate form.** Booking into Calendly used its own "Book via Calendly" button and form. Users wanted it inside **Add Appointment**.
- **Phone-call event types couldn't be booked.** Calendly refuses a booking for an event type held as a phone call (`outbound_call`) or "invitee chooses" (`ask_invitee`) unless the invitee's number or place is sent with it. Only the location kind was sent (F260).
- **New and renamed event types didn't show.** Event types are cached for 5 minutes, and Sync now didn't refresh them, so a new or renamed event type stayed hidden (F261).
- **Deleted meetings stayed hidden.** A Calendly meeting deleted in FCH was never brought back, even while it was still on in Calendly (F262).
- **Meetings only arrived on demand.** New Calendly meetings appeared only after the hourly sync or a click on Sync now. Webhooks can't reach localhost.

Problems found along the way that were not code issues:
- The OAuth app had no scopes ticked ("requested scope is invalid").
- The event type had been deleted in Calendly, which caused the 404 and 400 errors.

## Solution
- **Admin Add Appointment.** A **"Sync with Calendly"** checkbox shows on new appointments only, and only when Calendly is connected and the user may book (`status.connected && status.can_book`).
  - Ticked, it replaces the Time Slot with the Calendly event type and Calendly's open times for the chosen person and date.
  - It asks for the phone number or place when the event type needs one.
  - Saving books through `POST /common/calendly/book` instead of `POST /common/appointments`.
  - Someone who isn't the Calendly owner books as the invitee, so the attendee picker is hidden for them.
  - The separate `CalendlyBookingModal` and the "Book via Calendly" button are removed.
- **API.**
  - `event-types` returns `location_kind` and `asks_invitee_location`.
  - `book` accepts `location`, and returns 422 `location` when the event type needs one and none was sent.
  - `syncNow()` clears the cached event types.
- **Auto-sync.** `CalendlyConnectCard` syncs once, without a toast, every time the Appointments page opens or reloads, as long as Calendly is connected and the user is on the host's team. It then reloads the calendar. If the account isn't connected, nothing runs.
- **Sync restores deleted meetings.** A soft-deleted appointment whose Calendly meeting is still active is restored. If the meeting is also cancelled in Calendly, the appointment stays deleted.
- **FCH matches Calendly** (F263; product owner decisions, 2026-11-16):
  - A meeting cancelled in Calendly is **removed** from the FCH calendar. It's soft-deleted with status cancelled.
  - A meeting that is already cancelled the first time sync sees it is never added.
  - After the 7-days-back / 60-days-ahead window, each sync re-checks every remaining Calendly appointment with `GET {event_uri}`. A changed time or a cancel is applied, and a 404 removes the appointment.
  - Deleting an event type in Calendly changes nothing in FCH until Calendly cancels its meetings, because Calendly keeps them booked.

- **Calendly: one FCH app, one connection per partner** (F268; product owner decisions, 2026-11-17). This replaces F267 (each owner's own app and keys) and F266 (the Settings page).
  - **One app, owned by FCH:** FCH registers **one** Calendly OAuth app per server (local, stage, production). Its `CALENDLY_CLIENT_ID` / `CALENDLY_CLIENT_SECRET` go in that server's `.env`, and its Redirect URI is `{API}/api/v1/calendly/callback`. Empty keys mean Calendly is off and nothing errors.
  - **Who connects:** the **host**, each **WLP** and each **certified partner**. Someone on the partner's team clicks **Connect Calendly** and signs in to the partner's own Calendly. No keys are typed. The connection is shared by the partner's team.
  - **Borrowers never connect.** In Add Appointment → **Sync with Calendly**, a borrower books with **their partner's** Calendly. The borrower is the invitee and picks nobody; the appointment is between the partner's connected user and the borrower. If the partner hasn't connected, no tick is offered.
  - **The partner's team books** into its own Calendly, inviting the person it picks (`canBookWith`, O20).
  - **Storage:** `calendly_connections` has a unique `partner_id`, the OAuth tokens (encrypted) and a `webhook_signing_key` that FCH generates (encrypted). Migration `2026_11_17_000001_make_calendly_connections_one_per_partner` drops `borrower_id`, `client_id` and `client_secret`, deletes borrower connections, and marks connections made with their own keys as "Reconnect".
  - **Access:** only the host, WLP or certified partner **account itself** (the login whose record is the partner) connects and disconnects. Its staff and other users may only sync and book. Borrowers get 403 on all three.
  - **Where it is configured:** admin portal **Settings → Calendly** (`/settings/calendly`), shown only when the API says `can_manage` (the account itself), and only while FCH's Calendly app is set up. It has Connect / Reconnect, Sync now and Disconnect, and Calendly returns the account there after sign-in. The Appointments card shows the status and Sync now (account and staff), plus a "Connect in Settings" link for the account.
  - **Webhooks:** each connection has its own address, `POST /calendly/webhook/{id}`, checked with its own signing key.
  - **Sync:** the hourly sync goes through every connection. One failing doesn't stop the others.
  - **Isolation:** a partner's Calendly invitee is linked only to that partner's users. The host's can link to anyone.
  - **One Calendly account, one partner:** an account already connected by another partner is refused.
  - **Removed:** the keys popup in both portals, the borrower portal's Calendly card and its connect/sync/disconnect services, and the per-owner keys.
- **Local setup after pulling:**
  1. Put the **FCH Local Dev** app's keys in `.env` as `CALENDLY_CLIENT_ID` / `CALENDLY_CLIENT_SECRET`.
  2. Run `php artisan migrate` and `php artisan config:clear`.
  3. In the admin portal, click **Reconnect Calendly**.

## Files changed
**fch-backend-api**
- `app/Services/FCH/Common/Calendly/CalendlyService.php`: location fields, location check on booking, cache cleared on sync.
- `app/Services/Global/Calendly/CalendlySyncService.php`: restore on sync.
- `app/Http/Requests/Api/V1/FCH/Common/Calendly/BookCalendlyAppointmentRequest.php`: `location`.
- `lang/en/calendly.php`: `location_required`.

**Fch-admin-frontend-react**
- `src/components/Common/Modals/AppointmentModal.jsx`: Sync with Calendly.
- `src/components/Calendly/CalendlyConnectCard.tsx`: auto-sync; booking button removed.
- `src/pages/appointments/Appointments.jsx`: separate booking modal removed.
- `src/modules/calendly/types.ts`: new fields.
- `src/components/Calendly/CalendlyBookingModal.tsx`: deleted.

## Tests
- **Backend:**
  - `Feature/Calendly/CalendlyBookingTest`: 3 new.
  - `Feature/Calendly/CalendlyConnectionTest`: 1 new.
  - `Integration/Calendly/CalendlySyncServiceTest`: "deleted stays deleted" replaced by 2 tests.
  - Full suite: 5292 passed, 2 skipped. That run was before F262; afterwards all 68 Calendly tests pass.
- **Admin:**
  - `AppointmentModal.test.tsx`: 11 new, which also cover the cases of the deleted `CalendlyBookingModal.test.tsx`.
  - `CalendlyConnectCard.test.tsx`: booking-button test updated, 4 auto-sync tests added.
  - Full suite: green before the auto-sync change. Affected files: 59 of 59.

## Left open
- The borrower portal (`fch-borrower-react`) still has its separate Calendly booking form.
- Auto-sync runs only for the host's team, because the sync route is theirs. Borrowers and partners see new Calendly meetings after the host's next sync or the hourly `calendly:sync`.
- Webhooks can't reach `localhost`. Locally, meetings arrive through sync only.
- D19 (notifications for Calendly bookings) is unchanged.
