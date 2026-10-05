<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Assistant;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Assistant\AiAssistantConfig;
use App\Models\User\User;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Host-only settings for the support assistants. Each assistant is an Agent
 * saved on the OpenAI platform; this stores which agent it runs and whether
 * it is switched on, and reports that agent's name and model as they are on
 * the platform right now. The agent itself is edited on the platform.
 */
class AssistantConfigController extends Controller
{
    public function __construct(protected OpenAiAgentsService $agents)
    {
    }

    /**
     * Host-partner guard, matching OpenAiConfigurationController::denyIfNotHostPartner()
     * — these agents answer on behalf of every tenant, so the API itself must
     * refuse a non-host caller regardless of what the admin UI hides.
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

        return ApiResponse::success($configs->map(function (AiAssistantConfig $c) {
            $live = filled($c->agent_id) ? $this->agents->retrieveAgent($c->agent_id) : null;
            $agent = $live['agent'] ?? null;

            return [
                'assistant_type' => $c->assistant_type,
                'agent_id' => $c->agent_id,
                'is_enabled' => $c->is_enabled,
                'is_ready' => $c->isReady(),
                'agent' => $agent ? [
                    'name' => $agent['name'] ?? null,
                    'model' => $agent['model'] ?? null,
                    'reasoning_effort' => $agent['reasoning']['effort'] ?? null,
                ] : null,
                // Why the live agent could not be read (e.g. a key without api.agents.read).
                'agent_error' => $live['error'] ?? null,
            ];
        }));
    }

    /**
     * PUT assistant-config/{type} — which OpenAI Agent this assistant runs
     * and whether the assistant is switched on.
     */
    public function update(Request $request, string $type)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }
        if (! in_array($type, AiAssistantConfig::TYPES, true)) {
            return ApiResponse::notFound('Unknown assistant type.');
        }

        $data = $request->validate([
            'agent_id' => ['nullable', 'string', 'max:120', 'regex:/^agent_[A-Za-z0-9]+$/'],
            'enabled' => ['sometimes', 'boolean'],
        ], [
            'agent_id.regex' => 'The Agent ID must start with "agent_" and contain only letters and numbers.',
        ]);

        $agentId = $data['agent_id'] ?? null;
        $enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : AiAssistantConfig::find($type)->is_enabled;

        if ($enabled && blank($agentId)) {
            throw ValidationException::withMessages([
                'agent_id' => 'The Agent ID is required while the assistant is enabled.',
            ]);
        }

        // An id that OpenAI does not know (or that this key cannot use) would
        // only fail later, in front of a user in the chat — refuse it here.
        if (filled($agentId)) {
            $live = $this->agents->retrieveAgent($agentId);

            if (! $live['ok']) {
                throw ValidationException::withMessages([
                    'agent_id' => 'OpenAI could not load this agent. ' . $live['error'],
                ]);
            }
        }

        AiAssistantConfig::saveSettings($type, ['agent_id' => $agentId, 'enabled' => $enabled], auth()->user()->partner_id);

        return ApiResponse::success(null, __('app_config.saved_successfully'));
    }
}
