# R3-BUG-018: a contact's mobile error was the raw key "contact.mobile_number_invalid"

Date: 2026-11-15

## Problem

QA sent `POST common/contacts` with `mobile_number` `2025550199`, which has no formatting. The 422 message and `errors.mobile_number` were the raw key `contact.mobile_number_invalid`, and the Add Contact form showed the same text. `(202) 555-0199` saves.

`StoreContactRequest` and `UpdateContactRequest` use `__('contact.mobile_number_invalid')` for the format rule, but the key was never added to `lang/en/contact.php`. Laravel answers a missing key with the key itself.

This is the same kind of fault as R1 BUG-050 and BUG-139. A scan of every form request found 7 more keys missing the same way.

## Solution

- **The 8 missing keys** are added:
  - `contact.mobile_number_invalid`: "Enter a valid 10-digit US mobile number, e.g. (555) 234-5678." This matches the other phone messages.
  - `site_settings.partner_favicon_image` and `borrower_favicon_image`: the Site Settings favicons.
  - `payment_validation.rejection_reason_required`, `_string` and `_max`: Reject Payment.
  - `payment_validation.bank_detail_id_integer` and `_exists`: Process Payment.
- **Guard test.** Every `__('file.key')` used in `app/Http/Requests` must exist in `lang/en`. A new raw key now fails the suite instead of reaching QA.

## Files changed

- `lang/en/contact.php`, `lang/en/site_settings.php`, `lang/en/payment_validation.php`: the 8 keys.
- `tests/Feature/General/RequestMessageKeysTranslatedTest.php` (new): the guard.
- `tests/Feature/Lead/LeadAndContactApiTest.php`: an unformatted mobile gets the readable message on Add and Edit.

## Testing

- **Seen failing first:** both new tests. The contact test showed `"mobile_number": "contact.mobile_number_invalid"` without the key.
- **`tests/Feature/General`, `Lead`, `Contact`, `Payment`:** 338 passed.
- **Full suite:** run with R3-BUG-017; result in the PR.

## Open items

- **Not covered by the guard:** it checks form requests only. Keys used in services and controllers (`ApiResponse` messages) are not scanned.
