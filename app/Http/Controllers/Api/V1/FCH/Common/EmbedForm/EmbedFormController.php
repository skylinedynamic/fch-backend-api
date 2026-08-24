<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\EmbedForm;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Services\Global\Website\EmbedForm\EmbedFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmbedFormController extends Controller
{
    public function __construct(
        protected EmbedFormService $embedFormService
    ) {}

    /**
     * Get embed config and code snippet for the authenticated partner.
     * GET /api/v1/embed-forms/config
     */
    public function getConfig(Request $request): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;

        // For host admin, allow ?partner_id= override
        if (auth()->user()?->partner?->is_host && $request->has('partner_id')) {
            $partnerId = $request->integer('partner_id');
        }

        $config = $this->embedFormService->getOrCreateConfig($partnerId);

        $baseUrl = config('app.url');
        $embedSnippet = "<iframe src=\"{$baseUrl}/form/{$config->public_key}\" width=\"100%\" height=\"800\" frameborder=\"0\" style=\"border:none;border-radius:8px;\"></iframe>";
        $directUrl = "{$baseUrl}/form/{$config->public_key}";

        return ApiResponse::success([
            'id' => $config->id,
            'public_key' => $config->public_key,
            'form_title' => $config->form_title,
            'allowed_domains' => $config->allowed_domains ?? [],
            'notification_email' => $config->notification_email,
            'is_active' => $config->is_active,
            'embed_snippet' => $embedSnippet,
            'direct_url' => $directUrl,
            'submissions_count' => $config->submissions()->count(),
        ]);
    }

    /**
     * Update config settings.
     * PUT /api/v1/embed-forms/config
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;

        $request->validate([
            'form_title' => ['nullable', 'string', 'max:255'],
            'allowed_domains' => ['nullable', 'array'],
            'allowed_domains.*' => ['string', 'url'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $config = $this->embedFormService->updateConfig($partnerId, $request->all());

        return ApiResponse::success($config, 'Embed form configuration updated.');
    }

    /**
     * Regenerate the public embed key.
     * POST /api/v1/embed-forms/config/generate-key
     */
    public function regenerateKey(): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $config = $this->embedFormService->regenerateKey($partnerId);

        return ApiResponse::success([
            'public_key' => $config->public_key,
        ], 'Embed key regenerated successfully.');
    }

    /**
     * DataTable — fetch submissions.
     * POST /api/v1/embed-forms/submissions/fetch
     */
    public function fetchSubmissions(Request $request): JsonResponse
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
        $query = EmbedFormSubmission::with('attachments', 'partner');
        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'LIKE', "%{$s}%")
                    ->orWhere('last_name', 'LIKE', "%{$s}%")
                    ->orWhere('email', 'LIKE', "%{$s}%")
                    ->orWhere('phone', 'LIKE', "%{$s}%");
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

        $perPage = max(1, (int) ($filters['per_page'] ?? 15));
        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::successWithPagination(
            $paginated->items(),
            [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ]
        );
    }

    /**
     * Show a single submission.
     * GET /api/v1/embed-forms/submissions/{id}
     */
    public function showSubmission(int $id): JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $isHost = (bool) auth()->user()?->partner?->is_host;

        $submission = EmbedFormSubmission::with('attachments', 'partner')->find($id);

        if (!$submission) {
            return ApiResponse::notFound('Submission not found.');
        }

        if (!$isHost && $submission->partner_id !== $partnerId) {
            return ApiResponse::forbidden();
        }

        return ApiResponse::success($submission);
    }

    /**
     * Update submission status.
     * PUT /api/v1/embed-forms/submissions/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:new,reviewing,converted,rejected'],
        ]);

        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $isHost = (bool) auth()->user()?->partner?->is_host;

        $submission = EmbedFormSubmission::find($id);

        if (!$submission) {
            return ApiResponse::notFound('Submission not found.');
        }

        if (!$isHost && $submission->partner_id !== $partnerId) {
            return ApiResponse::forbidden();
        }

        $submission = $this->embedFormService->updateStatus($submission, $request->status);

        return ApiResponse::success($submission, 'Status updated successfully.');
    }

    /**
     * Secure file download for an attachment.
     * GET /api/v1/embed-forms/attachments/{id}/download
     */
    public function downloadAttachment(int $id): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $partnerId = auth()->user()?->partner_id ?? auth()->user()?->entity_id;
        $isHost = (bool) auth()->user()?->partner?->is_host;

        $attachment = EmbedFormAttachment::with('submission')->find($id);

        if (!$attachment) {
            return ApiResponse::notFound('File not found.');
        }

        if (!$isHost && $attachment->partner_id !== $partnerId) {
            return ApiResponse::forbidden();
        }

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            return ApiResponse::notFound('File no longer exists on server.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }
}
