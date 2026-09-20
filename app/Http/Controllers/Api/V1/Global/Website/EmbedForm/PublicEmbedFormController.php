<?php

namespace App\Http\Controllers\Api\V1\Global\Website\EmbedForm;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest;
use App\Models\Common\AppConfiguration;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\FCH\Common\Setting\AppConfigurationService;


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
            return ApiResponse::notFound(__('embed_form.form_not_found_or_inactive'));
        }

        $partner = $config->partner;
        $partnerId = $partner?->id;

        $branding = [];
        if ($partnerId) {
            // getBrandingSettings() resolves each surface through its fallback
            // chain and inherits from the host, which reading raw rows did not -
            // a partner with only some logos uploaded got no logo at all here.
            $branding = app(AppConfigurationService::class)->getBrandingSettings($partnerId);
        }

        $partnerName = $branding['site_name']
            ?? ($partner?->company_name ?: ($partner?->full_name ?: config('app.name')));

        $logoUrl = null;
        // The embed form renders on white, so the document logo is the right one.
        $logoPath = $branding['logo_document']
            ?? ($branding['logo_mail']
            ?? $partner?->company_logo);

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
            return ApiResponse::notFound(__('embed_form.form_not_found_or_inactive'));
        }

        // Honeypot: reject if filled
        if ($request->filled('_hp_company')) {
            return ApiResponse::success([], __('embed_form.submission_received'));
        }

        $submission = $this->embedFormService->storeSubmission($config, $request->validated(), $request);

        return ApiResponse::success([
            'submission_uid' => $submission->submission_uid,
        ], __('embed_form.submission_success'), 201);
    }
}
