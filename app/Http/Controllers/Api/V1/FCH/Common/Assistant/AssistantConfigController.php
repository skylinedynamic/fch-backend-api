<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Assistant;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Assistant\StoreAiAssistantConfigRequest;
use App\Models\Assistant\AiAssistantConfig;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;

class AssistantConfigController extends Controller
{
    /**
     * Host-partner guard for every endpoint here, matching
     * OpenAiConfigurationController::denyIfNotHostPartner() — these screens
     * control the model, token/effort budget, and enablement OpenAI runs on
     * behalf of every tenant, so the API itself must refuse a non-host
     * caller regardless of what the admin UI hides.
     */
    protected function denyIfNotHostPartner(): ?JsonResponse
    {
        $user = auth()->user();

        if (! $user || $user->entity_name !== User::PARTNER || ! $user->partner?->is_host) {
            return ApiResponse::forbidden(__('auth.access_denied'));
        }

        return null;
    }

    public function index()
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        $configs = collect(AiAssistantConfig::TYPES)->map(
            fn (string $type) => AiAssistantConfig::find($type)
        );

        return ApiResponse::success($configs->map(fn (AiAssistantConfig $c) => [
            'assistant_type' => $c->assistant_type,
            'model' => $c->model,
            'reasoning_effort' => $c->reasoning_effort,
            'max_output_tokens' => $c->max_output_tokens,
            'vector_store_id' => $c->vector_store_id,
            'knowledge_manifest_version' => $c->knowledge_manifest_version,
            'is_enabled' => $c->is_enabled,
            'is_ready' => $c->isReady(),
        ]));
    }

    public function store(StoreAiAssistantConfigRequest $request)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        try {
            $data = $request->validated();

            $config = AiAssistantConfig::find($data['assistant_type']);
            $wantsEnabled = (bool) ($data['is_enabled'] ?? false);

            // A config can only be switched on once it actually has a
            // knowledge base attached — either a real vector store (the
            // knowledge sync command) or local inline-knowledge files (the
            // fallback used when the OpenAI key can't upload files/vector
            // stores). Refused here rather than silently saved and going
            // live with nothing behind it.
            $hasKnowledge = filled($config->vector_store_id) || $config->hasInlineKnowledgeFiles();
            if ($wantsEnabled && ! $hasKnowledge) {
                return ApiResponse::error(__('assistant.cannot_enable_without_knowledge'), 422);
            }

            $config->model = $data['model'] ?? null;
            $config->reasoning_effort = $data['reasoning_effort'] ?? null;
            $config->max_output_tokens = $data['max_output_tokens'] ?? null;
            $config->is_enabled = $wantsEnabled;
            $config->save();

            return ApiResponse::success(null, __('assistant.config_saved'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('assistant.config_save_failed'));
        }
    }
}
