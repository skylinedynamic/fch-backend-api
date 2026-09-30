<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Assistant;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\AiAssistantPromptVersion;
use App\Models\User\User;
use App\Services\Assistant\AssistantPromptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Host-only prompt release management (create / list / activate / rollback).
 * Same host-partner guard as AssistantConfigController — a prompt controls
 * what every tenant's users are told, so the API refuses a non-host caller
 * regardless of what the admin UI shows.
 */
class AssistantPromptController extends Controller
{
    public function __construct(protected AssistantPromptService $prompts)
    {
    }

    protected function denyIfNotHostPartner(): ?JsonResponse
    {
        $user = auth()->user();

        if (! $user || $user->entity_name !== User::PARTNER || ! $user->partner?->is_host) {
            return ApiResponse::forbidden(__('auth.access_denied'));
        }

        return null;
    }

    /** GET assistant-config/{type}/prompts — the in-force prompt identity plus every version (no content). */
    public function index(string $type)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        if (! in_array($type, AiAssistantConfig::TYPES, true)) {
            return ApiResponse::notFound('Unknown assistant type.');
        }

        $active = AiAssistantConfig::find($type)->activePrompt();

        return ApiResponse::success([
            'in_force' => [
                'version' => $active['version'],
                'sha256' => $active['sha256'],
                'source' => $active['source'], // database | file (bundled default)
            ],
            'versions' => $this->prompts->list($type),
        ]);
    }

    /** POST assistant-config/{type}/prompts — adds an immutable version (not yet active). */
    public function store(Request $request, string $type)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        $data = $request->validate([
            'version' => ['required', 'string', 'max:40'],
            'content' => ['required', 'string', 'max:300000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $version = $this->prompts->create($type, $data['version'], $data['content'], $data['notes'] ?? null, auth()->id());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::validationError(['version' => $e->getMessage()], $e->getMessage());
        }

        return ApiResponse::success($version->makeHidden('content'), 'Prompt version created.', 201);
    }

    /** POST assistant-config/{type}/prompts/{id}/activate — release, or roll back to an older version. */
    public function activate(string $type, int $id)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        $version = AiAssistantPromptVersion::where('assistant_type', $type)->find($id);

        if (! $version) {
            return ApiResponse::notFound('Prompt version not found.');
        }

        $active = $this->prompts->activate($version, auth()->id());

        return ApiResponse::success($active->makeHidden('content'), 'Prompt version activated.');
    }

    /** POST assistant-config/{type}/prompts/revert-to-bundled — fall back to the code-shipped prompt. */
    public function revertToBundled(string $type)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        if (! in_array($type, AiAssistantConfig::TYPES, true)) {
            return ApiResponse::notFound('Unknown assistant type.');
        }

        $this->prompts->revertToBundled($type);

        return ApiResponse::success(null, 'Reverted to the bundled prompt.');
    }
}
