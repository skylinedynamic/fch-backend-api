# BUG-057: converting an already-converted lead answered 500

2026-11-02

## Problem
QA flow R2-PP-LEAD-03, Partner portal (Wanda) › Leads › Convert to Borrower. The Convert dialog for lead 204 was opened in two tabs. The lead was converted in tab 1, then tab 2 converted it again. The second request answered **HTTP 500** "A borrower with this email already exists." (today's wording: "This email already belongs to one of your clients."). Only one borrower was created, so the data was fine.

Causes:
- `LeadService::convertToBorrower()` never checked whether the lead was already converted (`convert_to` set). It failed later, on the email the first conversion had used.
- `LeadController::convert()` turned every refusal (`InvalidArgumentException`) into a 500.

## Solution
- **New exception** `App\Exceptions\LeadConversionRefusedException` for refusals the partner can act on.
- **`LeadService::convert()`** refuses a lead whose `convert_to` is set, with `lead.already_converted`: "This lead has already been converted."
- **Same exception for the existing refusals** (missing contact details, email already a client or partner):
  - in `convertToBorrower`;
  - in `convertToPartner`.
- **`LeadController::convert()`** answers these with **422** and the message.

Programming errors (an invalid lead type) and real failures still answer 500.

## Files changed
- `app/Exceptions/LeadConversionRefusedException.php`: new.
- `app/Services/FCH/Common/Lead/LeadService.php`: the already-converted check; refusals throw the new exception.
- `app/Http/Controllers/Api/V1/FCH/Common/Lead/LeadController.php`: 422 for refusals.
- `lang/en/lead.php`: `already_converted`.
- `tests/Feature/Lead/ConvertLeadAlreadyConvertedTest.php`: new, 2 tests.

## Testing
- New tests: red before (500 instead of 422), green after.
- `tests/Feature/Lead`: 73 passed.
- `tests/Feature/Website/WebsitePrequalLeadConversionTest.php`: 3 passed.

## Open items
- Frontend side: `react-fch` handoff of the same date.
