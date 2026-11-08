<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\EmbedForm;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\EmbedForm\EmbedFormConfigResource;
use App\Http\Requests\Api\V1\FCH\Common\EmbedForm\ConvertEmbedFormSubmissionRequest;
use App\Http\Requests\Api\V1\FCH\Common\EmbedForm\FetchEmbedFormSubmissionsRequest;
use App\Support\EmbedForm\ExistingBorrowerConfirmationRequired;
use App\Http\Requests\Api\V1\FCH\Common\EmbedForm\UpdateEmbedFormConfigRequest;
use App\Http\Requests\Api\V1\FCH\Common\EmbedForm\UpdateEmbedFormSubmissionStatusRequest;
use App\Http\Resources\FCH\EmbedForm\EmbedFormSubmissionResource;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use App\Support\EmbedForm\EmbedFormLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmbedFormController extends Controller
{
    /** Columns the Loan Applications list may sort on (its sortable headers). */
    private const SORTABLE_SUBMISSION_FIELDS = ['first_name', 'email', 'desired_funding_amount', 'status', 'created_at'];

    public function __construct(
        protected EmbedFormService $embedFormService
    ) {
    }

    /**
     * Get embed config and code snippet for the authenticated partner.
     * GET /api/v1/common/embed-forms/config
     */
    public function getConfig(Request $request): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;

        // For host admin, allow ?partner_id= override
        if (auth()->user()?->partner?->is_host && $request->has('partner_id')) {
            $partnerId = $request->integer('partner_id');
        }

        $location = $this->embedFormService->locationFor($partnerId);

        // Deliberately does NOT create one when the partner has nowhere to
        // publish. This endpoint is what the settings screen loads, and creating
        // a form here would be a side effect of merely opening the page -
        // handing out a key whose link resolves nowhere. The screen shows the
        // warning instead, and the form is created once a domain is set.
        $config = $location->isAvailable()
            ? $this->embedFormService->getOrCreateConfig($partnerId)
            : $this->embedFormService->findConfig($partnerId);

        if (!$config) {
            return ApiResponse::success(
                $this->unpublishableConfig($partnerId, $location),
                $location->warning() ?? __('embed_form.form_not_found_or_inactive')
            );
        }

        return ApiResponse::success(new EmbedFormConfigResource($config));
    }

    /**
     * The shape the settings screen expects when no form exists yet.
     *
     * Same keys as the resource so the screen can bind to them unconditionally;
     * the two `form_url_*` fields are what it renders the warning from.
     */
    private function unpublishableConfig(?int $partnerId, EmbedFormLocation $location): array
    {
        return [
            'id' => null,
            'partner_id' => $partnerId,
            'public_key' => null,
            'form_title' => null,
            'allowed_domains' => [],
            'notification_email' => null,
            'is_active' => false,
            'direct_url' => null,
            'embed_script_url' => null,
            'form_url_available' => $location->isAvailable(),
            'form_url_warning' => $location->warning(),
            'submissions_count' => 0,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * Update config settings.
     * PUT /api/v1/common/embed-forms/config
     */
    public function updateConfig(UpdateEmbedFormConfigRequest $request): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;

        try {
            // validated(), not all(): only the fields the request class knows
            // about reach the service, so an unexpected key cannot be written
            // through.
            $config = $this->embedFormService->updateConfig($partnerId, $request->validated());
        } catch (\DomainException $e) {
            // No domain, so there is no form to configure yet.
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(new EmbedFormConfigResource($config), __('embed_form.config_updated'));
    }

    /**
     * Regenerate the public embed key.
     * POST /api/v1/common/embed-forms/config/generate-key
     */
    public function regenerateKey(): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;

        try {
            $config = $this->embedFormService->regenerateKey($partnerId);
        } catch (\DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success([
            'public_key' => $config->public_key,
        ], __('embed_form.key_regenerated'));
    }

    /**
     * DataTable — fetch submissions.
     * POST /api/v1/common/embed-forms/submissions/fetch
     */
    public function fetchSubmissions(FetchEmbedFormSubmissionsRequest $request): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $isHost = (bool) auth()->user()?->partner?->is_host;

        // Host can see all partners or filter by partner_id
        if ($isHost && $request->has('partner_id') && $request->partner_id) {
            $partnerId = $request->integer('partner_id');
        } elseif ($isHost && !$request->has('partner_id')) {
            $partnerId = null; // all
        }

        $filters = $request->only(['search', 'status', 'date_from', 'date_to', 'per_page']);

        // Build query manually for host to see all
        $query = EmbedFormSubmission::with('attachments.docType', 'coBorrowers', 'partner');
        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'LIKE', "%{$s}%")
                    ->orWhere('last_name', 'LIKE', "%{$s}%")
                    ->orWhere('email', 'LIKE', "%{$s}%")
                    ->orWhere('phone', 'LIKE', "%{$s}%")
                    // The list shows the business beside the applicant, so a
                    // search for what is on screen has to find it.
                    ->orWhere('business_name', 'LIKE', "%{$s}%")
                    ->orWhere('business_email', 'LIKE', "%{$s}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $baseQuery = EmbedFormSubmission::query();
        if ($partnerId) {
            $baseQuery->where('partner_id', $partnerId);
        }
        $totalCount = (clone $baseQuery)->count();
        $newCount = (clone $baseQuery)->where('status', 'new')->count();
        $reviewingCount = (clone $baseQuery)->where('status', 'reviewing')->count();
        $convertedCount = (clone $baseQuery)->where('status', 'converted')->count();

        // The list's sortable columns only (BUG-009: the sort used to be ignored,
        // always newest first). Anything else falls back to newest first.
        $sortField = $request->input('sort_field');
        if ($sortField === 'desired_funding_amount') {
            // The column shows requested_amount, which is where submissions have
            // stored the amount since the funding-application rewrite; the
            // legacy column only holds it for older ones.
            $query->orderByRaw('COALESCE(requested_amount, desired_funding_amount) ' . ($request->input('sort_order') === 'asc' ? 'asc' : 'desc'));
        } elseif (in_array($sortField, self::SORTABLE_SUBMISSION_FIELDS, true)) {
            $query->orderBy($sortField, $request->input('sort_order') === 'asc' ? 'asc' : 'desc');
            if ($sortField === 'first_name') {
                $query->orderBy('last_name', $request->input('sort_order') === 'asc' ? 'asc' : 'desc');
            }
        }

        $perPage = max(1, (int) ($filters['per_page'] ?? 15));
        $paginated = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return ApiResponse::successWithPagination(
            EmbedFormSubmissionResource::collection($paginated->items()),
            [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'stats' => [
                    'total' => $totalCount,
                    'new_count' => $newCount,
                    'reviewing_count' => $reviewingCount,
                    'converted_count' => $convertedCount,
                ],
            ]
        );
    }

    /**
     * Show a single submission.
     * GET /api/v1/common/embed-forms/submissions/{id}
     */
    public function showSubmission(int $id): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $isHost = (bool) (auth()->user()?->is_host || auth()->user()?->partner?->is_host);

        $submission = EmbedFormSubmission::with('attachments.docType', 'coBorrowers', 'partner', 'convertedBorrower', 'convertedFundingApplication', 'salesRepUser')->find($id);

        if (!$submission) {
            return ApiResponse::notFound(__('embed_form.submission_not_found'));
        }

        if (!$isHost && $submission->partner_id !== $partnerId) {
            return ApiResponse::forbidden();
        }

        return ApiResponse::success(new EmbedFormSubmissionResource($submission));
    }

    /**
     * Update submission status.
     * PUT /api/v1/common/embed-forms/submissions/{id}/status
     */
    public function updateStatus(UpdateEmbedFormSubmissionStatusRequest $request, int $id): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $isHost = (bool) (auth()->user()?->is_host || auth()->user()?->partner?->is_host);

        $submission = EmbedFormSubmission::find($id);

        if (!$submission) {
            return ApiResponse::notFound(__('embed_form.submission_not_found'));
        }

        if (!$isHost && $submission->partner_id !== $partnerId) {
            return ApiResponse::forbidden();
        }

        // Converted is the end of the road: a borrower and a funding application
        // already exist, and moving it back would let it be converted again.
        if ($submission->status === EmbedFormSubmission::STATUS_CONVERTED) {
            return ApiResponse::error(__('embed_form.status_locked_converted'), 422);
        }

        $submission = $this->embedFormService->updateStatus($submission, $request->status);

        return ApiResponse::success(new EmbedFormSubmissionResource($submission), __('embed_form.status_updated'));
    }

    /**
     * Secure file download for an attachment.
     * GET /api/v1/common/embed-forms/attachments/{id}/download
     */
    public function downloadAttachment(int $id): StreamedResponse|JsonResponse
    {
        return $this->embedFormService->downloadAttachment($id);
    }

    /**
     * Convert submission to a Borrower.
     * POST /api/v1/common/embed-forms/submissions/{id}/convert-to-borrower
     */
    /**
     * The submission the signed-in partner may convert, or the response that
     * refuses it (not found, another partner's, already converted, rejected).
     */
    private function convertibleSubmission(int $id): EmbedFormSubmission|JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $isHost = (bool) (auth()->user()?->is_host || auth()->user()?->partner?->is_host);

        $submission = EmbedFormSubmission::find($id);

        if (!$submission) {
            return ApiResponse::notFound(__('embed_form.submission_not_found'));
        }

        if (!$isHost && $submission->partner_id !== $partnerId) {
            return ApiResponse::forbidden();
        }

        if ($submission->status === EmbedFormSubmission::STATUS_CONVERTED) {
            return ApiResponse::error(__('embed_form.already_converted'), 422);
        }

        // A rejected application is moved back to New or In Review before it
        // can be converted, so a rejection is never undone by one click (BUG-098).
        if ($submission->status === EmbedFormSubmission::STATUS_REJECTED) {
            return ApiResponse::error(__('embed_form.rejected_cannot_convert'), 422);
        }

        return $submission;
    }

    /**
     * What converting would do - join an existing client or create a new one -
     * so the Convert modal can say so before the partner confirms. Writes nothing.
     * GET /api/v1/common/embed-forms/submissions/{id}/conversion-preview
     */
    public function conversionPreview(int $id): JsonResponse
    {
        $submission = $this->convertibleSubmission($id);
        if ($submission instanceof JsonResponse) {
            return $submission;
        }

        try {
            return ApiResponse::success($this->embedFormService->conversionPreview($submission));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function convertToBorrower(ConvertEmbedFormSubmissionRequest $request, int $id): JsonResponse
    {
        $submission = $this->convertibleSubmission($id);
        if ($submission instanceof JsonResponse) {
            return $submission;
        }

        try {
            $borrower = $this->embedFormService->convertToBorrower($submission, $request->validated());
            return ApiResponse::success($borrower, __($borrower['is_existing_borrower'] ?? false ? 'embed_form.converted_to_existing_borrower' : 'embed_form.converted_to_borrower'));
        } catch (ExistingBorrowerConfirmationRequired $e) {
            // 409: the request is fine but needs the partner's go-ahead first.
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'code' => 'existing_borrower_confirmation_required',
                'data' => ['existing_borrower' => $e->borrower],
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::validationError($e->errors(), $e->getMessage());
        } catch (\Throwable $e) {
            // The service has already logged the cause. The exception's own
            // text is for developers, not for the toast it would end up in.
            return ApiResponse::error(__('embed_form.conversion_failed'), 500);
        }
    }
}

