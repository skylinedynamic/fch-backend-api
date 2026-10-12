# Findings batch — 2026-10-12 (index)

**Branch:** bugfix-awlad  
**Author:** Awlad Hossain

One handoff per issue fixed from `docs/test-findings.md` in this batch, plus what was deliberately left. Each linked file has the problem, the solution, the files changed, the tests and anything open.

## Fixed

| # | Was | Issue | Handoff |
|---|-----|-------|---------|
| F69 | U1, O3 | Pre-qualification | [2026-10-12-f69-pre-qualification.md](2026-10-12-f69-pre-qualification.md) |
| F70 | O14 | CI | [2026-10-12-f70-ci.md](2026-10-12-f70-ci.md) |
| F71 | O35 | Test isolation | [2026-10-12-f71-test-isolation.md](2026-10-12-f71-test-isolation.md) |
| F72 | O4 | Login | [2026-10-12-f72-login.md](2026-10-12-f72-login.md) |
| F73 | O5 | Borrowers | [2026-10-12-f73-borrowers.md](2026-10-12-f73-borrowers.md) |
| F74 | O7 | Credit profile | [2026-10-12-f74-credit-profile.md](2026-10-12-f74-credit-profile.md) |
| F75 | O8 | File manager | [2026-10-12-f75-file-manager.md](2026-10-12-f75-file-manager.md) |
| F76 | O11 | Seeders | [2026-10-12-f76-seeders.md](2026-10-12-f76-seeders.md) |
| F77 | O12, O30, O34 | Dead routes | [2026-10-12-f77-dead-routes.md](2026-10-12-f77-dead-routes.md) |
| F78 | O13 | Borrower portal | borrower-fch: `docs/handoff/2026-10-12-f78-borrower-portal.md` |
| F79 | O16 | Storage | [2026-10-12-f79-storage.md](2026-10-12-f79-storage.md) |
| F80 | O18 | Contacts | [2026-10-12-f80-contacts.md](2026-10-12-f80-contacts.md) |
| F81 | O21, O33 | Tests vs MySQL | [2026-10-12-f81-tests-vs-mysql.md](2026-10-12-f81-tests-vs-mysql.md) |
| F82 | O22 | OpenAI settings readers | [2026-10-12-f82-openai-settings-readers.md](2026-10-12-f82-openai-settings-readers.md) |
| F83 | O24 | Password change | [2026-10-12-f83-password-change.md](2026-10-12-f83-password-change.md) |
| F84 | O25 | Default payment method | [2026-10-12-f84-default-payment-method.md](2026-10-12-f84-default-payment-method.md) |
| F85 | O26 | Business info errors | [2026-10-12-f85-business-info-errors.md](2026-10-12-f85-business-info-errors.md) |
| F86 | O27 | Credit-account apply | [2026-10-12-f86-credit-account-apply.md](2026-10-12-f86-credit-account-apply.md) |
| F87 | O28 | Co-borrower form | [2026-10-12-f87-co-borrower-form.md](2026-10-12-f87-co-borrower-form.md) |
| F88 | O38 | Negative amounts | [2026-10-12-f88-negative-amounts.md](2026-10-12-f88-negative-amounts.md) |
| F89 | O40 | Scoring log noise | [2026-10-12-f89-scoring-log-noise.md](2026-10-12-f89-scoring-log-noise.md) |
| F90 | O41 | Writes on read | [2026-10-12-f90-writes-on-read.md](2026-10-12-f90-writes-on-read.md) |
| F91 | O42 | Log retention | [2026-10-12-f91-log-retention.md](2026-10-12-f91-log-retention.md) |
| F92 | O43 | Scheduler health | [2026-10-12-f92-scheduler-health.md](2026-10-12-f92-scheduler-health.md) |
| F93 | O45 | Reminders | [2026-10-12-f93-reminders.md](2026-10-12-f93-reminders.md) |
| F94 | O47 | Mail branding | [2026-10-12-f94-mail-branding.md](2026-10-12-f94-mail-branding.md) |
| F95 | O48 | Embed-form email | [2026-10-12-f95-embed-form-email.md](2026-10-12-f95-embed-form-email.md) |
| F96 | O49 | Verify assistant | [2026-10-12-f96-verify-assistant.md](2026-10-12-f96-verify-assistant.md) |
| F97 | O50 | Cron alert default | [2026-10-12-f97-cron-alert-default.md](2026-10-12-f97-cron-alert-default.md) |
| F98 | O52 | Stub payment drivers | [2026-10-12-f98-stub-payment-drivers.md](2026-10-12-f98-stub-payment-drivers.md) |
| F99 | O54 | SharePoint storage | [2026-10-12-f99-sharepoint-storage.md](2026-10-12-f99-sharepoint-storage.md) |
| F100 | O55 | MicroBilt | [2026-10-12-f100-microbilt.md](2026-10-12-f100-microbilt.md) |
| F101 | D8 | Domain resolver | [2026-10-12-f101-domain-resolver.md](2026-10-12-f101-domain-resolver.md) |
| F102 | D10 | SSNs at rest | [2026-10-12-f102-ssns-at-rest.md](2026-10-12-f102-ssns-at-rest.md) |
| F103 | D12 | Change-request email | [2026-10-12-f103-change-request-email.md](2026-10-12-f103-change-request-email.md) |
| F104 | D13, O44 | Restore safety | [2026-10-12-f104-restore-safety.md](2026-10-12-f104-restore-safety.md) |

## Not changed in this batch

| Finding | Why |
|---------|-----|
| D1 | Skipped 2026-10-12: backend permission checks deferred (product decision). |
| D2 | Skipped 2026-10-12: backend permission checks deferred (product decision). |
| D4 | Skipped 2026-10-12: backend permission checks deferred (product decision). |
| O6 | Skipped 2026-10-12: access scoping, deferred with the permission work. |
| O20 | Skipped 2026-10-12: access scoping, deferred with the permission work. |
| O23 | Skipped 2026-10-12: backend permission checks deferred (product decision). |
| D3 | Skipped 2026-10-12: 2FA changes excluded for now (product decision). |
| O1 | Skipped 2026-10-12: 2FA changes excluded for now (product decision). |
| O37 | Skipped 2026-10-12: 2FA changes excluded for now (product decision). |
| D5 | Skipped 2026-10-12: reCAPTCHA changes excluded for now (product decision). |
| D6 | Skipped 2026-10-12: reCAPTCHA changes excluded for now (product decision). |
| O29 | Skipped 2026-10-12: reCAPTCHA changes excluded for now (product decision). |
| D7 | Not changed 2026-10-12: the api./partner./app. prefix rule may be what lets white-label partners' own subdomains (e.g. api.<custom-domain>) get certificates; removing it needs a decision on those. |
| D9 | Not changed 2026-10-12: needs the decision described. |
| O17 | Not changed 2026-10-12: needs the decision described. |
| O31 | Not changed 2026-10-12: needs the decision described. |
| O32 | Not changed 2026-10-12: needs the decision described. |
| O39 | Not changed 2026-10-12: needs a decided page-size limit, with the admin screens moved first. |
| O51 | Not changed 2026-10-12: a new feature (webhook route + Stripe secret), not in scope. |
| O9 | Not changed 2026-10-12: dropping a local database is destructive - left to the developer. |
| O10 | Not changed 2026-10-12: dead-code removal not in scope. |
| O19 | Not changed 2026-10-12: dead-code removal not in scope. |
| O46 | Not changed 2026-10-12: dead-code removal not in scope. |
| O53 | Not changed 2026-10-12: dead-code removal not in scope. |
| O36 | Not changed 2026-10-12: a production data check, not code. |

## Before deploying

- Set `INTERNAL_RESOLVER_KEY` in every environment and on the edge proxy (F101) — without it every domain lookup is refused.
- Run `php artisan migrate` (F102 encrypts stored SSNs, F90 backfills play rows).
- Set `CRON_ERROR_NOTIFY_EMAILS` where scheduler alerts are wanted (F97).
- Run the O22 production query for stray non-host OpenAI rows (F82).
- Production restores now need `backup:restore --force` (F104).
