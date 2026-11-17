# records:purge — force-delete test partners and clients

2026-11-17

## Problem
Production holds test partners and clients (QA walkthroughs, demo accounts), with funding requests, funding applications, commissions, invoices, payments, documents and logins. They need to go for good, with everything that hangs off them. There was no safe way to do it: most of these tables have no foreign key to the record they belong to (funding_applications, commissions, invoices, audit events, subscriptions…), and several that do are RESTRICT (`borrowers.partner_id`, `users.partner_id`, `funding_requests.borrower_id`, `payments.*`, `employees.partner_id`). A database cascade can't do it, and a hand-written SQL delete would miss tables.

## Solution
An interactive artisan command, `records:purge`. It asks three questions, **lists everything first**, then asks yes / no:

1. **What to delete?** `partner` / `borrower` / `user`
2. **Email(s)**, comma separated (the record's own email or its login's)
3. **Full or partial?** Asked for a **borrower only**; a partner or user is always full.
   - **Full** (any type): the record and everything that hangs off it, force-deleted (soft-deleted rows too). A **partner's borrowers all go with it, fully**, and are listed first under "These borrowers will also be fully deleted".
   - **Partial** (borrower only): just the deals. That means funding requests, funding applications (their documents, co-borrowers, audit trail, activity, signed PDFs), commissions, payouts and the payouts' invoices and payments. **The borrower, its login, its enrollment invoices and payments and its documents stay.**
   - **User** (full): the login and what is its own: tokens, roles/permissions, notifications, appointments, and a staff member's employee record. Its partner or borrower stays.
4. The list: matched partners / borrowers / users, rows per table, files, blockers, leftovers. Then "This will force-delete 1 partner(s) and everything listed above." and **yes / no** (default **no**). No stops with nothing deleted.

Every step can be passed as an option for scripted runs: `--type`, `--email`, `--mode`, `--keep-email`, `--reassign-to`, `--dry-run` (only list).

- Refuses the host partner and the host partner's own login. Unknown emails are listed as "Not found" and skipped. `--keep-email` borrowers are never deleted; a kept borrower under a deleted partner blocks the run.
- **Every table is matched by one rule**, the same rule for counting and for deleting:
  - a key column (`partner_id`, `borrower_id`, `user_id`, `funding_application_id`, `funding_request_id`, `commission_id`)
  - a polymorphic pair (`entity_`, `subject_`, `causer_`, `notifiable_`, `model_`)
  - a few parent links (`RecordPurgeService::EXTRA_RULES`, e.g. chat messages by conversation, payout invoices and payments, refresh tokens)
- Clients, users and partners go last, by ID. New tables with these columns are picked up automatically.
- **Leftover check:** any reference the rules would leave is listed in the dry run. `--execute` re-checks inside the transaction and **rolls back** if one remains.
- **Blockers:** kept rows that still name a deleted user where the DB forbids it (`borrowers.assign_id`, `commissions.created_by/updated_by`, `payments.created_by`). `--execute` stops unless `--reassign-to` names a kept user, who then takes them over.
- **Typed confirmation** (`DELETE <n> CLIENTS`), then one DB transaction. Files are deleted **after** commit:
  - documents, partner documents, embedded form attachments
  - signed application PDFs (content-addressed `audit/files/{hash}.pdf`; only when no kept audit event names the same file)
  - the clients' and partners' upload folders
- Writes a report with **IDs and counts only** (no names/emails) to `storage/app/purge-reports/purge-<timestamp>.json` (local disk).

## Files changed
- `app/Console/Commands/PurgeRecords.php`: new, the command (options, output, confirmation).
- `app/Services/Maintenance/RecordPurgeService.php`: new, plan (resolve, sets, per-table counts, blockers, files, leftovers) and execute (transaction, files, report).
- `app/Services/Maintenance/PurgePlan.php`: new, what a run would delete.
- `tests/Feature/Console/PurgeRecordsCommandTest.php`: new, 16 tests.

## Testing
- Tests written first (red), then green: **16 passed (179 assertions)**. Covers the interactive flow (type → email → full/partial → list → confirm), full or partial asked only for a borrower, answering no stops with nothing deleted, `--dry-run`; partner full (its borrowers too), borrower full, borrower partial (deals, activity, audit trail, commission payout money gone; borrower, login, enrollment invoice and payment, documents kept), user full; partial refused for partner and user. Covers dry run deletes nothing; execute removes the partner, its clients, the listed client and all their data and files; kept client and host intact; report has no names or emails; wrong confirmation; host refused; unknown email; keep list; kept client under a deleted partner blocks; RESTRICT user reference blocks until `--reassign-to`; soft-deleted rows force-deleted. Storage is faked throughout.
- Full `fch-api` suite (first version): **5,611 passed, 2 skipped**.
- Local dry runs on `fch-pro`: partner full for the 3 partners lists 15 borrowers, 1,400 rows across 48 tables, no leftovers. Borrower partial (checked on Michael Chen, not run) lists only his deals: 534 rows across 7 tables.
- Earlier local dry run with the ticket's records: 3 partners, 20 clients, 1,637 rows across 49 tables, 51 files plus 43 folders, **no blockers, no leftovers**. Michael Chen (kept) is not in the list. The one extra application (#54) is a soft-deleted Draft of Demo Co Name whose client no longer exists.

## How to run on production
1. Merge via PR → `stage` (a live deploy) → dry run on stage → `release`.
2. **Back up the database first** (`mysqldump`), and the uploads storage if possible. The backup is the only undo.
3. Dry run (the ticket's records, by email):
   ```
   php artisan records:purge \
     --partner-email=craig.rice+whatever@fourcornerfunding.com,demo@fourcornerfunding.com,awladdeleo@gmail.com \
     --client-email=craig.rice+cashpay@fourcornerfunding.com,thaimn@live.com,testtest@fourcornerfunding.com,testing8888@fourcornerfunding.com,pepgrill1999@gmail.com \
     --keep-email=craig-borrower@fourcornerholdings.com
   ```
4. Check the client list, blockers and leftovers. Then run the same command with `--execute`, type the confirmation, and keep the JSON report.

## Open items
- Not found locally, so not in the command above: "Testing A Client", "Testing This Walkthrough", "test cash-pay", "Test A Client". If they exist on prod, add their emails.
- Partner "Awlad" is company **Exodias**. Its one client, #67 "Awlad Hossain" (`awladdc@gmail.com`), goes with it.
- Stripe is not touched. Local invoice and payment rows are deleted.
- Deleting audit events and signed PDFs removes the compliance trail for these test applications. That's acceptable for test data only.
- Host users' notifications that only mention these clients in their JSON (not addressed to a deleted user) stay, so their links will go nowhere.
- Not committed. The working tree is on `feature/owner-summary` with other uncommitted work.
