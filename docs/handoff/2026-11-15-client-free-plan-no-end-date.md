# A client's Limited Free Plan has no end date

Date: 2026-11-15

## Problem

Decision (2026-11-15, from R3-BUG-026): a client's Limited Free Plan never expires. The code still let a client's free plan get an end date, and refused sign-in once it passed:
- **Through the API.** The client create and edit API stored any `free_trail_end_date` sent. The admin form has no such field, but QA set one this way (clients 216, 220).
- **Through lead conversion.** Converting a lead to a client on the free plan gave it an end date 30 days out (`LeadService`), so such a client was locked out a month later.
- **At sign-in.** Sign-in refused a client whose end date had passed: "Your free plan has expired…".

## Solution

- **Create:** the client's `free_trail_end_date` is always null. The welcome is the free plan one (`borrower.account.free_plan_started`), never "trial until <date>".
- **Edit:** the client's `free_trail_end_date` is set to null whatever is sent. This also clears an old one on the next save.
- **Lead → client conversion:** no end date. Lead → partner conversion still gives the partner's free trial its 30 days.
- **Sign-in:** a client is never refused over a free plan end date. A partner's team still follows the partner's trial (`Partner::freeTrialExpired()`, R3-BUG-026).
- **Data:** migration `2026_11_15_000002_clear_client_free_plan_end_dates` clears `borrowers.free_trail_end_date`, e.g. QA client 220. `partners.free_trail_end_date` is untouched.

## Files changed

- `app/Services/FCH/Common/Borrower/BorrowerService.php`: create and edit store no client end date, and the free-plan welcome has no date.
- `app/Services/FCH/Common/Lead/LeadService.php`: lead → client conversion has no end date.
- `app/Services/Global/Auth/AuthService.php`: the client free-plan expiry check is removed.
- `database/migrations/2026_11_15_000002_clear_client_free_plan_end_dates.php` (new): the data fix.
- `tests/Feature/Borrower/ClientFreePlanNoEndDateTest.php` (new, 5):
  - create ignores a date
  - edit ignores a date
  - lead → client gets none
  - a client with an old date signs in
  - the migration clears client dates and keeps partner trials
- **Updated to the decision:**
  - `tests/Feature/Borrower/FreePlanWelcomeNotificationTest.php`: a date sent is ignored, so the free-plan welcome is sent.
  - `tests/Feature/Auth/BorrowerPortalLoginTest.php`: the "free plan expired" account state is removed.

## Testing

- **Seen failing first:** all 5 new tests.
- **`tests/Feature/Borrower`, `Auth`, `Lead`, `General`:** 575 passed.
- **Full suite:** see below.

## Open items

- **Run the migration on stage.**
- **Leftovers:** the admin's "Free Plan Expired" badge for a client (`badgeHelper.ts`) and `auth.free_plan_expired` are now unused. They are harmless and were left in place.
