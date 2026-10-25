# Commissions — sorting by Date, Borrower, Partner, Requested or Funded Amount (BUG-073)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-073** (OP-COMM-04): on Commissions, sorting by Date, Borrower, Partner, Requested Amount or Funded Amount made `POST /common/commissions` answer 500; the table showed "No commissions found" and every card dropped to $0 / 0%. Status, Admin Comm., Partner Comm. and Payment Status sorted fine.

The table sent each column's display field as `sort_field` (`date`, `borrower`, `partner`, `requested_amount`, `funded_amount`). None is a `commissions` column — they are made up by `CommissionResource` from `created_at`, the funding application and the partner. `CommissionService` passed the field to `QueryFilter::apply()`, whose check (`isUsableColumn`) looks only at the name's shape, not whether the column exists, so MySQL refused `ORDER BY borrower`. (SQLite treats an unknown name as a string, which is why the test suite never saw a 500.)

## Solution
- The table's headers send the key the API sorts by (admin panel handoff of the same date): Date → `created_at`, Borrower → `borrower_name`, Partner → `partner_name`; the amounts already sent `requested_amount` / `funded_amount`.
- `CommissionService::applySort()` sorts the list itself:
  - `borrower_name`, `requested_amount`, `funded_amount` → the funding application's `full_name`, `requested_amount`, `total_funded_amount`; `partner_name` → the partner's `full_name` — each as a sub-select (archived rows included, as the list loads them), so scoping and paging are unchanged;
  - the commissions columns it may sort as they are (`SORT_COLUMNS`);
  - anything else is not passed to the database — the list falls back to the key, so a wrong field can no longer empty the page;
  - `commissions.id` breaks ties.
- `QueryFilter` is told to skip its own sorting for this list. `QueryFilter` itself is unchanged: other lists sort by computed aliases that are not table columns, so a global column check would quietly break them.

## Files changed
- `app/Services/FCH/Common/Commission/CommissionService.php` — `SORT_COLUMNS`, `applySort()`; `skip_sorting` to `QueryFilter`.
- `tests/Feature/Commission/CommissionListSortTest.php` — new, 7 tests.

## Tests
- New: Date, Borrower, Requested and Funded Amount sort both ways; Partner sorts by name; `date` / `borrower` / `partner` / an unknown field fall back to newest first; Admin Comm. still sorts. Five failed first (wrong order), pass after.
- `tests/Feature/Commission`, `tests/Feature/CommissionPayout`: 61 passed. Full suite: see the session report.

## Open items
- Other lists that pass `sort_field` straight to `QueryFilter` can hit the same 500 if a table header names a display field; worth a per-list check.
- Mark BUG-073 in the QA defect log Fixed after the stage retest.
