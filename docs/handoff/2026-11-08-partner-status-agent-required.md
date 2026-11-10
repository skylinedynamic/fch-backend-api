# Partner profile: Status & Agent saved on its own endpoint

## Problem
The Status & Agent tab saved through `PUT v1/admin/partners/{id}`, sending the
whole partner back. A status/agent change on partner 74 failed with "Enter a
valid 10-digit US phone number": its saved phone `(185) 877-2814` (and contact
phone `(134) 187-5765`) is not a valid US number (area code starts with 1) —
a field that isn't even on that tab. The agent's E-mail/Mobile boxes are
read-only and must be allowed to be empty.

## Solution
- New endpoints (admin route group, no per-permission middleware — by request):
  - `GET  v1/admin/partners/{id}/status-agent` → `{ id, status, assign_to, agent: { id, name, email, phone_number } | null }`
  - `PUT  v1/admin/partners/{id}/status-agent` → takes only `status` (one of `Partner::STATUS`) and `assign_to` (existing user); both required ("Please select a status." / "Please select an agent.").
- `PartnerController::statusAgent` / `updateStatusAgent`, `UpdatePartnerStatusAgentRequest`,
  `PartnerStatusAgentResource`, `PartnerService::findStatusAgent` / `updateStatusAgent`
  (signs out a suspended/inactive partner's login and writes the activity log, as before).
  The status→login sync is shared with `update()` via `syncUsersWithStatus()`.
- `UpdatePartnerRequest` no longer takes `status` / `assign_to`: the Partner
  information save can't change them.
- `lang/en/partner.php`: merged the duplicate `validation.status` key (the
  later, unused one silently overrode the earlier), added status/agent messages.
- Admin frontend: `getPartnerStatusAgent` / `updatePartnerStatusAgent`
  (`src/modules/partner/services.ts`); the Status & Agent tab loads from and
  saves to them (`PartnerProfile.jsx`); the general update no longer sends
  status/agent (`logic.ts`); `assign_to` errors map to the Agent field.

## Tests
- New `tests/Feature/Partner/PartnerStatusAgentApiTest.php` (load, save despite
  an invalid saved phone, agent with no email/phone, required + valid values,
  role gate, 404, Partner information save leaves status/agent alone).
- New `tests/Integration/Partner/PartnerStatusAgentServiceTest.php`.
- `PartnerAdminApiTest`: archive test renamed — archive/unarchive no longer
  need `update partners` (route middleware removed 2026-11-08 by request).
- Partner, Validation, General feature + Partner integration: 281 passed.

## Follow-up: unknown partner (`/partners/86`)
- Problem: opening a partner that doesn't exist showed an empty Status & Agent
  form with Update and four toasts ("Failed to load partner data" x2,
  "Partner not found." x2 — details + status-agent loads, doubled by StrictMode).
- Fix (admin `PartnerProfile.jsx`): a 403/404 from either load shows
  `ErrorPage` "Partner Not Found" with no toasts, as Clients/Leads/Employees/Users
  do (BUG-030, BUG-164). Resets when the id changes.
- Tests: new admin `tests/pages/partners/PartnerProfile.notFound.test.tsx`;
  `PartnerProfile.status.test.tsx` (BUG-171) now answers the status-agent
  endpoint — the split above had broken it. Admin partner tests: 96 passed.

## Also: Add New Department input box (admin)
- Problem: "Department already exists." showed under the field, but the input
  box wasn't marked — it only got `p-invalid`, which has no style on a plain
  input in this app. An empty name showed only a toast.
- Fix (admin `DepartmentModal.jsx`): input is `form-control`, gets
  `is-invalid` + `aria-invalid` on an error (red box), cleared on typing; an
  empty name shows "Department name is required" under the box.
- Tests: 2 added to admin `tests/pages/employees/DepartmentModal.test.tsx`.

## Also: deleting a department in use (admin + API)
- Problem: × on a department always opened "Delete Department?"; one that
  employees use was refused only after confirming, as a toast (BUG-183).
- Fix: `DepartmentService::list` adds `employees_count` (case-insensitive name
  match within the partner, as `destroy()` counts). Admin `DepartmentModal.jsx`:
  × on one in use opens the shared `DeleteBlockedModal` "Department Cannot Be
  Deleted" (same wording as the API) — no confirmation, no delete call; a free
  one opens the confirmation; a 422 refusal on confirm (assigned meanwhile)
  opens the blocked modal instead of a toast. Same two-modal pattern as
  clients and blog tags.
- Tests: `Feature/Employee/DepartmentApiTest::the_list_says_how_many_employees_use_each_department`;
  admin `DepartmentModal.test.tsx` (1 added, the BUG-183 refusal test now
  expects the modal). Backend employee tests 56 passed; admin employees 29 passed.
- Then, as the client modal's "Go to Client": the blocked modal has a
  "View Employees" button → `/employees?department=<name>`; `useEmployeeList`
  starts filtered to `?department=`. Tests: admin `DepartmentModal.test.tsx`
  (+1), new `Employees.departmentLink.test.tsx` (2). Admin employees: 32 passed.

## Left open
- Partner 74 still has invalid phone numbers on file; the Partner information
  save will ask for valid ones until they are corrected.
- Archive/unarchive/status-agent are gated by the admin role only, not the
  `update partners` permission. The frontend still hides Save without it.
