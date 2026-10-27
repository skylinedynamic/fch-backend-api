<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Setting\StoreOpenAiConfigurationRequest;
use App\Models\Common\AppConfiguration;
use App\Models\User\User;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use Illuminate\Http\JsonResponse;

class OpenAiConfigurationController extends Controller
{
    protected OpenAiResponsesService $client;
    protected AppConfigurationService $configService;

    public function __construct(OpenAiResponsesService $client, AppConfigurationService $configService)
    {
        $this->client = $client;
        $this->configService = $configService;
    }


    /**
     * Host-partner guard for every endpoint here.
     *
     * These endpoints read and write a live OpenAI credential, so hiding the tab
     * in the admin UI is not enough — a tenant could still call the URL with its
     * own token. Mirrors the UI's PartnerHostRoute check (is_host, and an entity
     * type of Partner) so the screen and the API agree on who is a host user.
     *
     * @return JsonResponse|null  A 403 response when the caller is not a host user.
     */
    protected function denyIfNotHostPartner(): ?JsonResponse
    {
        $user = auth()->user();

        if (! $user || $user->entity_name !== User::PARTNER || ! $user->partner?->is_host) {
            return ApiResponse::forbidden(__('auth.access_denied'));
        }

        return null;
    }

    /**
     * Save the host-level OpenAI configuration.
     *
     * Uses a dedicated endpoint rather than the generic key/value save so these
     * credentials are actually validated — the generic endpoint stores whatever
     * it is given, which allowed a blank or malformed key to be saved silently.
     */
    public function store(StoreOpenAiConfigurationRequest $request)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        try {
            $validated = $request->validated();
            $partnerId = auth()->user()->partner_id;

            // A blank key means "keep what the server already has", so only
            // overwrite the stored key when a new one was actually supplied.
            // Stored encrypted: app_configurations is a plain key/value table and
            // this is a live credential.
            if (filled($validated['api_key'] ?? null)) {
                $this->configService->set(
                    'openai_api_key',
                    OpenAiResponsesService::encryptSecret($validated['api_key']),
                    AppConfiguration::MODULE_OPENAI,
                    $partnerId
                );
            }

            $this->configService->set('openai_aue_model', $validated['aue_model'] ?? '', AppConfiguration::MODULE_OPENAI, $partnerId);
            $this->configService->set('openai_reasoning_effort', $validated['reasoning_effort'], AppConfiguration::MODULE_OPENAI, $partnerId);

            return ApiResponse::success(null, __('app_config.saved_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }

    /**
     * Current configuration for the Settings screen.
     *
     * The API key itself is never returned — only whether one is stored — so the
     * credential does not travel to the browser or sit in front-end state.
     */
    public function show()
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        $partnerId = auth()->user()->partner_id;

        $storedKey = $this->configService->get('openai_api_key', null, AppConfiguration::MODULE_OPENAI, $partnerId);

        return ApiResponse::success([
            // Whether a key is stored - no form of it, not even the ciphertext,
            // which decrypts to the key once APP_KEY is known (BUG-128).
            'has_api_key' => filled($storedKey),
            'aue_model' => (string) ($this->configService->get('openai_aue_model', null, AppConfiguration::MODULE_OPENAI, $partnerId) ?: config('openai.default_model', 'gpt-5.6-luna')),
            'reasoning_effort' => (string) ($this->configService->get('openai_reasoning_effort', 'medium', AppConfiguration::MODULE_OPENAI, $partnerId) ?: 'medium'),
        ]);
    }

    /**
     * List the model ids available to the configured OpenAI key, so the admin
     * Settings screen can offer a real choice instead of a hard-coded list.
     */
    public function models()
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        $result = $this->client->listModels();

        // Always return a usable list: when the live lookup fails the service
        // supplies the configured fallback models, flagged so the UI can say so.
        // Effort options ship with it so the screen renders exactly the values
        // the save request validates against.
        return ApiResponse::success([
            'models' => $result['models'],
            'is_fallback' => $result['is_fallback'],
            'error' => $result['error'],
            'reasoning_efforts' => (array) config('openai.reasoning_efforts', []),
        ]);
    }
}
