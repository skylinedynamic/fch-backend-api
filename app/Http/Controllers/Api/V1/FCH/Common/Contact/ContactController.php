<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Contact;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Contact\FetchContactRequest;
use App\Http\Requests\Api\V1\FCH\Common\Contact\StoreContactRequest;
use App\Http\Requests\Api\V1\FCH\Common\Contact\UpdateContactRequest;
use App\Http\Resources\FCH\Contact\ContactResource;
use App\Models\Contact\Contact;
use App\Services\FCH\Common\Contact\ContactService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContactController extends Controller
{
    public function __construct(protected ContactService $contactService) {}

    /**
     * All Contacts
     */
    public function index()
    {
        try {
            return $this->contactService->getAll();

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('contact.contact_list_failed'));
        }
    }

    /**
     * Contact Details
     */
    public function show($id)
    {
        try {
            $contact = $this->contactService->find($id);

            return ApiResponse::success(new ContactResource($contact), __('contact.contact_details'));
        } catch (\Exception $e) {
            return ApiResponse::error(__('contact.contact_not_found'));
        }
    }

    /**
     * Contact With Pagination
     */
    public function fetchContacts(FetchContactRequest $request)
    {
        try {
            $partnerId = $request->user()->partner_id;
            $input = $request->validated();

            // Sanitize and cap pagination to avoid heavy payloads
            $perPage = isset($input['per_page']) ? (int) $input['per_page'] : null;
            $perPage = $perPage !== null ? max(1, min($perPage, 100)) : null; // cap at 100

            $filters = [
                'per_page' => $perPage,
                'page' => isset($input['page']) ? max(1, (int) $input['page']) : 1,
                'search' => isset($input['search']) ? trim((string) $input['search']) : null,
                'filters' => $input['filters'] ?? null,
                'sort_field' => $input['sort_field'] ?? null,
                'sort_order' => $input['sort_order'] ?? null,
            ];

            return $this->contactService->list($partnerId, $filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('contact.contact_list_failed'));
        }
    }

    /**
     * Store Contact
     */
    public function store(StoreContactRequest $request)
    {
        try {
            $contact = $this->contactService->create($request->validated());

            return response()->json([
                'status' => true,
                'message' => __('contact.contact_created'),
                'data' => new ContactResource($contact),
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('contact.contact_create_failed'));
        }
    }

    /**
     * Update Contact
     */
    public function update(UpdateContactRequest $request, int $id)
    {
        try {
            $contact = Contact::findOrFail($id);
            $partnerId = $request->user()->partner_id;

            if ($contact->partner_id !== $partnerId) {
                return response()->json([
                    'status' => false,
                    'message' => __('contact.unauthorized'),
                ], Response::HTTP_FORBIDDEN);
            }

            $contact = $this->contactService->update($contact, $request->validated());

            return response()->json([
                'status' => true,
                'message' => __('contact.contact_updated'),
                'data' => new ContactResource($contact),
            ]);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('contact.contact_update_failed'));
        }
    }

    /**
     * Delete Contact
     */
    public function destroy(Request $request, Contact $contact)
    {
        $partnerId = $request->user()->partner_id;

        if ($contact->partner_id !== $partnerId) {
            return response()->json([
                'status' => false,
                'message' => __('contact.unauthorized'),
            ], Response::HTTP_FORBIDDEN);
        }

        $this->contactService->delete($contact);

        return response()->json([
            'status' => true,
            'message' => __('contact.contact_deleted'),
        ]);
    }

    /**
     * Multiple Delete
     */
    public function multipleDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        if ($this->contactService->deleteMultiple($ids)) {
            return ApiResponse::success([], __('contact.contact_deleted'));
        }

        return response()->json(['status' => false, 'message' => __('contact.multiple_delete_failed')], 422);
    }
}
