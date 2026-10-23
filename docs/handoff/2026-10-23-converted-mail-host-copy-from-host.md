# Converted-to-client email: the host's copy comes from the host company

**Date:** 2026-10-23
**Branch:** `hotfix` (uncommitted)
**Author:** awlad

## Problem
When the host converts a submission from another partner's embedded form, three "converted to a client" emails go out: to the applicant, to the partner and to the host. All three were built with the form partner's id, so the **host's copy** left under the partner's mailbox, branding (logo and site name) and sender name, instead of the host company's.

## Solution
- `EmbedFormConverted` takes an optional `senderPartnerId`, which sets `mailPartnerId` (mailbox and sender name) and the branding partner. It defaults to the submission's partner.
- In `EmbedFormService::notifyConverted()`, each staff copy now passes its recipient's own id:
  - the host's copy comes from the host
  - the partner's copy comes from the partner
- The applicant's email is unchanged: it still comes from the partner.
- This matches how `NewSubmissionMail` already builds the host copy.

## Files changed
- `app/Mail/EmbedForm/EmbedFormConverted.php`: the `senderPartnerId` constructor parameter.
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`: `notifyConverted()` passes the recipient's id for staff copies.
- `tests/Feature/EmbedForm/EmbedFormConvertedMailSenderTest.php`: new.

## Tests
- New `EmbedFormConvertedMailSenderTest`: the host converts a white-label partner's submission, then checks:
  - the host's email has the host as mail partner and as branding partner
  - the partner's email has the partner
  - the applicant's email has the partner
- The two host cases failed before the fix.
- `tests/Feature/EmbedForm` + `tests/Integration/Mail` + `tests/Feature/MailConfiguration`: **258 passed**.

## Open items
- If the host and the partner share one email address, only one staff email is sent (the existing de-duplication). It is the host's, since the host is notified first.
- Not committed.
