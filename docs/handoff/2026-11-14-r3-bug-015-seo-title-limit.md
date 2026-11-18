# R3-BUG-015: an SEO title is at most 120 characters

Date: 2026-11-14

## Problem

Host Admin › CMS › SEO Settings › edit 'About Us' (`/cms/seo-settings/edit/9`). QA typed a 120-character SEO Title and clicked Update. The answer was "SEO settings updated successfully", and the API returned the full title. The Add form behaved the same.

`SeoRequest` allowed `max:255`, and the admin field had no limit and no counter.

## Solution

- **The limit is 120**, as the product owner chose (QA's sheet suggested 60; search results show about 60 and cut the rest). `SeoSetting::META_TITLE_MAX_LENGTH = 120`. A new or changed `meta_title` longer than that gets 422 "The SEO title may not be longer than 120 characters." (`seo.validation_meta_title_max`).
- **Older long titles are kept.** A title saved before the limit may be longer. An edit that sends it back unchanged still saves, so other fields can be changed. Replacing it with another long title is refused.
  - The check reads the stored title from the user's own partner only. Another partner's title can't open the limit.
- **Admin:** the field shows a counter ("52 / 120") and stops at 120 (see the admin handoff of the same name).

## Files changed

- `app/Models/CMS/SeoSetting.php`: `META_TITLE_MAX_LENGTH`.
- `app/Http/Requests/Api/V1/FCH/Admin/CMS/SeoRequest.php`: the `meta_title` limit, the unchanged-title exception, and the message.
- `lang/en/seo.php`: `validation_meta_title_max`.
- `tests/Feature/Website/SeoMetaTitleLengthTest.php` (new, 8):
  - the limit is 120
  - Add takes 120 and refuses 121
  - Edit refuses a 121-character title and takes 120
  - an older long title left unchanged saves
  - it can't be swapped for another long one
  - another partner's long title doesn't open the limit

## Testing

- **Seen failing first:** 5 of the 8. After the limit moved from 60 to 120, the updated tests failed (4) before the constant changed.
- **`tests/Feature/Website`, `tests/Feature/General`:** 114 passed.

## Open items

- **Blog SEO title:** `BlogRequest`'s `meta_title` is still `max:255`. It isn't in this bug.
- **Meta description:** no limit (about 160 is usual). It isn't in this bug either.
- **Assignment:** the sheet assigns this to Arafat; it was done here on request.
