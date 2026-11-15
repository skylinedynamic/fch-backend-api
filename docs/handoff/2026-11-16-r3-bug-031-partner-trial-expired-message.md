# R3-BUG-031: a partner's "free trial expired" message tells it to contact itself

Date: 2026-11-16

## Problem

QA (R3-OP-PART-19-R2, R3-OP-PART-12-R1, R3-PP-AUTH-07-R1) found three faults with an expired free-trial partner (QA WL partner 90):
1. On the trial's last day the login was refused, but the Partners list still showed "Trial".
2. Sessions that were already open kept working after the trial expired.
3. The refusal read "Your free trial has expired. Please contact QA-TEST R3 Trial Co for assistance." That named the partner itself, so the owner was told to contact their own company. Only the host can extend a trial or convert the partner. This is the R1 BUG-113 fault, which was fixed on 27-Oct for the Inactive message only.

Parts 1 and 2 were already fixed by R3-BUG-026 (`d0ebee7f`, 2026-11-15, see `2026-11-15-r3-bug-026-partner-trial-boundary.md`):
- the trial is now valid through its end date
- `EnsurePartnerTrialActive` stops an open session once the trial expires

QA reported this bug on 11-Nov, before that fix. Only part 3 was left.

## Solution

`auth.free_trial_expired` no longer names the partner. It now reads "Your free trial has expired. Please contact administrator for assistance." This is the same approach as BUG-113's `partner_inactive` / `partner_suspended` messages.

Owner, admin and staff all get the same text, by decision (option 1 of the two discussed):
- It is simple and consistent with BUG-113.
- It does not show the host's brand on a white-label domain.

The alternative was to tell staff to contact their partner and the owner to contact the host. It was not chosen.

## Files changed

- `lang/en/auth.php`: `free_trial_expired` drops `:partner` and names the administrator.
- `app/Services/Global/Auth/AuthService.php`: the partner team's trial check at sign-in no longer passes the partner's name.
- `app/Http/Middleware/EnsurePartnerTrialActive.php`: the open-session check uses the same message.
- `tests/Feature/Auth/PartnerFreeTrialBoundaryTest.php`:
  - The expected message is now the literal administrator text. Before, it was built from the partner's name.
  - New test `the_expired_message_sends_the_owner_to_the_administrator_not_to_its_own_company`. For the owner, it checks both sign-in and an open session, and checks that the partner's name is absent.
  - The staff open-session test now also checks the message.

## Testing

- **Seen failing first:** 5 of 9 in `PartnerFreeTrialBoundaryTest`. The actual message was "…Please contact Partner Co … for assistance."
- **After the fix:**
  - `PartnerFreeTrialBoundaryTest`: 9 passed.
  - `tests/Feature/Auth`: 110 passed.
  - `Partner`: 136 passed.
  - `Setting`: 88 passed.
- **Full suite:** 5,682 tests, all passed (2 skipped).
- **Frontends:** neither frontend matches on this text. The admin `useTrialExpiredListener` toast has its own wording, which has no partner name.

## Open items

- **Retest:** QA should retest all three parts on stage.
- **Same pattern elsewhere:** the `auth.subscription_expired` and `auth.payment_not_completed` messages (`AuthService` ~L111-121) still name the partner. Those messages are shown to the partner's users, so a partner owner could be told to contact themselves there too. They are outside this bug and were not changed.
