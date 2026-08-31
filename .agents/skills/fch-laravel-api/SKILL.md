---
name: fch-laravel-api
description: Complete architecture standards, RBAC scoping, Datatable QueryFilters, API Resources, Form Requests, and service patterns for the entire FCH Laravel backend API (fch-api). Use when editing PHP controllers, services, models, migrations, or API resources across any module.
---

# FCH Laravel Backend API Standards

## 1. Controller & Service Pattern
- **Controllers** (`app/Http/Controllers/Api/V1/...`) must remain thin, delegating all business logic, database queries, and third-party integrations to dedicated **Service classes** (`app/Services/...`).
- **Form Requests** (`app/Http/Requests/Api/V1/...`) must be created and used for validation of incoming API payloads instead of inline controller validation.

## 2. Multi-Tenant RBAC & Query Scoping (`applyRbacScope`)
- Every database query returning entity data (Invoices, Payments, Borrower Profiles, Applications, Subscriptions, Credit Accounts, Capital Access) must enforce tenant RBAC scoping:
  - **Host Partner (`is_host == 1`)**: Has administrative visibility across partners for platform-level data (e.g., all partner invoices/subscriptions), but restricted to host's own borrower/client data.
  - **Non-Host Partner (`is_host == 0`)**: Restrict strictly to `$query->where('partner_id', $authUser->partner_id)`.
  - **Borrower (`User::BORROWER`)**: Restrict strictly to `$query->where('borrower_id', $authUser->entity_id)`.

## 3. Date & Timezone Transformations
- Always use the `toTimezone($datetime, $timezone, $format)` helper function defined in `app/Helpers/helpers.php` inside API Resources:
  ```php
  'payment_date' => toTimezone($this->payment_date),
  'created_at' => toTimezone($this->created_at, null, 'Y-m-d'),
  ```
- Respect `x-timezone` request header automatically resolved by `toTimezone()`.

## 4. Remote DataTables & QueryFilter
- List endpoints powering remote DataTables should use `App\Services\Global\Datatable\QueryFilter::apply()`:
  - Explicitly map `$aliases` for joined fields (`partner_name`, `customer_name`, `borrower_name`, `invoice_id`, etc.).
  - Handle date ranges with `whereDate('created_at', '>=', $dateFrom)` and `whereDate('created_at', '<=', $dateTo)`.

## 5. API Response Consistency
- Always return HTTP responses via `App\Helpers\ApiResponse`:
  - `ApiResponse::success($data, $message)`
  - `ApiResponse::error($message)`
  - `ApiResponse::notFound($message)`
  - `ApiResponse::serverError($message)`
