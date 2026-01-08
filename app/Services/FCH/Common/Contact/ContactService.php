<?php

namespace App\Services\FCH\Common\Contact;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Contact\ContactResource;
use App\Models\Contact\Contact;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        ];

        $query = QueryFilter::apply(
            $query,
            $filters['sort_field'] ?? 'id',
            $filters['sort_order'] ?? 'desc',
            $filters['filters'] ?? [],
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
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'desc' : 'asc';

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
            Log::info('✅ Contact created successfully', ['contact_id' => $contact->id]);

            // // Handling Image Upload
            // if (isset($contactData['image']) && $contactData['image'] instanceof UploadedFile) {
            //      $this->uploadContactImage($contact, $contactData['image']);
            // }

            DB::commit();

            return $contact->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Contact creation failed', ['error' => $e->getMessage()]);
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
                    $this->deleteFile($contact->getRawOriginal('image'), 'public');
                }
                 $this->uploadContactImage($contact, $contactData['image']);
                 unset($contactData['image']); // Remove from data to avoid double update if fillable
            }

            $contactData['updated_by'] = auth()->id();
            $contact->update($contactData);

            DB::commit();
            Log::info('✅ Contact updated successfully', ['contact_id' => $contact->id]);

            return $contact->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('❌ Contact update failed', [
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
        try {
            if ($contact->image) {
                $this->deleteFile($contact->getRawOriginal('image'), 'public');
            }

            $contact->delete();
            Log::info('✅ Contact deleted', ['contact_id' => $contact->id]);

            return true;
        } catch (Throwable $e) {
            Log::error('❌ Contact deletion failed', [
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
        return Contact::where('id', $id)->firstOrFail();
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
        $contacts = Contact::whereIn('id', $ids)->get();
        foreach ($contacts as $contact) {
            if ($contact->image) {
                $this->deleteFile($contact->getRawOriginal('image'), 'public');
            }
        }

        Contact::where('partner_id', auth()->user()->partner_id)
            ->whereIn('id', $ids)
            ->delete();

        return true;
    }

    protected function uploadContactImage(Contact $contact, UploadedFile $file)
    {
        $upload = $this->uploadFile($file, "contacts/{$contact->id}", 'public');
        $contact->update(['image' => $upload['name']]); // Assuming uploadFile returns path or name
    }
}
