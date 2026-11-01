# Partners see a commission only once its application is Closed Completed

**Date:** 2026-11-01
**Branch:** bugfix/all-bugfix (working tree, not committed)

## Problem
A White-Label or Certified partner (owner and staff) saw a deal's commission as soon as the host saved it on the funding application (Draft / Pending). That covered the Commissions list, its summary cards, a commission's page and the dashboard card. At that point the amount was worked out from the requested amount, and the host could still change the rates. The rule: a partner sees its commission only once the application is **Closed Completed**.

## Solution
`CommissionViewer::scope()` is the one place the list, the summary cards, the details page (`getCommissionById`) and the dashboard card ask. For `partner_owner` and `partner_staff` it now also requires the commission's funding application to be `FundingApplication::CLOSED_COMPLETED`. Soft-deleted applications are included, so a deal of a client deleted since still counts (BUG-107).

The check is on the **application status**, not on `commission_status`. If the host saves the commission again later, `saveCommission()` puts it back to Draft / Pending; because the application stays Closed Completed, the partner keeps seeing it.

The host owner, host staff and commission managers see the same as before.

## Files changed
- `app/Services/FCH/Common/Commission/CommissionViewer.php`: partner scopes require a Closed Completed application.
- `tests/Feature/Commission/CommissionViewerTest.php`: two new tests.
  - Partners (owner and staff) see a commission only once its application is Closed Completed: list, totals and details page (404 before), while the host still sees it.
  - A partner's dashboard commission card leaves out commissions that are not Closed Completed.

## Testing
- Both new tests failed before the change and pass after it.
- `tests/Feature/{Commission,CommissionPayout,Dashboard}`: 85 passed.
- Full fch-api suite: see below.

## Open items
- A commission's page opened by a partner before the application closes now returns 404 (the same as any deal outside the viewer's scope).
- `saveCommission()` resetting a funded deal's commission to Draft / Pending on a second save is unchanged. It is a separate question.
- Not committed.
