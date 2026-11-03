# Funding applications without a funding request: edit, lender, close with commission

**Date:** 2026-11-03
**Branch:** `bugfix/embed-form-application-edit` (from `release`) in `fch-backend-api`, `Fch-admin-frontend-react`, `fch-borrower-react`
**Author:** arafat-invento (with Claude)
**Findings:** `docs/test-findings.md` F169–F176, O74–O79

## Problem
Embedded Form > Loan Applications > **Create Client & Funding Application** creates a funding
application with no funding request (`funding_request_id` and `funding_sequence_id` null). Such an
application could not be edited, given a lender or closed properly:

- **Edit was missing.** The admin details page showed **Edit Funding Application** only with an
  approved funding request, and the edit wizard read every step by funding request.
- **Saves attached the wrong request.** A save sending only the application `id` reused that id as a
  funding request id (404, or request #id when the borrower had one), else attached the borrower's
  latest approved request. The partner step resource also guessed a request from
  `auth()->user()->entity_id` (the partner's id for a partner login).
- **The borrower portal** had the same Edit rule and resource guess.
- **No activity.** The conversion wrote no activity entry ("No activity found").
- **No lender.** Lender matching and assignment existed only on the funding request page.
- **Edit lock and commission** needed rework along the way (see Decisions).

## Decisions (2026-11-03)
| # | Rule |
|---|---|
| F169 | An application is editable whenever its status is in `config('funding_application.statuses.editable')` (11 statuses) - however it was created. Edit always opens by application id. |
| F171 | The same rule for everyone, the host's team included (replaces 2026-10-29: the host edited at any status). |
| F173 | Locked message per viewer: borrower "...Contact your funding advisor..."; host/partner "...can only be edited while their status is one of the editable statuses." One orange banner above the title, no "Can't Edit" button. Not shown once Closed (Completed) - the summary replaces it. |
| F172/F174 | Lender Information (host only). In the 11 editable statuses the lender is optional and changeable; every other status needs a lender before it is set (422; host gets the funding request page's "Lender Required" modal); past them the lender is fixed, except an application already past them with none (one assignment). |
| — | Commission is set in the **Closed (Completed)** modal (the Commission Details card is gone) and shown only once the application is Closed (Completed): host the whole breakdown, partner requested/funded and only their own commission. |

## Solution
### API (`fch-backend-api`)
- **Read by application id:** `PartnerFundingApplicationService::getFundingInfo` and borrower
  `FundingApplicationService::getFundingInfo` take `funding_application_id` (partner: `visibleTo`; borrower: own only).
- **Save keeps the request:** `PartnerFundingApplicationService::saveFundingApplication` keeps an
  existing application's request/sequence (none stays none), never reads `request('id')` as a request
  id; the latest-approved fallback applies only to a new application.
- **No guessing:** both `FundingRequestResource`s guess a funding request only when there is no application.
- **Conversion log:** `FundingApplicationAuditLogger::logCreatedFromEmbedForm` (in the conversion
  transaction): "Successfully Converted to Funding Application" with submission #, application number, actor, new/existing client.
- **Edit lock:** `FundingApplication::isEditableBy()` = status rule only; `lockedReasonFor($user)`
  words the reason per viewer; `FundingApplicationLockedException::for($app, $editor)` uses it.
- **Lender matching:** moved unchanged from `FundingRequest::getMatchedLendersAttribute` into
  `App\Services\FCH\Common\Funding\LenderMatchService` (several categories at once, best match per lender).
- **Lender assignment:** `POST common/request-application/funding-application/{id}/assign-lender`
  (`AssignFundingApplicationLenderRequest`: host's team only, one lender). With a funding request it
  writes the request's lender (shared with the request page); without one it stores on the
  application (`funding_applications.lender_ids`, `assign_lender` - migration
  `2026_11_03_000001_add_lender_to_funding_applications_table`, not fillable). Logged "Lender Assigned: …".
- **Status gate:** `fundingApplicationStatusUpdate` refuses a status outside the 11 without a lender (422).
- **Details resource** (`FundingApplicationResource`): host-only `matched_lenders`, `assigned_lender_ids`,
  `can_assign_lender`; everyone `lender_required_statuses`, `has_assigned_lender`, `funded_date`.

### Admin portal (`Fch-admin-frontend-react`)
- **Edit:** `/intake-review/funding-application/:applicationId/edit` (wizard reads by application id;
  hooks add the id to the cache key only when present). Edit button: `is_editable` + host or own partner.
- **Lock banner:** orange, above the title, first sentence bold; hidden once Closed (Completed).
- **Lender Information** (host only, shared `components/Common/Lender/LenderInformationCard.tsx`,
  also used by the funding request page): live search (`lenderFilter.ts`: every word, as shown -
  "$4,500+", "6 mo"), category/lender filters, Assign with confirm dialog; once fixed, just the assigned
  lender as a card. Notice while changeable. Placed below Application Status.
- **Lender Required:** picking a status outside the 11 with no lender and pressing Update Status opens
  the funding request page's modal; "Got it, take me there!" closes it, then scrolls and pulses the first row.
- **Closed (Completed) modal** (`components/Common/Modals/FundedAmountModal.tsx`,
  `assets/css/funded-amount-modal.css`): funded amount (defaults to the saved funded amount, else the
  requested amount - the request's for an application from one), commission (success fee; admin/partner
  rates for a partner's application), summary, lock warning. On confirm the commission is saved first
  through the existing save-commission API, then the status; `requested_amount` is never sent (it used
  to overwrite an application-from-a-request's amount with 0).
- **Closed summary** (`pages/applications/funding/components/ClosedApplicationSummary.tsx`), top of the
  left column: host "Funding Completed" (requested, funded, success fee, admin/partner commission);
  partner "Partner Commission Details" (requested, funded, their commission and payment status).
- **Other:** "Status is unchanged." instead of confirming nothing; Status Notes not marked required;
  "Full Name" → "Business Contact Name" in Business Information; steps and step card the same height.
- **Removed:** the Commission Details card (and its requested-amount edit), the partner "Your
  Commission Breakdown" card shown before closing.

### Borrower portal (`fch-borrower-react`)
- Edit / Continue open `/my-applications/funding/:applicationId/edit`; wizard reads by application id.
- Locked banner in the admin design, no "Can't Edit Application" button.

## Files changed
API: `PartnerFundingApplicationService`, borrower `FundingApplicationService`, both `FundingRequestResource`s,
`FundingApplicationResource`, `FundingApplication` model, `FundingRequest` model, `FundingApplicationAuditLogger`,
`FundingApplicationEditGuard`, `FundingApplicationLockedException`, `EmbedFormService`, `RequestApplicationService`,
`RequestApplicationController`, new `AssignFundingApplicationLenderRequest`, new `LenderMatchService`,
`config/funding_application.php`, `lang/en/application.php`, `routes/common.php`, new migration, `docs/test-findings.md`.

Admin: `App.jsx`, `FundingApplicationDetails.tsx`, `FundingRequestDetail.tsx`, `FundApplication.tsx` and
its steps, `modules/application/{services,queries,types}.ts`, `modules/funding-application/{services,queries}.ts`,
new `LenderInformationCard.tsx`, `lenderFilter.ts`, `ClosedApplicationSummary.tsx`, rewritten
`FundedAmountModal.tsx`, new `assets/css/funded-amount-modal.css`, `assets/css/funding-application.css`.

Borrower: `App.jsx`, `FundingApplicationDetailPage.tsx`, `FundingApplications.tsx`, `FundApplication.tsx` and
its steps, `modules/funding-application/{services,queries}.ts`, `MyApplications.css`.

## Tests
Backend
- Unit: `tests/Unit/FundApplication/FundingApplicationLenderRulesTest.php` - lender/lock rules, locked reason per viewer.
- Integration: `tests/Integration/Funding/LenderMatchServiceTest.php` - matching and scoring.
- Feature: `PartnerEditsApplicationWithoutFundingRequestTest`, `BorrowerEditsApplicationWithoutFundingRequestTest`,
  `EmbedFormConversionActivityLogTest`, `FundingApplicationLenderAssignmentTest`,
  `HostEditsOnlyEditableFundingApplicationsTest` (was `HostEditsAnyFundingApplicationTest`);
  updated `FundingApplicationDetailLockedReasonTest`, `RequestApplicationReviewTest` (lender before closing, funded date).

Admin (`tests/`): `components/Common/Lender/{lenderFilter,LenderInformationCard}.test`,
`components/Common/Modals/FundedAmountModal.test`, `pages/applications/funding/ClosedApplicationSummary.test`,
`modules/funding-application/services.applicationLookup.test`; `FundingApplicationDetails.test` extended
(Edit by id, lock banner, host-only lenders, close with commission, Lender Required, summary, no commission before closing).

Borrower (`tests/`): `modules/funding-application/{services,queries}.applicationLookup.test`; detail page,
list and wizard lock tests updated.

Results (2026-11-03) - RESULTS_PENDING

Coverage note: PHPUnit credits a test only with the classes in its `#[CoversClass]`; list every class a
test exercises, or its lines read as uncovered. Vitest skips its coverage report when a test fails -
pass `--coverage.reportOnFailure`.

## Open / follow-up
- **Deploy** runs the new migration (`funding_applications.lender_ids`, `assign_lender`). Run `php artisan migrate` locally.
- **Decision:** drop the redundant `lender_ids` (one lender only - `assign_lender` would do).
- O74 — draft-ready bell link uses the funding request id.
- O75 — no activity entry for applications converted before this change; backfill needs a decision.
- O76 — the funding request page can still change a request's lender at any status.
- O77 — the lender matcher's "years in business" text fallback never runs.
- O78 — admin `ActivityEditModal` test fails on `release` too; not from this branch.
- O79 — the details API still sends partners the host's commission figures (the page hides them).
- Applications already past the editable statuses with no lender keep their status; the gate applies when the status changes.
- Pint: the touched API files already fail `pint --test` on older code (O67); new files are formatted.
- Not committed or pushed.
