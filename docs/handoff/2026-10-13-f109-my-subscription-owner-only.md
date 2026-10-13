# My Subscription for the owner only (F109, was O23)

**Date:** 2026-10-13
**Branch:** bugfix-awlad
**Author:** Awlad Hossain
**Findings:** `fch-api/docs/test-findings.md` — F109 (was O23)

## Problem
- **The host check covered only the read.** `GET /common/my-subscription` refused the host with 403. `POST update-payment-method` and `POST cancel-subscription` did not.
- **No route checked who called it.** Any staff user of a partner, for example a salesperson with only "view leads", could cancel the partner's subscription or change how it pays the host.
- **Email fallback.** `updateDefaultPaymentMethod()` looked up a partner by the user's **email** when it could not resolve one, which could match the wrong partner.

## Solution
**Decision (user):** My Subscription is for the partner itself. Its staff (Employee/User logins) may not see or change it. No Spatie permission is used.

- `MySubscriptionService::ownPartnerOrRefusal()` runs first in every My Subscription action: read, change payment method, remove payment method and cancel. It returns the caller's partner only if all of these hold:
  - the caller has a partner (otherwise 404);
  - the partner is not the host (otherwise 403 `partner.my_subscription_non_host_only`);
  - the caller is that partner's own login: `entity_type` Partner and `entity_id` = the partner (otherwise 403 `partner.my_subscription_owner_only`).
- The email fallback is gone. The partner comes from `$user->partner`.
- **Partner portal:** no change needed. `NonHostPartnerRoute` and the header link already show My Subscription only to a non-host partner owner. The backend was the gap.

## Files changed
- `app/Services/FCH/Common/MySubscription/MySubscriptionService.php`
- `lang/en/partner.php` (`my_subscription_owner_only`)

## Tests
- `Feature/Partner/PartnerAccountApiTest`, written to fail first and then passing:
  - `a_partners_staff_cannot_reach_my_subscription`: read, change, remove and cancel all return 403, and nothing changes;
  - `the_host_cannot_change_cancel_or_remove_through_my_subscription`.
- The existing owner tests (read, cancel, change method, Stripe refusal) still pass unchanged.
- react-fch `tests/lib/auth/NonHostPartnerRoute.test.tsx`: an Employee login is redirected. This characterises the existing guard, so it passed first time.
- Suites run:
  - fch-api full suite: 2282 passed, 42 skipped, 0 failed; real `storage/app` unchanged.
  - react-fch `npm test`: 665 passed; typecheck clean.

## Open / follow-up
- What cancelling does, and how a cancelled subscription is reactivated, was deferred. It is recorded as **O56**.
- Removing the default payment method is F110 (`2026-10-13-f110-remove-default-payment-method.md`).
