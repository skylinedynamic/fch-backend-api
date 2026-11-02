# BUG-062: host's "converted to a client" copy when the partner converts

2026-11-02

## Problem
QA flow R2-PP-EMB-04 / R2-OP-EMB-07, found 22-Oct-2026. Wanda's public embedded form is submitted and Wanda converts the submission. The host admin then gets two emails about the same applicant:
- "New Embedded Form Application" came from Four Corner Funding, with its logo.
- "Applicant converted to a client" came from Wanda's own mailbox, with Wanda's logo.

So the host copy was Wanda-branded and went out through Wanda's mail account.

## Solution
No production change needed: this was already fixed by `5cf8cd2d` ("embedded form convert modal and mail fix", 2026-10-23, handoff `2026-10-23-converted-mail-host-copy-from-host.md`). That commit is on `stage` and `release`. Before it, every `EmbedFormConverted` email was built with the form partner's id. Since then, `EmbedFormService::notifyConverted()` gives each staff copy its recipient's id, so the host's copy comes from the host, whoever converts.

That fix's test covered only **the host** converting. This change adds a test for **the partner** converting (the BUG-062 case), so it stays fixed.

## Files changed
- `tests/Feature/EmbedForm/EmbedFormConvertedByPartnerMailSenderTest.php`: new. The partner's own login converts its submission. It checks that the host copy's mailbox and branding are the host's and never the partner's, and that the partner's copy and the applicant's email come from the partner.

## Testing
- New test: 3 passed (7 assertions).
- `tests/Feature/EmbedForm`: 161 passed.

## Open items
- Retest on a build that contains `5cf8cd2d`. Restart `php artisan queue:work` first: these mails are queued, and a worker started before the fix keeps sending with the old code.
- Update the QA sheet: BUG-062 → Fixed (in `5cf8cd2d`), ready for retest.
