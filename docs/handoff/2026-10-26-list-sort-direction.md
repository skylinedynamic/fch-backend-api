# List sort direction no longer inverted (BUG-010)

Date: 2026-10-26

## Problem
On the admin panel, the first click on a sortable header (shown as ascending) listed Z-A: Capital Access (Program Name), Credit Accounts (Name/Issuer/Category) and Employees (Employee Name, Code, Phone). `CapitalAccessService`, `CreditAccountService` and `EmployeeService` each flipped the `sort_order` they were sent (`asc` → `desc`), most likely so the screens' default `id asc` loaded newest first.

## Solution
The three services now sort in the direction they are asked; with no order given they still default to `desc` (newest first). The admin screens now ask for `id desc` on load, so the first page looks the same as before.

## Files changed
- `app/Services/FCH/Common/CreditBuilder/CapitalAccessService.php`: `sort_order` no longer inverted.
- `app/Services/FCH/Admin/CreditBuilder/CreditAccountService.php`: same.
- `app/Services/FCH/Common/Employee/EmployeeService.php`: same.
- `tests/Feature/Sorting/ListSortDirectionTest.php`: new; asc/desc per list, and newest first when no order is sent.

## Testing
- `tests/Feature/Sorting`: 4 failed before the fix, 4/4 passed after.
- `--filter "CapitalAccess|CreditAccount|Employee|Sort"`: 169/169 passed.

## Open items
- Deploy together with the admin change (`Fch-admin-frontend-react` handoff of the same name). With only this API change, the admin lists would load oldest first until the admin is deployed.
