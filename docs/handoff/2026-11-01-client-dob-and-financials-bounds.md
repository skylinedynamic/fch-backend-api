# Clients: date of birth 18–120 and Business Financials bounds (BUG-005, BUG-006, round 2)

2026-11-01

## Problem
QA round 2 defect log, both Medium/P2 and both shown as Fixed. Neither was fixed.

- **BUG-005**: Owner portal › Clients › Add Client saved a client with a date of birth of 01 Jan 2009 (17 years old, 201). Editing client 153 to 01 Jan 1920 (106 years old) also saved (200). `StoreBorrowerRequest` and `UpdateBorrowerRequest` had `'date_of_birth' => ['nullable']` and nothing else. The borrower's own profile and the date-of-birth change request only checked `before:today`. The only age rule was in the embed form (18+).
- **BUG-006**: client 153 › Business Financials saved these values:
  - an annual revenue of 99999999999999999, stored rounded to 1e17;
  - a monthly revenue of 123456789012345;
  - a profit margin of 150%;
  - a current business debt of -500, stored as 500.

  `UpdateBorrowerBusinessFinancialsRequest` had `numeric|min:0` and no ceiling on the amounts, and plain `numeric` on the margin. The -500 never reached `min:0`, because the admin form stripped the minus sign (see the admin handoff). The FICO 950 part is already refused by `FicoScore` (on stage since 2026-10-15).

## Solution
- New rule `App\Rules\AdultDateOfBirth`. The borrower must be 18 to 120: the latest date is the 18th birthday, and the earliest is the day after the 121st birthday.
  - It accepts YYYY-MM-DD (portals) and MM-DD-YYYY or MM/DD/YYYY (admin client form, read month-first as `normalizeDate()` stores it).
  - An impossible date or a day-first date is refused as "Enter a valid date of birth."
  - An empty value is left to `required` or `nullable`.
- The age bounds are in `config/borrower.php` → `date_of_birth.min_age` (18) and `max_age` (120).
- The rule is used by:
  - `StoreBorrowerRequest` (Add Client)
  - `UpdateBorrowerRequest` (Edit Client)
  - `UpdatePersonalProfileRequest` (borrower profile)
  - `StoreChangeRequest`, for a `date_of_birth` change request. Its `before:today` is replaced, because the rule refuses future dates as under 18.
- Business Financials (`UpdateBorrowerBusinessFinancialsRequest`):
  - Every money field (monthly and annual revenue, annual personal income, average bank balance, average monthly deposits, current business debt) is checked for 0 up to `borrower.financials.amount_max`. That is 999,999,999.99, the ceiling the borrower's own Financial Details already used.
  - Profit margin must be `between` `borrower.financials.profit_margin.min` and `max`, which are -100 and 100. It can go negative because a loss-making business has a negative margin; this was decided with Awlad.
  - Each refusal has its own message.
- Messages:
  - `common.date_of_birth_invalid`, `date_of_birth_min_age` ("The borrower must be at least :age years old."), `date_of_birth_max_age`.
  - `borrower_validation.amount_min`, `amount_max`, `profit_margin_between`.

## Files changed
- `app/Rules/AdultDateOfBirth.php`: new.
- `config/borrower.php`: `date_of_birth` and `financials` bounds.
- `app/Http/Requests/Api/V1/FCH/Common/Borrower/StoreBorrowerRequest.php`, `UpdateBorrowerRequest.php`: DOB rule.
- `app/Http/Requests/Api/V1/Borrower/Profile/UpdatePersonalProfileRequest.php`, `StoreChangeRequest.php`: DOB rule (replaces `before:today`). The unused `date_of_birth.before` message was removed.
- `app/Http/Requests/Api/V1/FCH/Common/Borrower/UpdateBorrowerBusinessFinancialsRequest.php`: amount ceiling, margin range, messages.
- `lang/en/common.php`, `lang/en/borrower_validation.php`: the messages above.
- `tests/Unit/Rules/AdultDateOfBirthTest.php`: new (18 cases).
- `tests/Feature/Borrower/ClientDateOfBirthTest.php`: new. QA's dates are refused on Edit Client and nothing changes; an adult date is saved; a wiring check covers all four requests.
- `tests/Feature/Borrower/ClientBusinessFinancialsBoundsTest.php`: new. Each amount over the ceiling or negative is refused; QA's values leave the record unchanged; the margin outside -100..100 is refused; a negative margin and values at the bounds are saved.

## Testing
- New tests failed first: every case was red (no rule, no ceiling, no margin range, default messages). Then 43/43 passed.
- Full PHPUnit: OK, 4355 tests, 14462 assertions, 2 skipped.
- Admin live E2E against this API (`client-financials-bounds.live.spec.ts`, `client-date-of-birth-bounds.live.spec.ts`) passed. So did the borrower E2E (`personal-info-dob-bounds.spec.ts`). See the portal handoffs.

## Open items
- The embed form keeps its own 18+ rule (`funding_application.embed_form.minimum_age`) and has no upper age. It could use `AdultDateOfBirth` as well.
- Funding application ownership and co-borrower DOB (`StoreOwnershipInfoRequest`, `CoBorrowerValidationRequest`) are still `required|date`. They were not part of the QA report.
- Existing clients with an out-of-range DOB or financials are not changed. They will be refused the next time that field is saved with the same value.
