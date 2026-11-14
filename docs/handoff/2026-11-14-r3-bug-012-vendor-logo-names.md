# R3-BUG-012: a vendor logo is taken whatever its name, if the File Manager holds it

Date: 2026-11-14

## Problem

Host Admin › Credit Builder › Vendors (`POST/PUT admin/credit-builder/vendors`) refused a logo whose file name had parentheses or an extra dot. `vendors/QA-TEST logo (1).png` and `vendors/QA-TEST-logo.v2.png` got 422 "Choose the logo from the File Manager…".

The rule was `regex:/^[\w\-\/ ]+\.(jpe?g|png|gif|svg|webp)$/i`: letters, digits, `_ - /`, spaces and one dot. Common camera and download names could never be used.

QA's side notes:
- A path no file is at (`vendors/QA-TEST-plain.png`) was accepted.
- The API answered the raw keys `credit_builder.vendor_created` / `vendor_deleted` as messages. `vendor_updated`, `vendor_fetched` and `vendor_list_fetched` were missing too.

## Solution

- **Any picture name.** The logo must end in a picture extension (jpg, jpeg, png, gif, svg, webp; any case). The rest of the name is free: spaces, `(1)`, extra dots.
  - Still refused: `..`, a URL (`://`), an absolute path (`/…`) and a backslash.
  - The folder isn't restricted. Seeded vendors use `logos/tier-1/…`.
- **It must be in the File Manager.** A new logo is refused unless the uploads disk holds that file (`credit_builder.validation_vendor_logo_missing`).
  - The logo a vendor already has is not checked again. So an edit of a vendor whose logo file is gone (seeded, or removed) still saves.
- **Messages:** added `vendor_list_fetched`, `vendor_fetched`, `vendor_created`, `vendor_updated` and `vendor_deleted` to `lang/en/credit_builder.php`.

## Files changed

- `app/Http/Requests/Api/V1/FCH/Admin/CreditBuilder/Vendors/CreditBuilderVendorRequest.php`:
  - the looser name rule
  - the stricter not-a-path rule
  - `withValidator()` checks that a changed logo exists
- `lang/en/credit_builder.php`: `validation_vendor_logo_missing` and the five vendor messages.
- `tests/Feature/CreditBuilder/VendorLogoFromFileManagerTest.php`:
  - fakes the disks and puts the pictures the tests pick
  - refused paths: + `vendors/../../.env.png`, `/etc/logo.png`, a backslash path
  - accepted names (+4): space and `(1)`, extra dot, upper-case `.JPEG`, a seeded folder
  - a missing file is refused
  - an edit keeping a missing logo saves
  - create and delete answer in words

## Testing

- **Seen failing first:** 5 tests (the refused paths, the two QA names, the missing file, the messages).
- **`tests/Feature/CreditBuilder`:** 134 passed.
- **Full suite:** see below.

## Open items

- **No admin change needed.** The vendor modal picks from the File Manager's `vendors` library and sends the path back unchanged on edit.
