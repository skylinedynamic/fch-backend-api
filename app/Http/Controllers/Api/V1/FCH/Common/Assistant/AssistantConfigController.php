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
                // Every configured store with its own switch, in the host's
                // order; only the switched-on ones are ever searched.
                'vector_stores' => $c->vector_stores,
                'agent' => $agent ? [
                    'name' => $agent['name'] ?? null,
                    'model' => $agent['model'] ?? null,
                    'reasoning_effort' => $agent['reasoning']['effort'] ?? null,
                    // The vector stores the saved agent carries, so the host
                    // can see at a glance whether the platform and these
                    // settings point at the same knowledge base.
                    'agent_vector_store_ids' => $this->agentVectorStoreIds($agent),
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

        // Normalised before the rules run, so a repeated or blank row is
        // resolved once here rather than being validated and then re-resolved
        // differently on the way into storage.
        if ($request->has('vector_stores')) {
            $request->merge(['vector_stores' => AiAssistantConfig::parseStores($request->input('vector_stores'))]);
        }

        $data = $request->validate([
            'agent_id' => ['nullable', 'string', 'max:120', 'regex:/^agent_[A-Za-z0-9]+$/'],
            'enabled' => ['sometimes', 'boolean'],
            'vector_stores' => ['sometimes', 'array', 'max:' . AiAssistantConfig::MAX_VECTOR_STORES],
            'vector_stores.*.id' => ['required', 'string', 'max:120', 'regex:/^vs_[A-Za-z0-9]+$/'],
            'vector_stores.*.enabled' => ['required', 'boolean'],
        ], [
            'agent_id.regex' => 'The Agent ID must start with "agent_" and contain only letters and numbers.',
            'vector_stores.max' => 'An assistant can be given at most ' . AiAssistantConfig::MAX_VECTOR_STORES . ' vector stores.',
            'vector_stores.*.id.regex' => 'Each Vector Store ID must start with "vs_" and contain only letters and numbers.',
        ]);

        $agentId = $data['agent_id'] ?? null;
        $enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : AiAssistantConfig::find($type)->is_enabled;

        if ($enabled && blank($agentId)) {
            throw ValidationException::withMessages([
                'agent_id' => 'The Agent ID is required while the assistant is enabled.',
            ]);
        }

        // Ids are checked against OpenAI so a bad one is refused here rather
        // than failing later in front of a user in the chat. A check that never
        // reached OpenAI is not evidence against the id, though — refusing on a
        // network fault would make the screen unsaveable whenever the link to
        // OpenAI is down, so those are recorded and reported instead.
        $unverified = [];

        if (filled($agentId)) {
            $live = $this->agents->retrieveAgent($agentId);

            if (! $live['ok']) {
                if (! ($live['unreachable'] ?? false)) {
                    throw ValidationException::withMessages([
                        'agent_id' => 'OpenAI could not load this agent. ' . $live['error'],
                    ]);
                }

                $unverified[] = 'the Agent ID';
            }
        }

        // A store id that does not resolve would silently produce an assistant
        // that searches nothing and answers from the model alone — exactly the
        // failure this setting exists to prevent. Check it now, not in chat.
        //
        // Every row is checked, switched off ones included: a store is parked
        // to be switched back on later, and a typo found then would be a
        // surprise in front of a user rather than here.
        if (array_key_exists('vector_stores', $data)) {
            foreach ($data['vector_stores'] as $index => $row) {
                $store = $this->agents->retrieveVectorStore($row['id']);

                if ($store['ok']) {
                    continue;
                }

                if (! ($store['unreachable'] ?? false)) {
                    throw ValidationException::withMessages([
                        "vector_stores.{$index}.id" => "OpenAI could not load this Storage ID. " . $store['error'],
                    ]);
                }

                $unverified[] = "Storage ID \"{$row['id']}\"";
            }
        }

        $settings = ['agent_id' => $agentId, 'enabled' => $enabled];
        if (array_key_exists('vector_stores', $data)) {
            $settings['vector_stores'] = $data['vector_stores'];
        }

        AiAssistantConfig::saveSettings($type, $settings, auth()->user()->partner_id);

        if (! empty($unverified)) {
            // Saved, but say plainly what could not be confirmed: silently
            // accepting an unchecked id is how a broken knowledge base reaches
            // a borrower without anyone knowing it was never verified.
            return ApiResponse::success(null, 'Saved, but OpenAI could not be reached to verify '
                . implode(' and ', $unverified)
                . '. Check the server can reach api.openai.com, then run the assistant knowledge check.');
        }

        return ApiResponse::success(null, __('app_config.saved_successfully'));
    }

    /**
     * The vector store ids attached to a saved agent's file_search tool.
     *
     * @return string[]
     */
    protected function agentVectorStoreIds(array $agent): array
    {
        $ids = [];

        foreach ((array) ($agent['tools'] ?? []) as $tool) {
            if (is_array($tool) && ($tool['type'] ?? null) === 'file_search') {
                $ids = array_merge($ids, (array) ($tool['vector_store_ids'] ?? []));
            }
        }

        return array_values(array_unique(array_filter($ids, 'is_string')));
    }
}
