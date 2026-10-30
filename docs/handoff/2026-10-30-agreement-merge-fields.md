# Agreement merge fields

**Date:** 2026-10-30
**Branch:** agreement-in-config
**Author:** Awlad Hossain

## Problem
Agreement templates had literal placeholders such as `[Customer Full Name]` and hard-coded lender details. Every borrower retyped their name and address into text fields, and a partner had to edit its own details into the template text by hand.

## Solution
- **New `AgreementMergeFields`.** The text can hold `{{signer.name|email|phone|address|business_name|business_address}}`, `{{owner.company_name|email|phone|address}}` and `{{today}}` (whitespace inside the braces is allowed).
  - **signer** is whoever signs: a borrower, or a partner signing the host's partner agreement.
  - **owner** is the partner whose agreement it is.
  - Only the fields in `KEYS` are ever filled; nothing sensitive (SSN, EIN, bank or payment details) is offered. Unknown fields are left as typed.
  - Values are HTML-escaped. A detail the signer has not given is left blank. Addresses are joined from their parts, leaving out what is missing.
- **Filled in two places:**
  - `check` returns the version's HTML already filled for the signer;
  - the server-built signed record is built from the filled HTML, so the record keeps the filled text.
- **Builder:** the Agreement Builder offers these fields from an "Insert field" menu (Fch-admin-frontend-react `src/config/agreementMergeFields.ts`). Keep the two lists the same.

## Files changed
- `app/Services/FCH/Common/ManageAgreement/AgreementMergeFields.php`: new.
- `app/Services/FCH/Common/ManageAgreement/AgreementService.php`: fill on check and before building the signed record.

## Tests
- Added: `tests/Feature/Agreement/AgreementMergeFieldsTest.php` (8). The eighth test checks that the builder's heading, list and table blocks keep their markup in the signed record, with merge fields inside list items and table cells filled. Covers: the borrower sees their own and their partner's details; the signed record keeps them; escaping; a missing detail is blank; unknown and sensitive fields are never filled; partner signer vs host owner; the offered keys.
- Suites run: Agreement 72 passed. Full suite: 3194 passed, 20 skipped, 0 failed.

## Open / follow-up
- **Legacy signatures are not filled:** the legacy `content_html` signing path (portals not yet updated) stores the browser's HTML, so its merge fields are filled only if the portal showed them filled (it does, via `check`).
- **Date format:** `{{today}}` is `m/d/Y`, the server's date on the day of signing.
