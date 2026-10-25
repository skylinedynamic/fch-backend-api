# Capital Access — Tradeline Depth reads the whole credit file, factor wording (BUG-031)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-031** (CP-CAP-04, Medium/P2) on the borrower portal's Capital Access screen:
1. **Tradeline Depth had no value** for Wanda's client (Laborum Consequatur).
2. Estimated Total Access was identical for very different profiles.
3. "1 accounts" in Banking Relationships.

Causes in `ReadinessScoreCalculationService`:
- **Tradeline Depth** — the tiers are written on three figures (open accounts, high-limit accounts, total limit), but only `open_accounts` was read; the other two were taken as **0**, so the two upper tiers ("Multiple High-Limit & Installment Depth", "Good Depth with High-Limit account") could never match. With **no credit file** the factor returned no value at all — the blank QA saw.
- **Wording** — `"%d accounts, oldest relationship %d months"` / `"%d account(s)"` / `"%d months+"` with no singular: "1 accounts", "0 account(s)", "1 months+".

Item 2 is **by design** (product decision, 2026-10-24): the total is the sum of the maximum exposure of every play the client is not hard-blocked from. Not changed.

## Solution
- Tradeline Depth computes all three tier figures from the credit file's **open** trades: the count, the count with a credit limit of at least **$5,000** (`ReadinessScoreCalculationService::HIGH_LIMIT_ACCOUNT_MIN`), and the sum of their limits. Closed trades do not count.
- No credit file → value **"No credit file"** (score 0, as before).
- Wording: "No bank accounts", "1 account", "2 accounts, each older than 12 months", "1 account, oldest relationship 63 months"; "1 month+", "11 months+".

The approval-probability and approval-odds models read the Tradeline Depth label, so a deeper file now earns its points there too (with the seeded labels).

Checked on the local `fch-migration` database (read-only, rolled back): Tradeline Depth now reads Strong for 7 borrowers and Moderate for 1, where every borrower read Limited/Weak before; no "(s)" or "1 accounts" left.

## Files changed
- `app/Services/Borrower/CapitalAccess/ReadinessScoreCalculationService.php` — tradeline figures from open trades, `HIGH_LIMIT_ACCOUNT_MIN`, "No credit file", singular/plural wording.
- `tests/Integration/CreditBuilder/ReadinessScoreCalculationTest.php` — +10 tests.

## Tests
- New (10): tradeline tiers for 5 files (upper tiers reachable, no high-limit account, one open, closed trades ignored); no credit file; banking wording for 0/1/2 accounts; time-in-business wording for 1/11 months. Six failed first (two unreachable tiers, blank, "0 account(s)", "1 accounts", "1 months+"), all pass after.
- `tests/Integration/CreditBuilder` + `tests/Feature/CreditBuilder` — 136 passed. Full suite: see the session report.

## Open items
- **$5,000 high-limit threshold** is our assumption — confirm with the product owner; it is one constant.
- The approval models match Tradeline Depth **by label text**. The local database has edited labels ("Strong", "Moderate", …) that do not match the seeded ones the models expect, so there those borrowers get the "Thin file" points. Fine on a seeded database; worth matching by tier order instead of label.
- Item 2 stays as designed; QA can close it as "works as intended".
- Mark BUG-031 in the QA defect log back to In Progress, then Fixed after the stage retest.
