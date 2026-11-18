# R3-BUG-026 (partner side): a partner's free trial runs through its end date and ends a running session

Date: 2026-11-15

## Problem

QA tested on a client's free plan. Decision (2026-11-15): **a client's free plan has no end date.** The admin form has no date field for it; QA set one through the API. So the client side of the bug doesn't apply.

The same code serves a **partner's free trial**, which does have an end date (required). Checking it showed the bug there:
- **Sign-in was refused on the last day.** `AuthService` used `Carbon::parse($end)->isPast()`, so from 00:00 on the end date. The Partners list's "Expired" filter (`end < today`) and the admin portal's menu lock (`today > end`) both treat the end date as a trial day.
- **Staff weren't checked.** A partner's staff user (entity Employee) was not checked against the partner's trial at all.
- **A running session was never stopped.** No API check looked at the trial after sign-in. A partner whose trial ran out, or was shortened by the host, kept working until it signed out.
- **The admin portal used a third boundary.** `ProtectedRoute` ended the trial at 23:59 **UTC** on the end date. That is hours early or late depending on the timezone. In UTC+6 a partner stayed in until 06:00 the next day.

## Solution

**One rule:** `Partner::freeTrialExpired()` is true when:
- the partner is on a free trial
- it has an end date
- the end date is before today, in the caller's timezone (`todayInRequestTimezone()`)

The host is never on a trial.

**Sign-in:** a partner's team (admin and staff) is refused only once the partner's trial has expired. The message is unchanged: "Your free trial has expired. Please contact … for assistance." A client's own login rule is untouched.

**A running session:** the new `EnsurePartnerTrialActive` middleware runs on every api route after auth, like `EnsureUserHasPartner`. For an expired partner's team it answers 401 with the same message. The admin portal's `apiClient` signs out on 401, so the next request ends the session. Guests, clients and the host's team are left alone.

**Admin portal** (Fch-admin-frontend-react): `ProtectedRoute` uses the local calendar day (`parseDateOnly` / `startOfToday`), the same as `subscriptionUtils`.

## Files changed

- `app/Models/Partner/Partner.php`: `freeTrialExpired()`.
- `app/Http/Middleware/EnsurePartnerTrialActive.php` (new).
- `bootstrap/app.php`: the middleware is on the api group, after auth.
- `app/Services/Global/Auth/AuthService.php`: the partner team's sign-in uses `freeTrialExpired()`. The client check now reads only a client's own plan.
- `tests/Feature/Auth/PartnerFreeTrialBoundaryTest.php` (new, 8):
  - the boundary
  - sign-in on the last day works
  - sign-in after it is refused, for admin and staff
  - an open session works on the last day and stops once the host shortens the trial, for admin and staff
  - the partner's clients are not signed out
  - a regular-plan partner is untouched

## Testing

- **Seen failing first:** 6 of the 8: the method, last-day sign-in, staff sign-in, and the two open-session tests. One more was failing only because of a test mistake, fixed before the code change.
- **`tests/Feature/Auth`, `Partner`, `Setting`:** 330 passed.
- **Full suite:** 5,664 tests, all passed (2 skipped).

## Open items

- **Clients:** the client create and edit API still accepts `free_trail_end_date` (only the API, not the form). A client given one through the API is refused at sign-in once it passes. Not changed here; drop that field for clients if wanted.
- **Stage data:** QA client 220's end date is set (2026-12-31). Clear it if the client should never expire.
