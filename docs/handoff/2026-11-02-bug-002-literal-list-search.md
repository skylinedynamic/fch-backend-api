# BUG-002: list search matches % and _ literally (Clients, Leads, Partners)

**Date:** 2026-11-02
**Author:** awlad

## Problem
BUG-002 (R2-OP-GLOB-08): searching Clients, Leads or Partners for `%` or `_` alone returned every record.
- The search text went into `LIKE "%{$search}%"` as typed.
- In LIKE, `%` means "anything" and `_` means "any one character", so both acted as wildcards.
- This was not a security issue: queries are parameterised, and a search never widens what the user may see.

## Solution
- **New `App\Support\Database\LikePattern`:**
  - `escape()` escapes `!`, `%` and `_` with `!`.
  - `contains()` returns the escaped `%text%`.
  - `ESCAPE_CLAUSE` is `ESCAPE '!'`.
  - Why `!` and not a backslash: MySQL's default escape character is a backslash, SQLite has none, and a backslash inside the `ESCAPE` clause is read differently by each. `!` behaves the same on both.
- **New query macros `whereContains($column, $text)` / `orWhereContains(...)`** in `AppServiceProvider`. They also work on Eloquent builders.
- **The search scopes now use them:** `Borrower::scopeSearch` (including its raw `CONCAT_WS` full-name match), `Lead::scopeSearch` and `Partner::scopeSearch`. These scopes back the Clients, Leads and Partners lists.

## Files changed
- `app/Support/Database/LikePattern.php`: new.
- `app/Providers/AppServiceProvider.php`: the two macros.
- `app/Models/Borrower/Borrower.php`, `app/Models/Lead/Lead.php`, `app/Models/Partner/Partner.php`: `scopeSearch`.

## Testing
- **New, all failed first:**
  - `tests/Unit/Support/LikePatternTest.php`, 2 tests.
  - `tests/Feature/Search/LiteralListSearchTest.php`, 3 tests: Clients, Leads and Partners. `%` and `_` match only records that contain them, and "50%" finds "50% Off Outlet".
- **Run:**
  - The new tests: 5 passed.
  - `tests/Feature/Borrower` + `tests/Feature/Lead` + `tests/Feature/Partner`: 467 passed.
- **Checked on the local MySQL:** 56 clients; `search('%')` = 0, `search('_')` = 0, `search('a')` = 56. Leads `%` = 0, Partners `_` = 0.

## Open items
- **Other lists still match `%` and `_` as wildcards.** About 330 more LIKE searches across roughly 50 files still put the text in as typed, for example Invoices, Payments, Users, Employees, Blogs, Change Requests, Verification Center, Embed Form and `QueryFilter` (column filters).
  - Each can switch to `whereContains()` / `orWhereContains()`, or to `LikePattern` with `ESCAPE_CLAUSE` in raw SQL.
  - `SeoService` already escapes, but with a backslash (MySQL only).
