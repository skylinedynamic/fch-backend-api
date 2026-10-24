# Clients — the assignee is one of the client's partner's team (BUG-055)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-055**: on Clients › Add Client / client profile, "Assign to" listed the host's staff even on a partner's client. The admin form asked `common/get-all-users` without a partner (admin side: see the admin panel's handoff of the same date). The API did not stop it either: `assign_id` was only `exists:users,id`, so any user on the platform (another partner's, the host's, even a borrower's login) could be saved as a client's assignee.

The other half of the report — the host choosing another partner when adding a client — works as intended: the host's Add Client is for its own clients; partners add theirs (or they arrive through lead / embed-form conversion).

## Solution
- New rule `App\Rules\PartnerTeamMember($partner, $keep, $message)`: the id must be one of the partner's team, `User::teamOf()` — the same set `get-all-users` lists (host: its Admin logins; another partner: its Partner logins; never a borrower). `$keep` is the record's current value, accepted even outside the team so a client saved before the rule still saves. Same idea as the lead owner rule (`LeadPartnerRules`).
- `StoreBorrowerRequest`: the partner is the one `BorrowerService::store()` files the client under — the host may name one with `partner_id`, anyone else gets their own.
- `UpdateBorrowerRequest`: the client's own partner (or the one the host moves it to); its current assignee is kept.
- Refusal: 422 on `assign_id`, "Assign the client to someone on the client's partner team." (`borrower_validation.assign_id_not_in_team`).

## Files changed
- `app/Rules/PartnerTeamMember.php` — new rule.
- `app/Http/Requests/Api/V1/FCH/Common/Borrower/StoreBorrowerRequest.php` — rule on `assign_id`; `clientPartner()`.
- `app/Http/Requests/Api/V1/FCH/Common/Borrower/UpdateBorrowerRequest.php` — rule on `assign_id` keeping the current one; `clientPartner()`.
- `lang/en/borrower_validation.php` — `assign_id_not_in_team`.
- `tests/Feature/Borrower/ClientAssigneeTeamTest.php` — new, 8 tests.

## Tests
- New `ClientAssigneeTeamTest` (8): host assigns its own client to its team; a partner cannot assign to the host's team; a borrower's login is never an assignee; the host creating for a partner assigns from that partner's team; no assignee still allowed; the host reassigns a partner's client within that partner's team, not to its own; a client keeps an assignee from before the rule. The 4 refusing cases failed first (201/200), pass after.
- `tests/Feature/Borrower`, `tests/Feature/Lead/LeadOwnerTeamTest.php`, `tests/Feature/EmbedForm`: 330 passed. Full suite: see the session report.

## Open items
- Clients already assigned outside their partner's team keep that assignee until someone changes it; no data is rewritten.
- Conversions (lead → client, embed form → client) set `assign_id` in the service, not through these requests, and are unchanged — a lead's owner is already held to its partner's team.
- Mark BUG-055 in the QA defect log back to In Progress, then Fixed after the stage retest; note the partner-picker half as works-as-intended.
