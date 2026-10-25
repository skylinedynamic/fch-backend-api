# CMS Blogs — a repeated title gets its own web address (BUG-045)

**Date:** 2026-10-24
**Branch:** bugfix/all-bugfix
**Author:** awlad

## Problem
QA defect log **BUG-045** (OP-CMS-13, Medium/P2): creating a blog post with the title of an existing post failed silently — `POST /admin/cms/blogs` answered 422 and the admin panel showed nothing.

The admin form sent a slug it made from the title; `BlogRequest`'s `unique` rule on `slug` (per partner) refused the duplicate before the model's own slug handling (Spatie `HasSlug`, which makes slugs unique) ever ran. The form has no slug field, so the error had nowhere to show (admin side: see the admin panel's handoff of the same date).

## Solution
`Blog::getSlugOptions()`:
- unique **within the partner** (`extraScope` on `partner_id`) — the same scope as the `blogs (slug, partner_id)` unique index; soft-deleted posts count, as they do in the index;
- a repeated title becomes `title-2`, `title-3`, … (`startSlugSuffixFrom(2)`);
- **set once**: editing a post keeps its address (`doNotGenerateSlugsOnUpdate`) — links and search results to it keep working.

The admin form no longer sends a slug for a new post, so the API makes it. A caller that sends an explicit slug already taken still gets the 422 (unchanged rule).

## Files changed
- `app/Models/CMS/Blog.php` — slug options.
- `tests/Feature/Website/CmsBlogAdminApiTest.php` — +3 tests.

## Tests
- New: a repeated title → `qa-draft-post`, `-2`, `-3`; a deleted post's slug is not reused (`gone-2`); editing keeps the slug. All three failed first (suffix `-1`, slug regenerated on edit), pass after. The existing "an explicit duplicate slug is refused" test still passes.
- `tests/Feature/Website` — 31 passed. Full suite: see the session report.

## Open items
- Posts saved before this change keep their slugs; none is renamed.
- Mark BUG-045 in the QA defect log back to In Progress, then Fixed after the stage retest.
