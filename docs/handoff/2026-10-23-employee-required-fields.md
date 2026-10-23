# Employees — only name, email and country are required

**Date:** 2026-10-23
**Branch:** `hotfix` (uncommitted)
**Author:** awlad

## Problem
Adding an employee (`POST /api/v1/common/employees`) required name, position (`designation`), department, phone, city, state, country, address and email.

Editing (`PUT /api/v1/common/employees/{id}`) had a different mix:
- position, department, phone, city, state and address could not be cleared
- email could be cleared

The requirement is that only **name, country and email** are required, on both add and edit, and everything else is optional.

## Solution
- `StoreEmployeeRequest`:
  - `first_name`, `email` and `country` stay `required`
  - `designation`, `department`, `phone_number_1`, `city`, `state` and `address` are now `nullable`
- `UpdateEmployeeRequest`:
  - `first_name`, `email` and `country` are `sometimes|required`: they may be left out of a partial update, but not sent empty
  - `email` was `nullable` and is now required, the same as on add
  - the same six fields as on add are now `nullable`
- The other rules are unchanged:
  - phone stays unique within the partner when it is given
  - email stays unique within the partner on add, and unique against logins on edit when the employee has or is getting a user
  - the formats are unchanged
- Messages for rules that no longer exist were removed. `email.required` was added on update.
- Every affected `employees` column is already nullable, so no migration is needed.

## Files changed
- `app/Http/Requests/Api/V1/FCH/Common/Employee/StoreEmployeeRequest.php`: required → nullable for the six fields, and messages tidied.
- `app/Http/Requests/Api/V1/FCH/Common/Employee/UpdateEmployeeRequest.php`: the same, plus email required.
- `tests/Feature/Employee/EmployeeApiTest.php`:
  - `an_employee_needs_a_name_role_details_and_address` became `an_employee_needs_only_a_name_an_email_and_a_country`
  - new tests: create with only name/email/country; create with the optional fields sent empty; an edit cannot clear name/email/country; an edit can clear the optional fields

## Tests
- The 5 new or changed expectations failed against the old rules.
- `tests/Feature/Employee` + `tests/Feature/User` + `BorrowerCommonRouteAccessTest`: **106 passed**.

## Open items
- The admin panel (`Fch-admin-frontend-react`, `pages/employees/components/form-sections/`) still shows red `*` on Position, Department, Phone Number, Address, State and City. The labels only: client-side validation there is commented out, so it doesn't block saving. Not changed, because the request was server-side only.
- Not committed.
