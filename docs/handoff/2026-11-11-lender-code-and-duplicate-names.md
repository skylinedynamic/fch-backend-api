# Lender Code and lender names that may repeat (2026-11-11)

Repos: **fch-backend-api** and **Fch-admin-frontend-react**, branch `bugfix/qa-round1-run2`. Findings: F259.

## Problem / requirement
- **Lender Code:** unique, system-generated, `"LND-" . rand(4 digits) . lender id` (lender 5 → `LND-93245`).
- **Lender Name, Legal Name:** may repeat, with a possible-duplicate warning. Before, the legal name was unique (R2 BUG-008); this requirement replaces that.
- **Dropdowns:** every lender dropdown reads "Lender Name - Lender Code".
- **Lists and detail tables:** Lender Code in its own column; the lender itself still shown as "Lender Name - Legal Name".
- **Seeder:** do not change `LenderSeeder`; existing lenders get a code.

## Solution
- **Column:** `lenders.lender_code` (string 32, nullable, unique), named so it does not clash with the many numeric `lender_id` foreign keys. Screens label it "Lender Code".
- **Migration** `2026_11_11_000001_add_lender_code_to_lenders_table` adds the column and gives every existing lender (deleted too) its code.
- **Model** `Lender`:
  - `booted()` sets the code right after create (the id is needed), then saves quietly. It is not fillable, so the API ignores a sent `lender_code`.
  - `lenderCodeFor($id, $digits = random)`: `LND-` + 4 digits + id. Unique by construction: two codes of the same length end in ids of the same length, and ids never repeat. No limit on the number of lenders.
  - `fillMissingLenderCodes()` for back-filling.
  - `withSame($column, $value, $ignoreId)` for the duplicate warning (case and spaces ignored, deleted lenders left out).
- **Seeder** `LenderCodeSeeder` (new) runs `fillMissingLenderCodes()`; safe to run again: `php artisan db:seed --class=LenderCodeSeeder`. `LenderSeeder` is unchanged (lenders it creates get a code from `booted()`).
- **Removed:** `App\Rules\UniqueLenderLegalName` and the `lender.legal_name_unique` message.
- **API:**
  - `POST /v1/admin/lenders/check-duplicates` `{name?, legal_name?, ignore_id?}` → `{name: [...], legal_name: [...]}`.
  - `lender_code` in `LenderResource`, `GET admin/lenders/get/all`, and matched lenders (`LenderMatchService`).
  - The lender list searches, filters and sorts by it.
- **Admin:**
  - `lenderOptionLabel()` ("Name - LND-1234", falls back to "Name - Legal Name" without a code), used by the Lender Information Lender filter (now picks by id), the Underwriting Logic Lender picker and Filter by Lender.
  - Lender Code column in the Lenders list and the Lender Information table, and on the assigned-lender card.
  - Add/Edit Lender: read-only Lender Code field; the duplicate check on leaving Lender Name / Legal Name shows a warning and the form still saves.
  - Lender Information layout: Lender Name min 180px, a compact code column, and long categories wrap, so Match and Assign stay visible.

## To run after pulling
`php artisan migrate` (fills codes for existing lenders). `LenderCodeSeeder` is only needed for a table filled some other way.

A local database that ran the first version of this migration (`LND-` + 4 digits, column of 8): `php artisan migrate` (2026_11_11_000002 widens the column to 32), then `php artisan db:seed --class=LenderCodeSeeder` - it replaces any code not in the current format (missing, or `LND-5890` without the id) and keeps the rest.

## Tests
See F259 in `docs/test-findings.md`.

## Left open
- Nothing.
