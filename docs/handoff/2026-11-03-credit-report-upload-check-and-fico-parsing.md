# Credit report upload: any PDF accepted, and FICO read as 850

**Date:** 2026-11-03
**Branch:** bugfix/round-2
**Author:** Awlad Hossain

## Problem
Owner › Clients › client › Credit Profile › Choose PDF Report (`POST /common/borrowers/parse-credit-profile`).

- **Unrelated PDFs were accepted as credit reports.** Local data has a commission invoice uploaded today as client 169's credit report. `looksLikeCreditReport()` (BUG-144) took a name, SSN, date of birth or address as enough. The parser reads "Payee Name: …" as the person's name, so almost any business document passed. Once it passed, the file was stored and filed as the client's credit report document.
- **FICO filled in as 850** (QA's side note on BUG-074: "the synthetic report says FICO 712 … the parser filled 850"). The parser's last-resort pattern `/FICO.*?(\d{3})\s*\n/is` took the first three-digit number ending *any* line after the word "FICO", across lines. So "FICO Score 712 (Good)" followed by "Score range: 300 - 850" gave 850. It also accepted values outside 300–850 (9999, 120).

## Solution
- **Only credit data makes a credit report.** `looksLikeCreditReport()` now needs a score (FICO, PAYDEX, Intelliscore, Equifax), a trade account or an inquiry. A name, address, phone or date of birth alone is refused with the existing 422 `borrower.credit_profile_not_a_report`, and nothing is stored.
- **FICO from its own line only.** New `IsoftpullParserService::parseFicoScore()`:
  - It reads the first line that names FICO and skips a line that gives a range ("300 - 850", "300 to 850").
  - It ignores the model number (the 8 in "FICO 8").
  - It takes the first number of three digits or more, and only if it is within `FicoScore::min()`–`max()` (config, 300–850). Otherwise it gives null, and the host types the score in, which the save now requires (BUG-074).
- **Real reports are unaffected.** All 30 iSoftPull reports in local storage parse to the same FICO, trade and inquiry counts as before: 560, 630, 697, 734, 777. The invoice now gives nothing and is refused.
- **Five real reports checked field by field** (2026-11-03): 3 CBR exports, plus iSoftPull Equifax and TransUnion. All five use the iSoftPull layout.
  - Every `Company:` block is accounted for as a trade or an inquiry (e.g. 34 = 27 + 7, 27 = 19 + 8).
  - The personal fields, the account-analysis grids and the utilisation are filled in.
  - Fields that are partly empty match the source: `past_due` is null where the report says "Past Due: N/A", and `limit` is only on revolving accounts.

## Files changed
- `app/Services/FCH/Common/Borrower/IsoftpullParserService.php`: `parseFicoScore()` replaces the three FICO regexes.
- `app/Http/Controllers/Api/V1/FCH/Common/Borrower/BorrowerController.php`: `looksLikeCreditReport()` without personal info or addresses.

## Testing
- New `tests/Unit/Services/Borrower/IsoftpullParserServiceTest.php` (13 cases, including the two fixture tests below):
  - the iSoftPull score line
  - "FICO Score 712 (Good)" followed by a range line gives 712, not 850
  - model numbers and the ® mark are handled
  - a range-only line, a number on a later line, 9999 and 120 give null
  - an invoice gives no score, trades or inquiries
- `tests/Feature/CreditProfile/ParseCreditProfileRejectsNonReportTest.php`:
  - new: an invoice with name, address, phone and date of birth is refused and nothing is kept
  - new: a page that only explains the FICO range is refused
  - the report fixture now carries a score line
- `tests/Fixtures/CreditReports/isoftpull-report-anonymised.txt` (new): one real report's text with every name, number, address and value replaced, so only its labels and layout are left. It was checked line by line for personal data. Two new unit tests use it:
  - the layout is read in full (FICO, 4 trades with statuses and balances, "N/A" past due as null, 3 addresses)
  - a report without the "Trade Accounts" heading gives no trades
- Fixtures given a FICO line, with their intent unchanged:
  - `BorrowerCreditProfileApiTest` (log test)
  - `CreditReportDocumentTest`
- Before the fix: 9 failed (850 instead of 712, 200 instead of 422). After: all pass. `tests/Feature/CreditProfile`, `tests/Feature/Borrower` and `tests/Unit/Services` give 504 passed.
- Full suite (`php artisan test`): 4573 passed, 2 skipped, 0 failed. Two `SafeUploadTest` SVG tests failed at first because `enshrined/svg-sanitize` was missing from the local `vendor/`. `composer install` (lock file unchanged) fixed that. `IsoftpullParserServiceTest` passes (13 cases) with the fixture tests.

## Open items
- **"0 trade accounts" on QA's synthetic report.** Trades are read from the "Trade Accounts" section, and the fixture test shows that a report without that heading gives 0 trades. Every real iSoftPull report has the heading. QA's synthetic report most likely did not, so the same file is not expected to give trades. No fallback was added: inquiries also use `Company:` blocks, and a guess would count them as accounts. If QA should test this, they need a real-layout report (or the anonymised fixture as a PDF).
- In OpenAI parser mode a FICO the model returns is not range-checked before `looksLikeCreditReport()`. The save rejects a value outside 300–850 anyway (`FicoScore`).
- Client 169's local data still holds the invoice uploaded earlier. It is local test data only, not touched.
