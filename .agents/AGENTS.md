# Project Rules: fch-api

- **Controller Thinness**: Controllers must only delegate requests to Service classes and return JsonResponse using `ApiResponse` helper methods.
- **Tenant Security**: Never remove RBAC scoping (`applyRbacScope`). Non-host partners (`is_host == 0`) must never see other partners' data.
- **Date Conversion**: All API resources MUST use `toTimezone($date)` for date output formatting.
- **Validation**: Place request validation in FormRequest classes (`app/Http/Requests/Api/V1/...`).
