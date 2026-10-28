# Invoices — an invoice's date is its stored date everywhere (BUG-122)

**Date:** 2026-10-26
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-122** (OP-INV-08): the details modal for a down-payment invoice (INV-20261020009, $1,000) showed the whole enrollment ($7,696.70) and **21 Oct**, while the list and the PDF said $1,000.00 and **20 Oct**.

- **Amount:** already fixed in 79df9f24 (14 Oct, "Every figure is the invoice's own"), which reached `origin/stage` on **24 Oct** (PR #33), after QA's test on 21 Oct. No change here.
- **Date:** still wrong. The admin list and details and the borrower list sent `created_at` through `toTimezone()` (the viewer's zone), while the PDF printed the stored date. An invoice created late in the UTC day was a day apart in Asia/Dhaka.

## Solution
As decided, the invoice date uses **`toDateOnly()`**: the stored date, not shifted into the viewer's zone.
- `FCH\Invoice\InvoiceResource` and `FCH\Invoice\InvoiceDetailResource`: `created_at` → `toDateOnly()`.
- `Borrower\Invoice\InvoiceResource` (the client portal list): the same. The borrower details already used it.
- `resources/views/invoices/download.blade.php`: the date uses `toDateOnly(payment_date ?: created_at, 'd M Y')`. This prints the same as before, but now goes through the same helper.

## Files changed
- `app/Http/Resources/FCH/Invoice/InvoiceResource.php`
- `app/Http/Resources/FCH/Invoice/InvoiceDetailResource.php`
- `app/Http/Resources/Borrower/Invoice/InvoiceResource.php`
- `resources/views/invoices/download.blade.php`
- `tests/Feature/Invoice/InvoiceDateTest.php`: new, 6 tests.

## Tests
- **New:** an invoice created 20 Oct 22:00 UTC shows `2026-10-20` in the admin details, the admin list and the borrower list, for a viewer in Asia/Dhaka and in America/Chicago. The three Dhaka cases failed first (they gave `2026-10-21`).
- `--filter "Invoice|Payment|Billing"`: 490 passed.

## Open items
- QA sheet: BUG-122 → Fixed after the stage retest. The amount part has been on stage since 24 Oct.
