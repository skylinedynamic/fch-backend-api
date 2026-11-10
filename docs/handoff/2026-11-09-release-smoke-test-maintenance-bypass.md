# Release smoke test for the API, with maintenance bypass

2026-11-09

## Problem
The stage workflow got a maintenance bypass on 2026-11-05 (`2026-11-05-stage-smoke-test-maintenance-bypass.md`). The release workflow had nothing to carry it over to: unlike stage, it had no post-deploy smoke test, so a broken production API deploy still finished green.

## Solution
Added a smoke-test step to `deploy-release.yml`, based on the stage step. It sends `X-Maintenance-Bypass` with the value of the new `PROD_MAINTENANCE_BYPASS` secret, so it checks the new build while users see the maintenance page. The workflow still does not turn maintenance on or off.

It differs from stage in two ways:
- **URL:** it uses `PROD_API_URL`, falling back to `https://api.fourcornerfunding.com/up`. On 2026-11-09 the production API root answered 404 and `/up` answered 200, so testing the root would fail every deploy.
- **No log dump on failure:** the stage step "Check Server Logs on Failure" was left out on purpose. It prints `laravel.log` into the GitHub Actions log, and production logs may hold applicant data.

## Files changed
- `.github/workflows/deploy-release.yml`: new step "Smoke Test - Verify API is Responding" at the end of `build-and-deploy`.

## Testing
- No automated tests cover workflows. The edited YAML was parsed successfully.
- Checked by hand with curl: `https://api.fourcornerfunding.com/up` answers 200 with no maintenance on.
- Not yet run on GitHub Actions. First check: the next release deploy, with maintenance off and then on.

## Open items
- Add the repository secret `PROD_MAINTENANCE_BYPASS`, with the same value as the production server's Nginx snippets. Without it the header is sent empty, does not match, and the smoke test fails while maintenance is on.
- `PROD_API_URL` is optional. If it is already set, it must point at `/up`, not the API root.
- Confirm the production Nginx snippets cover the API, including `/up`. Server setup is a manual runbook, not in this repo.
- A failed smoke test turns the run red but does not roll back; the new code and migrations are already live by then.
