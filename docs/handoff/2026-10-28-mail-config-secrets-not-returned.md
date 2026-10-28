# BUG-133 — Mail settings no longer send stored credentials to the browser

## Problem
`GET /api/v1/common/mail-configurations` (and the fallback list, plus the save/verify/default responses) returned each provider's settings through `MailConfigurationResource`:
- `aws_access_key` was not flagged `secret`, so the stored AWS access key ID went out in plain text.
- `smtp_password` / `aws_secret_key` went out as the mask `••••••••`. QA's "48 characters" is that mask JSON-escaped (`•` x 8). It was not the real value, but the field was still in the response.

## Solution
- `config/mail_configuration.php`: `aws_access_key` is now `secret` (still a `text` input).
- `MailConfigurationResource`: secret fields are left out of the response entirely. In their place, `has_<field>: bool` (e.g. `has_smtp_password`, `has_aws_access_key`, `has_aws_secret_key`), the same approach as the OpenAI fix (`has_api_key`).
- Saving is unchanged: a secret sent back blank or as the mask keeps the stored value.
- `LegacyEmailConfigurationImporter`: the "is this provider filled in?" check now skips only `password`-type fields, so making the access key secret leaves import behaviour unchanged.
- Admin frontend (`Fch-admin-frontend-react`): `mailForm.ts` and `MailConfigurationCard.tsx` read `has_<field>`; the types comment is updated. The form still shows the mask locally for a stored secret and sends it back to keep the stored value.

## Tests
- `tests/Feature/MailConfiguration/MailConfigurationApiTest.php`: new `the_list_never_sends_a_stored_credential_to_the_browser` (Defect), `saving_without_the_aws_keys_keeps_the_stored_ones`; updated assertions to `has_smtp_password`; SES validation now expects the AWS keys to be required by the service (they are secrets).
- `tests/Feature/MailConfiguration/FallbackMailConfigurationTest.php`: updated to `has_smtp_password`.
- Ran `tests/Feature/MailConfiguration`, `tests/Unit/Mail`, `tests/Integration/Mail`: 260 passed.

## Open
- The SMS configuration resource (`SmsConfigurationResource`) still returns `auth_token` as the mask. It is not a leak, but it is not the `has_*` shape either.
