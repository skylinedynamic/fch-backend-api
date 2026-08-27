<?php

namespace App\Http\Controllers\Api\V1\Global\Website\EmbedForm;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest;
use App\Models\Common\AppConfiguration;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class PublicEmbedFormController extends Controller
{
    public function __construct(
        protected EmbedFormService $embedFormService
    ) {}

    /**
     * Return partner branding & form configuration for the embed form.
     * GET /api/v1/embed/config/{key}
     */
    public function config(string $key): JsonResponse
    {
        $config = $this->embedFormService->resolveConfig($key);

        if (!$config) {
            return ApiResponse::notFound('Form not found or is no longer active.');
        }

        $partner = $config->partner;
        $partnerId = $partner?->id;

        $branding = [];
        if ($partnerId) {
            $branding = AppConfiguration::where('partner_id', $partnerId)
                ->where('module', AppConfiguration::MODULE_BRANDING)
                ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo', 'header_logo', 'favicon', 'primary_color', 'secondary_color'])
                ->pluck('value', 'key')
                ->toArray();
        }

        $partnerName = $branding['site_name']
            ?? ($partner?->company_name ?: ($partner?->full_name ?: config('app.name')));

        $logoUrl = null;
        $logoPath = $branding['secondary_logo']
            ?? ($branding['primary_logo']
            ?? ($branding['header_logo']
            ?? $partner?->company_logo));

        if ($logoPath) {
            $logoUrl = asset('storage/' . $logoPath);
        }

        $faviconUrl = !empty($branding['favicon']) ? asset('storage/' . $branding['favicon']) : null;

        return ApiResponse::success([
            'form_title' => $config->form_title,
            'partner_name' => $partnerName,
            'partner_logo' => $logoUrl,
            'partner_favicon' => $faviconUrl,
            'primary_color' => $branding['primary_color'] ?? '#0d6efd',
            'secondary_color' => $branding['secondary_color'] ?? '#198754',
        ]);
    }

    /**
     * Handle form submission from the public embed form.
     * POST /api/v1/embed/submit/{key}
     */
    public function submit(SubmitEmbedFormRequest $request, string $key): JsonResponse
    {
        $config = $this->embedFormService->resolveConfig($key);

        if (!$config) {
            return ApiResponse::notFound('Form not found or is no longer active.');
        }

        // Honeypot: reject if filled
        if ($request->filled('_hp_company')) {
            return ApiResponse::success([], 'Submission received.');
        }

        $submission = $this->embedFormService->storeSubmission($config, $request->validated(), $request);

        return ApiResponse::success([
            'submission_uid' => $submission->submission_uid,
        ], 'Your application has been submitted successfully. We will be in touch soon!', 201);
    }
}
