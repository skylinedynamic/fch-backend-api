# Handoffs

One file per finished piece of work — a feature, a change or a bug fix — so whoever picks it up next knows what was wrong, what was done and what is left.

**File name:** `YYYY-MM-DD-short-title.md` (date the work was finished, kebab-case title), e.g. `2026-10-12-integration-test-backfill.md`.

## Template

```markdown
# <Title>

**Date:** YYYY-MM-DD
**Branch:** <branch>
**Author:** <name>

## Problem
What was wrong or missing, and how it showed up (who was affected, what they saw).

## Solution
What was changed and why this approach. Note any behaviour change a user or another app will notice.

## Files changed
- `path/to/file.php` — one line on what changed

## Tests
- Added: `tests/.../SomeTest.php` — what it pins
- Updated: `tests/.../OtherTest.php` — what changed in the expectation
- Suites run: Unit / Integration / Feature — result

## Open / follow-up
Anything not done, decisions waiting (link `docs/test-findings.md` IDs), and actions for someone outside the code (rotate a secret, check production data, ...).
```
