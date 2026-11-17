# Remove one-off artisan commands

2026-11-17

## Problem
`app/Console/Commands/` had collected one-off data and file migrations that were written for a single run after a deploy. They are not scheduled, and they cluttered `php artisan list` and the test suite.

## Solution
Removed 8 commands, their tests, and the comments that named them:

| Command | What it did |
|---|---|
| `funding-types:check` / `funding-types:fix` | Report / rewrite old funding type names to the Active categories (F184) |
| `agreements:move-images-to-disk` | Base64 agreement images onto the agreement image disk |
| `files:move-to-private` | Copy public folders to their private disk (BUG-016) |
| `files:relocate` | Partner documents / credit reports / provider logos to folders of their own (BUG-016) |
| `embed-form:relocate-converted-documents` | Documents of embed submissions converted before the current conversion |
| `commissions:remove-unclosed` | Commissions of applications not Closed (Completed) (F177) |
| `funding-applications:purge-download-copies` | The old per-download PDF/ZIP copies |

**Kept:** `audit:move-signed-copies`. `stage`'s new `audit:organize-files` runs after it on production (move → organize → verify), so it stays until both have run on prod; restored with its test while merging into `stage`.

**Also kept:** `invoices:reconcile-paid`. It isn't one-off: the auto-charge stop alert tells admins to run it (`lang/en/payment.php` → `already_paid_alert`, BUG-005).

No class became dead code. Every service and support class these commands used is still used elsewhere (`FundingTypeName`, `AgreementImageStore`, `FundingApplicationAuditTrail`, `EmbedFormService`, `StoragePath`, `StoredFileName`, `Disk`, `Folder`).

## Files changed
- Deleted: the 9 commands in `app/Console/Commands/`.
- Deleted: `tests/Integration/Console/{CheckFundingTypes,FixFundingTypes,RemoveUnclosedCommissions}CommandTest.php`, `tests/Feature/Agreement/MoveAgreementImagesToDiskTest.php`, `tests/Feature/Files/{MoveFilesToPrivate,RelocateDocuments}CommandTest.php`, `tests/Feature/EmbedForm/RelocateConvertedDocumentsCommandTest.php`.
- `tests/Feature/Audit/AuditTrailCommandsTest.php`: removed the purge test only (verify, seal and history import stay).
- `app/Services/FCH/Common/EmbedForm/EmbedFormService.php`, `config/files.php`, `tests/Feature/Audit/FundingApplicationAuditTrailTest.php`: comments only.

## Testing
- `php artisan list` shows none of the 9 commands.
- Full suite: see the result recorded in the PR.

## Open items
- **Run before this change reaches production.** The commands stay on the server until the next `release` deploy. `funding-types:check` on prod (17 Nov) showed `funding-types:fix` **has not run**: 13 applications and 4 embed submissions still carry old names, and 8 of those applications are editable. Their Funding Request step can't be saved until the type is re-chosen. Run `php artisan funding-types:fix --dry-run`, then `funding-types:fix`, before deploying.
- The other one-off commands weren't checked on prod. Run each one's `--dry-run` there before deploying; anything still listed needs its real run first.
- Older handoff notes still name these commands as deploy steps. They're history, left as they are.
