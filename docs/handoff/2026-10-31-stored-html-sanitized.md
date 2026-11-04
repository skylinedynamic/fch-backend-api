# Names in system activity descriptions are escaped (BUG-004, round 2)

2026-10-31

## Problem
QA round 2 defect log **BUG-004** (High/P1): a lead named `R2-XSS <img src=x onerror=console.log("xss")>` ran its `onerror` in the admin's Activity panel. `ActivityLogService` writes the record's name into the system description (`':model profile :action for :display_name'` → `Lead profile created for R2-XSS <img …>`) unchanged, and both portals render descriptions as HTML. Emails changed to, appointment subjects and the appointment's client name went in the same way.

## Solution
- `ActivityLogService`: `$displayName`, the new email, the appointment subject and the appointment's entity name are passed through `e()` before they are put into a description pattern. The description stays HTML (manual notes are rich text and are stored as written), so an escaped name reads the same in both portals and is text, not markup.
- The portals now also sanitize every stored HTML render (`Fch-admin-frontend-react` and `client-fch` `docs/handoff/2026-10-31-stored-html-sanitized.md`) — that is what covers descriptions already in the database and HTML in manual notes.

## Files changed
- `app/Services/FCH/Common/ActivityLog/ActivityLogService.php` — escape user values in system descriptions.
- `tests/Feature/ActivityLog/ActivityDescriptionEscapesNamesTest.php` — new: creating a lead with an HTML name stores no `<img` in its activity, and the escaped name is there.

## Testing
- `ActivityDescriptionEscapesNamesTest` failed first (`Lead profile created for R2-XSS <img src=x onerror=…>`), then passed.
- `tests/Feature/ActivityLog`, `tests/Feature/Lead`, `tests/Feature/Appointment` — OK, 240 tests.
- Full PHPUnit suite — OK, 4284 tests, 14251 assertions (2 skipped).

## Open items
- Existing activity rows keep their unescaped names; the portals' sanitizing makes them safe to render. No data migration was done.
- `$customDescription` values passed by callers are stored as given (some are rich text); they rely on the portals' sanitizing.
