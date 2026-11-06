# BUG-190: public form shows every required-field error on the first Continue

**Date:** 2026-11-06

## Problem
On the public application form (`/form/<key>`, `resources/views/embed-form/index.blade.php`), Step 1 with only a 4-digit ZIP typed showed only "ZIP code must be 5 digits." on Continue. The other empty required fields showed nothing until Continue was clicked again. Decision D5: every error must show at once.

The validation rules were not at fault. Reproduced locally with Playwright:
1. Pressing Continue took the focus off the ZIP field (mousedown → focusout).
2. Leaving the field drew its error message.
3. The message pushed the Continue button 22px down (y 928 → 950) before the mouse was released.
4. The mouseup missed the button, so no `click` fired and `navigateStep()` / `validateStep()` never ran.

A second click worked because nothing moved then. Any field left with a bad value right before clicking Continue had the same effect.

## Solution
Back, Continue and Submit (`btnPrev`, `btnNext`, `btnSubmit`) call `preventDefault()` on `mousedown`. The focus stays in the field until the click lands, so nothing moves under the pointer, and the step check runs and flags every field at once. Keyboard use is unchanged.

## Files changed
- `resources/views/embed-form/index.blade.php`
- Admin panel: `Fch-admin-frontend-react/e2e/sidebar/embedded-form/public-form/step-errors.live.spec.ts` (new).

## Testing
- **New live spec:** it takes the host's public key from Configuration, opens the public form, types a 4-digit ZIP and clicks Continue straight away. It failed before the fix ("This field is required." not found) and passed after. `e2e/sidebar/embedded-form/` live: 6/6 passed.
- **Playwright probe on the local form,** three ways (type then click, type then Enter, type slowly then click). Before the fix, "type then click" flagged 1 field. After, all three flag 10 fields on the first Continue.

## Open items
- Not committed.
