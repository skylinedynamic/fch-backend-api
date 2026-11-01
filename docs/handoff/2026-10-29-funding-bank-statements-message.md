# Funding: bank statements message (from stage)

Branch `bugfix/funding-bank-statements-message` in fch-backend-api, fch-borrower-react and Fch-admin-frontend-react, cut from `stage`. Only the bank-statement message (items 9, 10 and 11 of `bugfix/qa-fixes-2026-10-15`, F127 / D14) was brought over. Nothing else from that branch.

## Problem
A funding option blocked by bank statements showed "Banking Activity: Failed / Required: Uploaded", which does not say why or what to do.

## Solution
- **Backend:** the six banking rules in `config/funding_qualification.php` have `group => banking`. In `FundingQualificationService`, a failed banking factor has a `message` and an upload `action` (`/documents`). A failed Banking Activity check also has `reason_code` (`not_uploaded` / `rejected`), `reason` and `requirement`. Text is in `lang/en/funding_sequencing.php`. Bank statements Under Review now pass Banking Activity (D14 decision), in both `FundingQualificationService` and `FundingReadinessService`.
- **Borrower portal:**
  - The funding card tooltip shows the reason and requirement (or the bank-statement message).
  - The funding detail page shows a banner with an Upload Bank Statements button (`src/utils/bankingGuidance.ts`, `FundingCard.tsx`, `FundingDetail.tsx`, `assets/css/funding.css`).
  - `FundingDetail.tsx` keeps stage's BUG-026 and BUG-134 logic; only the banner was added.
- **Admin:** the client page's funding hover card (`ClientFundingSequences.tsx`) shows the same reason and requirement.

## Tests
- **Backend:** `Integration/Application/BankingReadinessMessageTest` and `Feature/Funding/BankingReadinessMessageApiTest` (10 pass). Funding, qualification and readiness filter: 527 pass, 1 skipped.
- **Borrower:** `tests/utils/bankingGuidance.test.ts`, `tests/pages/funding/FundingCard.test.tsx` and `FundingDetail.test.tsx`, plus e2e `e2e/sidebar/funding/funding-bank-statements.spec.ts`. Vitest funding: 61 pass.
- **Admin:** `tests/pages/clients/ClientFundingSequences.test.tsx`. Clients: 48 pass.

## Open
- The `docs/test-findings.md` entries F127 and D14 live on `bugfix/qa-fixes-2026-10-15`. Add them here if this branch merges first.
