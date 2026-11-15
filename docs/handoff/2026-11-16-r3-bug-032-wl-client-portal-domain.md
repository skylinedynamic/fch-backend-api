# R3-BUG-032: a White-Label partner's clients are sent to the host's client portal

Date: 2026-11-16

## Problem

QA (R3-PP-AUTH-03-R2) tested QA WL partner 90. It has Active Custom URL off and no borrower domain. The partner added client 218 (Limited Free Plan) and client 219 (paid).

What QA saw:
- **218's welcome mail:** linked to `https://client-stage…/login`, the host's client portal. Signing in there gave 401 "Invalid email or password."
- **219's welcome mail:** had no link at all.
- **The partner's own domain:** the same credentials signed in on `qatestr3trial…`, which is the **partner** portal.

### Decision (2026-11-16)

- The host's client portal is only for the host's clients and Certified partners' clients.
- A White-Label partner's client never gets the host's portal. It gets its partner's `borrower_domain` when there is one, and no link otherwise.
- Active Custom URL is only the switch for the partner's own custom domain (`partner_domain`). Every partner has a `partner_subdomain` for its team.
- For a client, a `borrower_domain` counts as soon as it is set.

### What the code did against that

- **About 15 client email links** fell back to the host portal when `getBorrowerDomain()` was null. The free-plan welcome template built its own link this way, so 218 got client-stage.
- **Sign-in (`PortalIsolationService`)** let a client in on its partner's subdomain and custom domain. The host's clients were also let in on the host's partner portal. A `borrower_domain` only counted with Active Custom URL on.
- **`PortalUrlResolver`** (email-change links) used the host portal for a White-Label client without a domain. It also ignored a `borrower_domain` while Active Custom URL was off.
- **The edge (`DomainResolverController`)** neither routed a `borrower_domain` nor gave it a certificate while Active Custom URL was off.

## Solution

**One rule:** `Partner::borrowerPortalDomain()` returns:
1. `borrower_domain` when it is set
2. otherwise the shared client portal, for the host and Certified partners only
3. otherwise null

`getBorrowerDomain()` and `PortalUrlResolver` both use it.

- **Sign-in:** a client (entity Borrower) is accepted only on its partner's `borrower_domain`, or on the reserved borrower portal for the host's and Certified partners' clients. It is never accepted on a partner portal domain. Partner-team rules are unchanged.
- **Emails:** no client link falls back to the host portal any more. When there is no link, the template hides the button. This includes the generic notification layout.
- **Email-change links:** none for a client without a portal. A bare `?expires=…` link is not sent.
- **Password reset:** the host fallback is dropped. A reset is only sent from a portal the user may sign in on, so such a client never gets one.
- **Edge:** `resolve-custom-domain` and `caddy/domain-check` accept a `borrower_domain` whenever it is set. `partner_domain` still needs Active Custom URL on.

## Files changed

**Rule and sign-in:**
- `app/Models/Partner/Partner.php`: `borrowerPortalDomain()`.
- `app/Helpers/helpers.php`: `getBorrowerDomain()` delegates to it; the docblock now says not to add a fallback.
- `app/Services/Global/Common/PortalIsolationService.php`: the client sign-in rule.
- `app/Services/Global/Common/PortalUrlResolver.php`: a client uses `borrowerPortalDomain()`.
- `app/Services/Global/Account/EmailChangeService.php`: no link when there is no portal.
- `app/Http/Controllers/DomainResolverController.php`: `borrower_domain` is not gated by Active Custom URL.

**Host-portal fallback removed:**
- `app/Mail/AccountCreateNotificationMail.php`
- `app/Services/Global/Notification/Mail/UserCreateNotificationMail.php`
- `app/Mail/Trial/BorrowerTrialMail.php`
- `app/Mail/Borrower/{DraftApplicationSent,FundingApplicationSubmitted,CreditProfileUpdated}Mail.php`
- `app/Mail/Borrower/BorrowerAccountCreatedMail.php` (comment only)
- `app/Mail/EmbedForm/EmbedFormConverted.php`
- `app/Mail/FundingRequest/UnderwritingStatusUpdated.php`
- `app/Services/FCH/Subscription/EnrollmentService.php` (invoice payment links)
- `app/Services/GlobalPayment/PaymentMailService.php`
- `app/Services/Global/Notification/Types/PasswordResetNotification.php`

**Templates (button hidden when the link is empty):**
- `emails/account_created`, `emails/users_created`, `emails/generic-notification`
- `emails/borrower/{draft_application_sent,funding_application_submitted,underwriting_status_updated,funding_application_reminder,funding_request_approved,payment_link}`
- `emails/invoice/sent`
- `emails/trial/borrower_account_created`: now uses `$loginUrl` instead of building its own link
- `emails/trial/borrower_migration`

## Testing

**New tests, each seen failing first:**
- `tests/Feature/Auth/BorrowerPortalLoginTest.php`. A client:
  - signs in on its `borrower_domain` with Active Custom URL off
  - is refused on its partner's subdomain (both a White-Label and a Certified partner)
  - is refused on its partner's custom domain
  - is refused on the host's partner portal
  - is refused on another partner's `borrower_domain`
- `tests/Unit/Services/PortalUrlResolverTest.php`:
  - a White-Label client with Active Custom URL off uses its `borrower_domain`
  - a White-Label client without one gets no link
  - a Certified client uses its `borrower_domain` when it has one
- `tests/Integration/Mail/AccountAndPaymentMailRenderTest.php` and `RecordMailRenderTest.php`. For 18 client emails:
  - none links the host portal or shows an empty button for a White-Label client without a `borrower_domain`
  - they link the `borrower_domain` when there is one
  - a Certified or host client still gets the host portal
- `tests/Feature/EmailChange/EmailChangePortalLinksTest.php`: no link and no button for a client without a portal.
- `tests/Feature/Website/PublicWebsiteApiTest.php`: a `borrower_domain` is routed and certified with Active Custom URL off; `partner_domain` is not.

**Updated to the decision:**
- `a_borrower_domain_that_is_not_activated_yet_refuses_sign_in` is now `…signs_in_on_the_borrower_domain_with_the_custom_url_off`.
- O48 `the_converted_applicant_email_falls_back_to_the_portal_sign_in` now uses a Certified partner.
- The record-mail "fall back to the portal" test is replaced by the per-email checks above.
- The underwriting-status test now gives the partner a `borrower_domain`.
- The last line of D7 `an_api_app_or_partner_prefix_alone_gets_no_certificate` now expects OK: `app.gamma.test` is in the database as a `borrower_domain`.

**Results:**
- Mail: 113 passed.
- Auth: 115 passed.
- EmailChange: 93 passed.
- Website: 19 passed.
- Full suite (with BUG-031): 5,727 tests, all passed (2 skipped).

## Open items

- **Admin panel** (`Fch-admin-frontend-react`, `TenantConfigSection.jsx` / `TenantDomainConfig.jsx`): the `borrower_domain` input only shows with Active Custom URL on. Under the new rule it should be editable on its own. Not changed here.
- **A White-Label partner without a `borrower_domain`:** its clients have no portal at all, by decision. Their emails carry no button:
  - a paid client's invoice / payment link
  - email-change confirmation
  The host or partner has to set a `borrower_domain` for those clients to sign in, pay or confirm. QA's expected result for this case should be updated to "no link".
- **A Certified partner with a `borrower_domain`:** its clients now use that domain in every link and at sign-in. Before, `PortalUrlResolver` alone sent them to the host portal.
- **Retest on stage:** a `borrower_domain` added with Active Custom URL off also needs DNS and Caddy to pick it up.
