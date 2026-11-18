# Permissions declared once, in App\Enums\Permission

Date: 2026-11-12

## Problem

Each permission name was written in `config/permissions.php`, and then written again as a string wherever code checked it: route middleware (`'permission:update clients'`), model and service constants (`Invoice::PERMISSION_PAY`, `Partner::PERMISSION_MANAGE_CONFIGURATION`, `CommissionViewer::MANAGE_PERMISSION`), and comments. A typo in any copy fails silently, because the user just gets 403. Nothing showed which names were in use.

This is groundwork for checking permissions on the server. Most endpoints are checked only by the admin panel today, which is the root cause of BUG-202 and R3-BUG-001.

## Solution

- **`App\Enums\Permission`** is the only place a permission is declared. Each case's value is the stored name, and its `#[PermissionInfo]` attribute gives:
  - the module (`App\Enums\PermissionModule`)
  - `certified`: a Certified Partner's roles may hold it
  - `host`: only the host's roles may hold it
  - an optional `key` for seeders that look the permission up by key
- **`config/permissions.php`** is now `return Permission::toConfig();`. The array is exactly what it was before, with the same modules, order, flags and keys, so none of the ~20 seeders that read it needed a change.
- **Code refers to permissions through the enum:**
  - routes: `->middleware(Permission::UpdateClients->middleware())`
  - checks: `$user->can(Permission::PayInvoice->value)`
- **Constants removed.** The three constants listed above are gone, and their uses now point at the enum.
- **Guard test.** `PermissionEnumTest` fails if any permission name is written as a string anywhere in `app/` or `routes/`.

Why an attribute on each case instead of a `match()` per property: everything about a permission sits on one line beside its name. A new permission is one new case plus a seeder to add it to existing databases, as before.

The 115 cases were generated from the old config by a script, not typed by hand. Two case names fix spellings while keeping the stored value: `AddSocialMedia` (stored as `add soical media`) and `ApprovePayment` (stored as `can approve payment`). The module `EmbeddedLoanApplicationForm` likewise keeps its stored value, `enbedded loan application form`. The stored values are unchanged, so nothing in the database moves.

## Files changed

- `app/Enums/Permission.php` (new): the 115 permissions, plus `module()`, `isCertified()`, `isHost()`, `middleware()` and `toConfig()`.
- `app/Enums/PermissionModule.php` (new): the 26 modules.
- `app/Enums/Attributes/PermissionInfo.php` (new): the per-case attribute.
- `config/permissions.php`: builds the list from the enum.
- `routes/common.php`: 13 `permission:` middlewares now use the enum.
- `app/Models/Invoice/Invoice.php`: `PERMISSION_PAY` removed.
- `app/Models/Partner/Partner.php`: `PERMISSION_MANAGE_CONFIGURATION` removed. It had been added earlier the same day for R3-BUG-001.
- `app/Services/FCH/Common/Commission/CommissionViewer.php`: `MANAGE_PERMISSION` removed.
- `app/Traits/ResolvesConfigPartner.php`, `app/Services/GlobalPayment/UnifiedPaymentService.php`, and `app/Http/Requests/.../ArchivePartnerRequest.php` / `ArchiveBorrowerRequest.php`: code and comments use the enum.
- `database/seeders/PayInvoicePermissionSeeder.php`: uses the enum (imported as `PermissionName`, because the file also uses the `Permission` model).
- `tests/Feature/Payment/PayInvoicePermissionSeederTest.php`, `tests/Feature/Payment/PayInvoiceTest.php`: use the enum.
- `tests/Unit/Permissions/PermissionEnumTest.php` (new), `tests/Fixtures/Permissions/config-permissions.json` (new): the snapshot of the config as it was.

## Testing

`PermissionEnumTest` was written first. The snapshot test passed against the old config. The other four failed until the enum existed, and the guard test then listed 19 literals, which were replaced one by one.

The test checks that:
- `config('permissions')` is identical to the pre-change snapshot (same order, flags and keys)
- the config is built from the enum
- each case reports its module and who may hold it
- `middleware()` gives `permission:<name>`
- no permission name is written as a string in `app/` or `routes/`

Results:
- Full suite: 5,490 tests passed, 2 skipped.
- `php artisan config:cache` works with the enum-built config (26 modules, 115 permissions, keys intact).

## Open items

- **This change adds no new permission checks.** Behaviour is the same as before. Adding server-side checks module by module is the next step. Each module needs an audit of which permission the admin panel uses for each endpoint, plus a 403/200 test per endpoint, because turning a check on can lock out roles that never had that permission.
- **Seeders:** `database/` is outside the guard test. Several older seeders still write names as strings. They run once against existing databases, so they're lower risk, but they can move to the enum over time.
- **Admin panel:** `Fch-admin-frontend-react` still writes names as strings (`hasPermission('Pay Invoice')`). A follow-up could generate a TypeScript constants file from the enum, for example with an artisan command, so both apps share one list.
- **Branch:** this was done on `bugfix/round-3`, together with the R3-BUG-001 fix, and is uncommitted. Consider committing them separately.
