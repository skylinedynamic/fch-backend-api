# BUG-018 retest: a partner's lead showed "Already a Borrower" for another partner's client

2026-11-08

## Problem
BUG-018 was retested on 08-Nov-26 with partner Wanda and her lead 201. The lead's email belongs only to a client of the host; Wanda has no client with it.

Wanda's Leads list showed "Already a Borrower" on the lead and no Convert button, and `GET common/leads/201` returned `convert_email_already: 'Borrower'`. So Wanda could still tell the person is a client elsewhere, even though converting such a lead already gives the neutral message.

The cause was in `LeadResource`: `convert_email_already` looked for a borrower with this email across every partner, and for a partner with it across the whole platform.

## Solution
`LeadResource::emailAlreadyIn()` now counts only the lead's own partner's records, the same rule `LeadService::convertToBorrower` uses:
- **'Borrower':** a borrower with this email and the lead's own `partner_id`.
- **'Partner':** only when the lead belongs to the host, whose book partners are.
- **Anything else is `false`:** the lead looks like any other, Convert is offered, and converting it gets the neutral 422 message "This email cannot be used. Please use a different email.".

The admin panel needs no change, because its "Already a …" badge is driven by this value.

## Files changed
- `app/Http/Resources/FCH/Lead/LeadResource.php`: `convert_email_already` uses the new `emailAlreadyIn()`. Pint also tidied the imports.
- `tests/Feature/Lead/ConvertLeadEmailTakenTest.php`: new tests:
  - the neutral refusal is a 422, not a server error
  - the lead and both lead lists (`GET common/leads`, `POST common/leads/fetch-leads`) show `false` for another partner's client
  - the partner's own client still shows 'Borrower'
  - a partner's lead does not reveal that an email belongs to a partner
  - the host's lead still shows 'Partner'

## Testing
- The 3 leak tests failed first ('Borrower' / 'Partner' instead of false) and pass now.
- `tests/Feature/Lead`: 80 passed. Pint applied.
- The full suite was not run for this change.

## Open items
- **The host's view:** a host viewing Wanda's lead also gets `false` now, because only the lead's partner's book counts. Converting it on Wanda's behalf still refuses with the neutral message.
- **Defect Log:** not updated. BUG-018 needs QA to retest it.
- **Not deployed:** not committed or pushed yet.
