<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Assistant\AiAssistantConfig;
use App\Models\PreQualification\PreQualificationAgentConfig;
use App\Models\User\User;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Host-only settings for the Pre-Qualification pipeline: which OpenAI Agent
 * runs each step and which vector stores that step searches.
 *
 * Each step is an Agent saved on the OpenAI platform. Its instructions, its
 * output schema, its model and its reasoning effort are edited there and are
 * read on every evaluation, so this stores only which agent runs and whether
 * it is switched on. A step with no agent configured keeps running the
 * instructions shipped in the codebase.
 */
class PreQualificationAgentConfigController extends Controller
{
    public function __construct(protected OpenAiAgentsService $agents)
    {
    }

    /**
     * Host-partner guard, matching OpenAiConfigurationController::denyIfNotHostPartner()
     * — these agents underwrite on behalf of every tenant, so the API itself
     * must refuse a non-host caller regardless of what the admin UI hides.
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

        $configs = collect(PreQualificationAgentConfig::TYPES)->map(
            fn (string $step) => PreQualificationAgentConfig::find($step)
        );

        return ApiResponse::success($configs->map(function (PreQualificationAgentConfig $c) {
            $live = filled($c->agent_id) ? $this->agents->retrieveAgent($c->agent_id) : null;
            $agent = $live['agent'] ?? null;

            return [
                'step' => $c->step,
                'agent_id' => $c->agent_id,
                'is_enabled' => $c->is_enabled,
                // False means this step runs the instructions that ship with
                // the codebase rather than an agent.
                'is_ready' => $c->isReady(),
                'vector_stores' => $c->vector_stores,
                'agent' => $agent ? [
                    'name' => $agent['name'] ?? null,
                    'model' => $agent['model'] ?? null,
                    'reasoning_effort' => $agent['reasoning']['effort'] ?? null,
                    // An underwriting step needs a structured result. An agent
                    // whose Text format is left on plain text fails every run,
                    // so the host is told here rather than after a borrower
                    // has submitted.
                    'has_structured_output' => ($agent['text']['format']['type'] ?? null) === 'json_schema',
                    'agent_vector_store_ids' => $this->agentVectorStoreIds($agent),
                ] : null,
                // Why the live agent could not be read (e.g. a key without api.agents.read).
                'agent_error' => $live['error'] ?? null,
            ];
        }));
    }

    /**
     * PUT prequalification-agent-config/{step} — which OpenAI Agent this step
     * runs, the stores it searches, and whether it runs on the agent at all.
     */
    public function update(Request $request, string $step)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        if (! in_array($step, PreQualificationAgentConfig::TYPES, true)) {
            return ApiResponse::notFound('Unknown pre-qualification step.');
        }

        // Normalised before the rules run, so a repeated or blank row is
        // resolved once here rather than being validated and then re-resolved
        // differently on the way into storage.
        if ($request->has('vector_stores')) {
            // Same normaliser the assistants use, so one Settings screen sends
            // one shape and a pasted list of ids works in both places.
            $request->merge(['vector_stores' => AiAssistantConfig::parseStores($request->input('vector_stores'))]);
        }

        $data = $request->validate([
            'agent_id' => ['nullable', 'string', 'max:120', 'regex:/^agent_[A-Za-z0-9]+$/'],
            'enabled' => ['sometimes', 'boolean'],
            'vector_stores' => ['sometimes', 'array', 'max:' . PreQualificationAgentConfig::MAX_VECTOR_STORES],
            'vector_stores.*.id' => ['required', 'string', 'max:120', 'regex:/^vs_[A-Za-z0-9]+$/'],
            'vector_stores.*.enabled' => ['required', 'boolean'],
        ], [
            'agent_id.regex' => 'The Agent ID must start with "agent_" and contain only letters and numbers.',
            'vector_stores.max' => 'A step can be given at most ' . PreQualificationAgentConfig::MAX_VECTOR_STORES . ' vector stores.',
            'vector_stores.*.id.regex' => 'Each Storage ID must start with "vs_" and contain only letters and numbers.',
        ]);

        $agentId = $data['agent_id'] ?? null;
        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : PreQualificationAgentConfig::find($step)->is_enabled;

        if ($enabled && blank($agentId)) {
            throw ValidationException::withMessages([
                'agent_id' => 'The Agent ID is required while this step is set to run on an agent.',
            ]);
        }

        // Ids are checked against OpenAI so a bad one is refused here rather
        // than failing mid-evaluation in front of a borrower. A check that
        // never reached OpenAI is not evidence against the id, though —
        // refusing on a network fault would make the screen unsaveable whenever
        // the link to OpenAI is down, so those are recorded and reported.
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
            } elseif (($live['agent']['text']['format']['type'] ?? null) !== 'json_schema') {
                // Refused outright rather than saved with a warning: an
                // underwriting step cannot store prose, so this agent would
                // fail every single evaluation it was given.
                throw ValidationException::withMessages([
                    'agent_id' => 'This agent does not return a structured result. Set its Text format to JSON Schema on the OpenAI platform, then save again.',
                ]);
            }
        }

        // A store id that does not resolve would silently produce a step that
        // searches nothing and underwrites from the model alone — exactly the
        // failure this setting exists to prevent. Every row is checked,
        // switched-off ones included, so a typo is found now rather than when
        // the store is switched back on.
        if (array_key_exists('vector_stores', $data)) {
            foreach ($data['vector_stores'] as $index => $row) {
                $store = $this->agents->retrieveVectorStore($row['id']);

                if ($store['ok']) {
                    continue;
                }

                if (! ($store['unreachable'] ?? false)) {
                    throw ValidationException::withMessages([
                        "vector_stores.{$index}.id" => 'OpenAI could not load this Storage ID. ' . $store['error'],
                    ]);
                }

                $unverified[] = "Storage ID \"{$row['id']}\"";
            }
        }

        $settings = ['agent_id' => $agentId, 'enabled' => $enabled];

        if (array_key_exists('vector_stores', $data)) {
            $settings['vector_stores'] = $data['vector_stores'];
        }

        PreQualificationAgentConfig::saveSettings($step, $settings, auth()->user()->partner_id);

        if (! empty($unverified)) {
            // Saved, but say plainly what could not be confirmed: silently
            // accepting an unchecked id is how a broken underwriting step
            // reaches a borrower without anyone knowing it was never verified.
            return ApiResponse::success(null, 'Saved, but OpenAI could not be reached to verify '
                . implode(' and ', $unverified)
                . '. Check the server can reach api.openai.com, then save again.');
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
