# Agreement Builder: Save as Template (API)

**Date:** 2026-11-17
**Branch:** feature/agreement-builder-modification
**Author:** Awlad Hossain

## Problem
In the Agreement Builder, a partner could only save the agreement itself (one per type). There was no way to keep a version of an agreement under a name and start from it later. The Templates dialog offered only the built-in starter templates.

## Solution
Added saved agreement templates: a named copy of the builder's blocks (`template_json`) that belongs to the partner that saved it.
- A partner saves its **borrower** agreement. The **host** saves both **partner** and **borrower**. Saving `slug=partner` for a non-host owner returns 422 on `slug`.
- A name is required (max `AgreementSavedTemplate::NAME_MAX` = 100, trimmed). It must be unique per owner and type, case-insensitive (422 on `name`). Another type or another partner may reuse it.
- Templates are private to their owner. Reading or deleting another partner's template returns 404.
- From Partner profile › Configuration the host names the partner with `partner_id`, using the same on-behalf check as the agreement endpoints: a host user holding `manage partner configuration`, else 403.
- Images in the blocks go to disk through the existing `AgreementImages` cast, like agreement templates.
- The on-behalf owner check moved from a private method of `AgreementTemplateController` into a new `ResolvesAgreementOwner` trait, shared by both controllers. Behaviour is unchanged (the existing `AgreementTemplateOnBehalfTest` covers it).

Endpoints (`routes/common.php`, under `common/agreement-templates`):
- `GET saved?slug=` lists templates, newest first, without `template_json`.
- `POST saved` takes `{slug, name, template_json}` and creates one.
- `GET saved/{id}` returns one template with `template_json`.
- `DELETE saved/{id}` deletes one.

## Files changed
- `database/migrations/2026_11_17_000001_create_agreement_saved_templates_table.php`: new table (`partner_id` FK cascade, `slug`, `name`, `template_json`, `created_by`).
- `app/Models/Common/AgreementSavedTemplate.php`: model, `NAME_MAX`, `slugs()`, image cast.
- `app/Services/FCH/Common/ManageAgreement/AgreementSavedTemplateService.php`: list / create (host-only partner type, unique name) / find / delete.
- `app/Http/Controllers/Api/V1/FCH/Common/ManageAgreement/AgreementSavedTemplateController.php`: new controller.
- `app/Http/Requests/.../AgreementSavedTemplateStoreRequest.php`, `AgreementSavedTemplateIndexRequest.php`: validation.
- `app/Http/Resources/FCH/Common/ManageAgreement/AgreementSavedTemplateResource.php`: `template_json` only with `withBlocks()`.
- `app/Traits/ResolvesAgreementOwner.php`: the on-behalf owner check, extracted.
- `app/Http/Controllers/.../AgreementTemplateController.php`: uses the trait.
- `routes/common.php`: the four routes.
- `lang/en/agreement.php`: `saved_template.*` messages.

## Tests
- Added: `tests/Feature/Agreement/AgreementSavedTemplateTest.php` (12 tests): save and list; partner can't save the partner type; host saves both; newest first; required and max-length validation; unique name per type, case-insensitive; same name for another partner; privacy (404); delete; on-behalf save; on-behalf forbidden (403); guest (401).
- Added: `AgreementImageStorageTest::an_agreement_saved_as_a_template_keeps_its_logo_on_disk_and_reads_back_unchanged` (faked storage).
- Suites run: `tests/Feature/Agreement`, 108 passed. Full suite: see the PR / session notes.

## Open / follow-up
- **Run the migration locally:** `php artisan migrate` (not run against the `.env` database from this session).
- No rename or overwrite of a saved template. Save under a new name and delete the old one.
- Saving a template has no permission check beyond being able to resolve the owner, the same as the existing `agreement-templates/save`. If templates should need `update partner/borrower agreement settings`, both endpoints should get it together.
