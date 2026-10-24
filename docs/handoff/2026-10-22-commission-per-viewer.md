# Commissions shown per viewer (host / host staff / partner / partner staff)

**Date:** 2026-10-22
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
The commission figures didn't depend on who was looking:
- **Host staff** (Admin-type employee logins) saw every deal and the host's whole share, on the Commissions list, the detail page and the dashboard.
- **Partner staff** saw the partner's commission, but the system never pays them per deal.
- **Partners** received the host's share (`admin_commission`, `admin_commission_amount`) in the list and detail API responses. Only the screen hid it.
- **Host staff could pay out their own commission.** `POST admin/commission/{id}/payout` only required an Admin-type login, and the Pay button only checked `payout.can_pay`.
- The dashboard card showed one paid total, with nothing about money still owed or paid to staff.

## Solution
A new `App\Services\FCH\Common\Commission\CommissionViewer` decides the role, the visible rows and the totals. The list, the detail page, the dashboard and the payout endpoint all use it, so they can't disagree:

| Viewer | Who | Rows | Money |
|---|---|---|---|
| `host_owner` | host's own login (`entity_type` Partner), **or host staff with `update commission`** | all | host share by payment status, partner share, paid to staff |
| `host_staff` | other host logins | clients assigned to them (`borrowers.assign_id`) | only their own payout: paid (invoices) + expected (the Pay calculation) |
| `partner_owner` | partner's own login | the partner's | partner share by payment status; never the host's share |
| `partner_staff` | other partner logins | the partner's clients assigned to them | none: deal counts only ("My Deals") |

- **List** (`Common\Commission\CommissionService::getCommissions`): `scope()` for rows. `summarize()` builds the cards and adds `statistics.viewer`. Fields:
  - owners: `paid_amount`, `pending_amount`, `unpaid_amount`
  - host owner also: `paid_to_staff`
  - host owner also: `deal_commission_amount` (whole commission, host + partners) and `payouts_due_amount` (what the host still has to pay partners and staff on funded deals; the payout calculation summed)
  - host staff: `my_paid_amount`, `my_expected_amount`, `my_expected_deals`
- **Rows** (`CommissionResource`): money fields are sent only to viewers allowed to see them. Host staff get `my_payout`. `filter()` drops the hidden fields, because `PaginatesApi` calls `toArray()` directly.
- **Detail** (`getCommissionById` + `CommissionDetailsResource`): same scoping and field rules, plus `viewer`. Partner staff get no `payout`. `can_pay` is false unless the viewer can pay.
- **Dashboard** (`DashboardService`): new `data.commission` card per viewer. Its `earned_amount` is commission on funded deals, paid out or not: paid + unpaid for owners, paid + expected for host staff. It's the figure the card shows. `counts.total_commission` is kept as "paid to this viewer" for older screens and isn't sent to partner staff.
- **Payout** (`Admin\Commission\CommissionService::processPayout`): returns 403 unless `CommissionViewer::canPay()`, which means a host owner or commission manager. New message `commission.payout_not_allowed`.

Why `update commission` lifts host staff to the owner view: whoever pays commissions out needs to see every deal. Without this rule, a manager would only see their own clients.

## Files changed
- `app/Services/FCH/Common/Commission/CommissionViewer.php`: new; role, scope, summary, `canPay()`
- `app/Services/FCH/Common/Commission/CommissionService.php`: list and detail scoped and summarised by the viewer
- `app/Http/Resources/FCH/Common/Commission/CommissionResource.php`: per-viewer fields, `my_payout`
- `app/Http/Resources/FCH/Common/Commission/CommissionDetailsResource.php`: per-viewer fields, `viewer`, `can_pay` gating
- `app/Services/FCH/Admin/Dashboard/DashboardService.php`: `commission` card per viewer
- `app/Services/FCH/Admin/Commission/CommissionService.php`: payout refused to non-payers
- `lang/en/commission.php`: `payout_not_allowed`
- `docs/test-findings.md`: F132

## Tests
- Added `tests/Feature/Commission/CommissionViewerTest.php` (9 tests). Five deals across all four viewers cover:
  - list rows, cards and fields
  - detail scoping and fields
  - the dashboard card
  - commission managers getting the host owner's view
  - host staff refused when paying themselves (403, `can_pay` false), with a manager allowed
- Every test failed before the change and passes after.
- Updated fixtures in `CommissionListTest`, `CommissionPayoutTest` and `CommissionPayoutPaymentEntryTest`. Their "host admin" login had no `entity_type`, which now means host staff. It's now the host's own login (`entity_type` Partner), matching how real host owners are stored. No expectation changed.
- Suites run:
  - Feature/Commission, CommissionPayout, Dashboard, Partner, Application, User, Employee: all OK
  - Full suite: 3339 tests, 0 failures, 6 errors. The 6 errors are the known stale-classmap ones in `General/*` and also fail without this change.

## Open / follow-up
- **Compensation endpoints are still open to any login of a partner** (`POST common/compensations`). A host staff member could raise their own Per Deal rate, which would then raise their payout and "My Commission". Recommended fix: host owner only, or a permission. Needs a decision; see the 2026-10-22 conversation.
- `saveCommission` and re-closing a funded deal reset a **Paid** commission to Draft/Pending or Processing/Unpaid, and the entered admin % isn't used in the split. Found while tracing, not fixed.
- The host owner's "Commission Earned" is gross. Staff payouts are shown alongside as "Paid to staff", not subtracted.
- Commission #8 locally was paid by the older flow (no payout record). Older paid commissions to host staff won't show in `paid_to_staff` or `my_paid_amount`.
- Two partner-type users locally have no `entity_type`. They're treated as partner staff.
