<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Setting\StoreOpenAiConfigurationRequest;
use App\Models\Common\AppConfiguration;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use App\Services\FCH\Common\Setting\AppConfigurationService;

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
     * Save the host-level OpenAI configuration.
     *
     * Uses a dedicated endpoint rather than the generic key/value save so these
     * credentials are actually validated — the generic endpoint stores whatever
     * it is given, which allowed a blank or malformed key to be saved silently.
     */
    public function store(StoreOpenAiConfigurationRequest $request)
    {
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
        $partnerId = auth()->user()->partner_id;

        $storedKey = $this->configService->get('openai_api_key', null, AppConfiguration::MODULE_OPENAI, $partnerId);

        return ApiResponse::success([
            'has_api_key' => filled($storedKey),
            // The stored ciphertext, never the decrypted key. Safe to display: it
            // is useless without APP_KEY, and it lets the admin confirm at a glance
            // that the credential is encrypted at rest.
            'api_key_encrypted' => (string) $storedKey,
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
