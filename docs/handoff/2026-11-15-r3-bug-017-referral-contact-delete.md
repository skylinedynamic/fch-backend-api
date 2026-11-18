# R3-BUG-017: a lead's Referral Contact is not deleted

Date: 2026-11-15

## Problem

1. QA made contact 56 ("QA-TEST R3 Cont02") the Referral Contact on lead 228 (Lead Source = Referral Partner).
2. QA then deleted contact 56. There was no warning: "Contact deleted successfully."
3. Afterwards:
   - Lead 228 still had `referral_partner_id: 56`, although GET `common/contacts/56` was 404.
   - The lead page showed its required Referral Contact as empty.
   - The Leads list showed "Referral Partner" with no name.
   - Updating the lead saved and kept the dead id.

Causes:
- **No check before delete.** `ContactService::delete()` and `deleteMultiple()` never checked whether a lead uses the contact.
- **Soft delete.** `Contact` uses soft deletes, so the row stays. The foreign key's `onDelete('set null')` never runs.
- **Validation.** The lead request rule `exists:contacts,id` counts a soft-deleted contact as existing, so a dead id saved.

## Solution

- **Delete is refused while a lead uses the contact.** This follows the converted-lead delete.
  - **One contact** (`DELETE common/contacts/{id}`): 422 "This contact is the Referral Contact on 1 lead (QA-TEST R3 Captcha). Choose another Referral Contact there first." The response has `errors.leads` = [{id, name}].
  - **Bulk** (`POST common/contacts/multiple-delete`): if any selected contact is in use, none is deleted. 422 "Some selected contacts are the Referral Contact on a lead and cannot be deleted: QA-TEST R3 Cont02. Unselect them to delete the others, or choose another Referral Contact on those leads." The response has `errors.contacts` = [{id, name, lead_count}].
  - A deleted lead doesn't hold its contact.
- **A deleted contact can't be chosen.** `referral_partner_id` uses `Rule::exists('contacts', 'id')->whereNull('deleted_at')` on Store and Update lead details.
- **Data fix:** migration `2026_11_15_000001_clear_deleted_referral_contacts_from_leads` clears `referral_partner_id` on leads that point at a deleted contact, such as stage lead 228. When such a lead is next saved with Lead Source "Referral Partner", it asks for a new contact.

## Files changed

- `app/Exceptions/ContactInUseException.php` (new).
- `app/Services/FCH/Common/Contact/ContactService.php`: `delete()` and `deleteMultiple()` refuse a contact in use, through `leadsReferredBy()`.
- `app/Http/Controllers/Api/V1/FCH/Common/Contact/ContactController.php`: answers 422 with the details.
- `app/Http/Requests/Api/V1/FCH/Common/Lead/StoreLeadDetailsRequest.php`, `UpdateLeadDetailsRequest.php`: a deleted contact is refused.
- `lang/en/contact.php`: `delete_blocked_referral` (1 / many), `delete_blocked_referral_named`.
- `database/migrations/2026_11_15_000001_clear_deleted_referral_contacts_from_leads.php` (new): the data fix.
- `tests/Feature/Contact/ContactUsedAsReferralTest.php` (new, 6):
  - a single delete is refused, naming the lead
  - a bulk delete deletes none and names the contact
  - an unused contact is still deleted
  - a deleted lead doesn't block
  - a deleted contact can't be chosen, on Store and Update
  - the migration clears dead ids and keeps live ones

## Admin (Fch-admin-frontend-react)

No code change. The Contacts page already toasts the API's `message` on a failed bulk delete. `tests/pages/contacts/Contacts.test.tsx` gained a test for the 422 message (28 passed).

## Testing

- **Seen failing first:** 4 of the 6: single refuse, bulk refuse, deleted contact refused, migration. The other 2 (unused contact, deleted lead) pin behaviour that stays.
- **`tests/Feature/Contact`, `tests/Feature/Lead`:** 102 passed.
- **Full suite (after R3-BUG-017 to 020):** 5,611 tests, all passed (2 skipped).

## Open items

- **Run the migration on stage.** Lead 228's dead id 56 is then cleared.
- **The admin Contacts page** still calls `modules/contact/services` directly, without `queries.ts`. It was not touched here.
