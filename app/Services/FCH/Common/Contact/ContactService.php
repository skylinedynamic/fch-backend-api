<?php

namespace App\Services\FCH\Common\Contact;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Contact\ContactResource;
use App\Exceptions\ContactInUseException;
use App\Models\Contact\Contact;
use App\Models\Lead\Lead;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

class ContactService
{
    use PaginatesApi, UploadsFiles;

    public function getAll()
    {
        $contacts = Contact::query()->where('partner_id', Auth::user()->partner_id)->orderBy('id', 'desc')->get();

        return ApiResponse::success(ContactResource::collection($contacts), __('contact.retrieved_success'));
    }

    /**
     * Paginate contacts for a business.
     */
    public function list(int $partnerId, array $filters)
    {
        $query = Contact::query()->where('partner_id', $partnerId);

        if (! empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        // Apply Advanced Filters
        $aliases = [
            'email' => 'contacts.email',
            'mobile_number' => 'contacts.mobile_number',
            'company_name' => 'contacts.company_name',
            'address' => 'contacts.address',
            'is_referral_partner' => 'contacts.is_referral_partner',
        ];

        $rawFilters = $filters['filters'] ?? [];
        if (is_string($rawFilters)) {
            $rawFilters = json_decode($rawFilters, true) ?? [];
        }

        // Custom handling for 'full_name' filter to allow searching for the 'Referral Partner' badge
        if (isset($rawFilters['full_name']['constraints'])) {
            $query->where(function ($q) use ($rawFilters) {
                foreach ($rawFilters['full_name']['constraints'] as $c) {
                    $val = $c['value'] ?? null;
                    if ($val === null || $val === '') continue;

                    $q->where(function ($subQ) use ($val, $c) {
                        $mode = $c['matchMode'] ?? 'contains';
                        $likeVal = "%{$val}%";
                        if ($mode === 'startsWith') $likeVal = "{$val}%";
                        if ($mode === 'endsWith') $likeVal = "%{$val}";
                        if ($mode === 'equals') $likeVal = $val;
                        
                        $operator = $mode === 'equals' ? '=' : 'LIKE';
                        $subQ->whereRaw("CONCAT_WS(' ', contacts.first_name, contacts.last_name) {$operator} ?", [$likeVal]);

                        if (stripos('referral partner', $val) !== false || stripos('rp', $val) !== false) {
                            $subQ->orWhere('contacts.is_referral_partner', 1);
                        }
                    });
                }
            });
            unset($rawFilters['full_name']);
        }

        $query = QueryFilter::apply(
            $query,
            $filters['sort_field'] ?? 'id',
            $filters['sort_order'] ?? 'desc',
            $rawFilters,
            [
                'concat_fields' => [
                    'full_name' => ['contacts.first_name', 'contacts.last_name'],
                ],
                'aliases' => $aliases,
                'skip_sorting' => true, // We handle sorting manually below
            ]
        );

        // Sorting
        $sortField = $filters['sort_field'] ? $filters['sort_field'] : 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'full_name') {
            $query->orderBy('first_name', $sortOrder)->orderBy('last_name', $sortOrder);
        } else {
            $query->orderBy($sortField, $sortOrder);
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            ContactResource::class,
            __('contact.contact_listed')
        );
    }

    /**
     * Create a new contact.
     */
    public function create(array $contactData): Contact
    {
        DB::beginTransaction();

        try {
            $contactData['partner_id'] = auth()->user()->partner_id;
            $contactData['created_by'] = auth()->id();
            $contactData['updated_by'] = auth()->id();

            $contact = Contact::create($contactData);
            Log::info('Contact created successfully', ['contact_id' => $contact->id]);

            // // Handling Image Upload
            // if (isset($contactData['image']) && $contactData['image'] instanceof UploadedFile) {
            //      $this->uploadContactImage($contact, $contactData['image']);
            // }

            DB::commit();

            return $contact->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Contact creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update a contact.
     */
    public function update(Contact $contact, array $contactData): Contact
    {
        DB::beginTransaction();
        try {
            // Handling Image Upload
            if (isset($contactData['image']) && $contactData['image'] instanceof UploadedFile) {
                if ($contact->image) {
                    $this->deleteFile($contact->getRawOriginal('image'), Disk::UPLOADS);
                }
                $this->uploadContactImage($contact, $contactData['image']);
                unset($contactData['image']); // Remove from data to avoid double update if fillable
            }

            $oldIsReferralPartner = $contact->is_referral_partner;

            $contactData['updated_by'] = auth()->id();
            $contact->update($contactData);

            // If contact is no longer a referral partner, remove them from leads
            if ($oldIsReferralPartner && !$contact->is_referral_partner) {
                \App\Models\Lead\Lead::where('referral_partner_id', $contact->id)
                    ->update([
                        'referral_partner_id' => null,
                    ]);
            }

            DB::commit();
            Log::info('Contact updated successfully', ['contact_id' => $contact->id]);

            return $contact->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Contact update failed', [
                'contact_id' => $contact->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete a contact.
     */
    public function delete(Contact $contact): bool
    {
        // A lead's Referral Contact is kept: say on which leads (R3-BUG-017).
        $leads = $this->leadsReferredBy([$contact->id]);
        if ($leads->isNotEmpty()) {
            $names = $leads->map(fn (Lead $lead) => $this->leadName($lead));
            throw new ContactInUseException(
                trans_choice('contact.delete_blocked_referral', $leads->count(), ['count' => $leads->count(), 'leads' => $names->implode(', ')]),
                ['leads' => $leads->map(fn (Lead $lead) => ['id' => $lead->id, 'name' => $this->leadName($lead)])->values()->all()]
            );
        }

        try {
            if ($contact->image) {
                $this->deleteFile($contact->getRawOriginal('image'), Disk::UPLOADS);
            }

            $contact->delete();
            Log::info('Contact deleted', ['contact_id' => $contact->id]);

            return true;
        } catch (Throwable $e) {
            Log::error('Contact deletion failed', [
                'contact_id' => $contact->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Find contact by ID.
     */
    public function find(int $id): Contact
    {
        return Contact::where('id', $id)->where('partner_id', Auth::user()->partner_id)->firstOrFail();
    }

    /**
     * Delete Multiple
     */
    public function deleteMultiple(array $ids): bool
    {
        if (empty($ids)) {
            return false;
        }

        // Get contacts to delete their images
        $contacts = Contact::whereIn('id', $ids)->where('partner_id', auth()->user()->partner_id)->get();

        // If any is a lead's Referral Contact, none is deleted (R3-BUG-017).
        $leadCounts = $this->leadsReferredBy($contacts->pluck('id')->all())->countBy('referral_partner_id');
        if ($leadCounts->isNotEmpty()) {
            $inUse = $contacts->filter(fn (Contact $c) => $leadCounts->has($c->id))->values();
            throw new ContactInUseException(
                __('contact.delete_blocked_referral_named', ['names' => $inUse->map(fn (Contact $c) => $this->contactName($c))->implode(', ')]),
                ['contacts' => $inUse->map(fn (Contact $c) => [
                    'id' => $c->id,
                    'name' => $this->contactName($c),
                    'lead_count' => $leadCounts->get($c->id),
                ])->all()]
            );
        }
        foreach ($contacts as $contact) {
            if ($contact->image) {
                $this->deleteFile($contact->getRawOriginal('image'), Disk::UPLOADS);
            }
        }

        Contact::where('partner_id', auth()->user()->partner_id)
            ->whereIn('id', $ids)
            ->delete();

        return true;
    }

    /** The leads (not deleted) that have one of these contacts as their Referral Contact. */
    private function leadsReferredBy(array $contactIds)
    {
        return Lead::whereIn('referral_partner_id', $contactIds)
            ->orderBy('id')
            ->get(['id', 'referral_partner_id', 'contact_person', 'company_name']);
    }

    private function leadName(Lead $lead): string
    {
        return $lead->contact_person ?: ($lead->company_name ?: "#{$lead->id}");
    }

    private function contactName(Contact $contact): string
    {
        return trim("{$contact->first_name} {$contact->last_name}") ?: "#{$contact->id}";
    }

    protected function uploadContactImage(Contact $contact, UploadedFile $file)
    {
        $upload = $this->uploadFile($file, Folder::CONTACTS . "/{$contact->id}", Disk::UPLOADS);
        $contact->update(['image' => $upload['name']]); // Assuming uploadFile returns path or name
    }
}
