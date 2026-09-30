<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Assistant;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Assistant\AiAssistantConfig;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;

/**
 * Read-only status for the host: is_enabled/model/reasoning_effort come from
 * config/assistant.php (a code deploy changes them, not this screen) and
 * vector_store_id/knowledge_manifest_version come from actually running
 * `php artisan assistant:sync-knowledge` — there is nothing left for an
 * admin "save" action to persist, so this controller only ever reads.
 */
class AssistantConfigController extends Controller
{
    /**
     * Host-partner guard, matching OpenAiConfigurationController::denyIfNotHostPartner()
     * — this exposes which model/effort OpenAI runs on behalf of every
     * tenant, so the API itself must refuse a non-host caller regardless of
     * what the admin UI hides.
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
            'prompt' => collect($c->activePrompt())->only(['version', 'sha256', 'source'])->all(),
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
}
