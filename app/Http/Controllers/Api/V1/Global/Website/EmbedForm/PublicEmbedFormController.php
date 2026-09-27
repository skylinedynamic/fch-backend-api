<?php

namespace App\Http\Controllers\Api\V1\Global\Website\EmbedForm;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
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

        $logoUrl = $this->resolveLogoUrl($branding, $partner);
        $faviconUrl = $this->resolveFaviconUrl($branding);

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

        // The domain allow-list is enforced in SubmitEmbedFormRequest::authorize(),
        // which runs before validation - see the note there.

        // Honeypot: reject if filled
        if ($request->filled('_hp_company')) {
            return ApiResponse::success([], __('embed_form.submission_received'));
        }

        $submission = $this->embedFormService->storeSubmission($config, $request->validated(), $request);

        return ApiResponse::success([
            'submission_uid' => $submission->submission_uid,
        ], __('embed_form.submission_success'), 201);
    }

    /**
     * The logo an outside site should render for this form.
     *
     * The same resolution as the hosted page's own header - see
     * EmbedFormPageController::resolveLogoUrl() for why the built address is
     * read rather than rebuilt from the stored path.
     */
    private function resolveLogoUrl(array $branding, ?Partner $partner): ?string
    {
        $resolved = ($branding['logo_document_full_url'] ?? null)
            ?: ($branding['logo_mail_full_url'] ?? null);

        if ($resolved) {
            return $resolved;
        }

        return $partner?->company_logo
            ? encodeUrl(asset('storage/' . ltrim($partner->company_logo, '/')))
            : null;
    }

    private function resolveFaviconUrl(array $branding): ?string
    {
        return ($branding['favicon_full_url'] ?? null) ?: null;
    }
}
