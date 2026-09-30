<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Assistant;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Assistant\AiAssistantConfig;
use App\Models\Assistant\AiAssistantNavigationRoute;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Host-only management of the approved Take Me There route map (adding a
 * portal page, repointing a key, switching one off) without a code deploy.
 * The model still can only pick from this map; paths are validated as
 * same-origin in-app paths, never a URL.
 */
class AssistantNavigationRouteController extends Controller
{
    protected function denyIfNotHostPartner(): ?JsonResponse
    {
        $user = auth()->user();

        if (! $user || $user->entity_name !== User::PARTNER || ! $user->partner?->is_host) {
            return ApiResponse::forbidden(__('auth.access_denied'));
        }

        return null;
    }

    /** GET assistant-config/{type}/routes — every effective key with where it comes from. */
    public function index(string $type)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }
        if (! in_array($type, AiAssistantConfig::TYPES, true)) {
            return ApiResponse::notFound('Unknown assistant type.');
        }

        $defaults = (array) config("assistant.navigation.{$type}", []);
        $rows = AiAssistantNavigationRoute::where('assistant_type', $type)->get()->keyBy('route_key');

        $out = [];
        foreach ($defaults as $key => $entry) {
            $row = $rows->get($key);
            $out[$key] = [
                'route_key' => $key,
                'label' => $row?->label ?? $entry['label'],
                'path' => $row?->path ?? $entry['path'],
                'is_active' => $row ? $row->is_active : true,
                'source' => $row ? 'customised' : 'default',
            ];
        }
        foreach ($rows as $key => $row) {
            if (! isset($out[$key])) {
                $out[$key] = ['route_key' => $key, 'label' => $row->label, 'path' => $row->path, 'is_active' => $row->is_active, 'source' => 'custom'];
            }
        }

        return ApiResponse::success(array_values($out));
    }

    /** PUT assistant-config/{type}/routes/{key} — add, repoint or switch off a key. */
    public function upsert(Request $request, string $type, string $key)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }
        if (! in_array($type, AiAssistantConfig::TYPES, true)) {
            return ApiResponse::notFound('Unknown assistant type.');
        }
        if (! preg_match(AiAssistantNavigationRoute::KEY_PATTERN, $key)) {
            return ApiResponse::validationError(['route_key' => 'Use lowercase letters, digits and underscores.'], 'Invalid route key.');
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'path' => ['required', 'string', 'max:200', 'regex:' . AiAssistantNavigationRoute::PATH_PATTERN],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $row = AiAssistantNavigationRoute::updateOrCreate(
            ['assistant_type' => $type, 'route_key' => $key],
            ['label' => $data['label'], 'path' => $data['path'], 'is_active' => $data['is_active'] ?? true]
        );

        return ApiResponse::success($row);
    }

    /** DELETE assistant-config/{type}/routes/{key} — drop the override (a default key returns to its default). */
    public function destroy(string $type, string $key)
    {
        if ($denied = $this->denyIfNotHostPartner()) {
            return $denied;
        }

        AiAssistantNavigationRoute::where('assistant_type', $type)->where('route_key', $key)->delete();

        return ApiResponse::success(null, 'Route override removed.');
    }
}
