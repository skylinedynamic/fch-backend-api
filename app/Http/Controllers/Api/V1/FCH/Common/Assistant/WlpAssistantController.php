<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Assistant;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Assistant\StoreAssistantMessageRequest;
use App\Http\Resources\Assistant\ChatMessageResource;
use App\Models\Assistant\AiAssistantConfig;
use App\Models\User\User;
use App\Services\Assistant\SupportAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Backs FCH_WLP_Assistant_OpenAPI_v1_1.yaml's /wlp-assistant/* paths. The
 * `data` payload of each success response matches that schema exactly
 * (session_id/message_id/answer/actions/sources/escalation/policy); the
 * outer {status,message,data} wrapper is this codebase's own ApiResponse
 * convention, used by every other endpoint.
 *
 * routes/common.php's `auth:api` group only checks for a valid token — it
 * does not by itself exclude a borrower-typed user (unlike the borrower
 * route stack, which additionally requires the `borrower` middleware). The
 * reference controller enforces the WLP-only boundary with an explicit role
 * check (`abort_unless(in_array($role, [...]), 403)`); denyIfBorrower() is
 * the equivalent here, using this codebase's user_type discriminator.
 */
class WlpAssistantController extends Controller
{
    public function __construct(protected SupportAssistantService $assistantService)
    {
    }

    protected function denyIfBorrower(): ?JsonResponse
    {
        $user = Auth::user();

        if (! $user || $user->user_type === User::BORROWER) {
            return ApiResponse::forbidden(__('auth.access_denied'));
        }

        return null;
    }

    /**
     * GET /wlp-assistant/config — the small, safe-to-expose subset of the
     * assistant's state any authenticated WLP user may read: whether it's
     * ready to answer, and the widget's welcome subtitle + suggestion chips,
     * live-derived from the current system prompt (see
     * SupportAssistantService::deriveWidgetIntro() — nothing here is stored
     * in the database). Never the system prompt or vector_store_id
     * themselves — those stay behind the host-only AssistantConfigController.
     */
    public function config()
    {
        if ($denied = $this->denyIfBorrower()) {
            return $denied;
        }

        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);
        $isReady = $config->isReady();
        $intro = $isReady ? $this->assistantService->deriveWidgetIntro($config) : null;

        return ApiResponse::success([
            'is_ready' => $isReady,
            'welcome_subtitle' => $intro['welcome_subtitle'] ?? null,
            'quick_suggestions' => $intro['quick_suggestions'] ?? [],
            // route_key => same-origin path, from the server-side approved map, so
            // the widget's allowlist follows route changes without a frontend deploy.
            'navigation' => array_map(fn ($e) => $e['path'], $config->navigationMap()),
        ]);
    }

    /**
     * Send a message to the WLP support assistant, creating a session on
     * first use. A supplied session_id must belong to the caller — never
     * trusted to read or post into someone else's thread.
     */
    public function sendMessage(StoreAssistantMessageRequest $request)
    {
        if ($denied = $this->denyIfBorrower()) {
            return $denied;
        }

        try {
            $user = Auth::user();
            $data = $request->validated();

            $conversation = !empty($data['session_id'])
                ? $this->assistantService->findOwnConversationByUuid($data['session_id'], $user, AiAssistantConfig::TYPE_WLP_SUPPORT)
                : null;

            if (! $conversation) {
                $conversation = $this->assistantService->startOrGetConversation($user, AiAssistantConfig::TYPE_WLP_SUPPORT);
            }

            $result = $this->assistantService->sendMessage($conversation, $data['message'], $user);

            return ApiResponse::success([
                'session_id' => $result['conversation']->uuid,
                'message_id' => $result['assistant_message']->uuid,
                'answer' => $result['assistant_message']->content,
                // toTimezone() reads the caller's X-Timezone header, so this
                // is already correct for the viewer — see ChatMessageResource.
                'created_at' => toTimezone($result['assistant_message']->created_at, null, 'M j, Y g:i A'),
                'actions' => $result['actions'],
                // Deterministic destination for Take Me There: {required, intent, route_key}.
                'navigation' => $result['navigation'],
                'sources' => $result['sources'],
                'escalation' => $result['escalation'],
                'policy' => $result['policy'],
            ], __('assistant.message_sent'));
        } catch (\Exception $e) {
            Log::error('WLP assistant sendMessage error', ['error' => $e->getMessage()]);
            return ApiResponse::serverError(__('assistant.message_failed'));
        }
    }

    public function history($sessionId)
    {
        if ($denied = $this->denyIfBorrower()) {
            return $denied;
        }

        try {
            $user = Auth::user();
            $conversation = $this->assistantService->findOwnConversationByUuid((string) $sessionId, $user, AiAssistantConfig::TYPE_WLP_SUPPORT);

            if (! $conversation) {
                return ApiResponse::notFound(__('assistant.conversation_not_found'));
            }

            return ApiResponse::success(
                ChatMessageResource::collection($conversation->messages),
                __('assistant.history_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('assistant.history_failed'));
        }
    }

    /**
     * GET /wlp-assistant/sessions — the authenticated user's own sessions only.
     */
    public function index()
    {
        if ($denied = $this->denyIfBorrower()) {
            return $denied;
        }

        try {
            $user = Auth::user();
            $conversations = $this->assistantService->listOwnConversations($user, AiAssistantConfig::TYPE_WLP_SUPPORT);

            return ApiResponse::success($conversations->map(fn ($c) => [
                'session_id' => $c->uuid,
                'status' => $c->status,
                'last_message_at' => $c->last_message_at,
                'created_at' => $c->created_at,
            ]), __('assistant.history_retrieved'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('assistant.history_failed'));
        }
    }

    /**
     * POST /wlp-assistant/sessions — explicitly starts a new conversation,
     * closing the caller's current open one first. Backs a "New conversation"
     * control in the UI; sendMessage() alone can only ever resume or extend
     * the existing open thread, never deliberately retire it.
     */
    public function store()
    {
        if ($denied = $this->denyIfBorrower()) {
            return $denied;
        }

        try {
            $conversation = $this->assistantService->startNewConversation(Auth::user(), AiAssistantConfig::TYPE_WLP_SUPPORT);

            return ApiResponse::success([
                'session_id' => $conversation->uuid,
            ], __('assistant.session_started'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('assistant.history_failed'));
        }
    }

    /**
     * DELETE /wlp-assistant/sessions — removes every session belonging to the
     * authenticated user for this assistant, per FCH retention policy.
     */
    public function destroy()
    {
        if ($denied = $this->denyIfBorrower()) {
            return $denied;
        }

        try {
            $user = Auth::user();
            $conversations = $this->assistantService->listOwnConversations($user, AiAssistantConfig::TYPE_WLP_SUPPORT);

            foreach ($conversations as $conversation) {
                $conversation->delete();
            }

            return ApiResponse::success(null, __('assistant.sessions_deleted'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('assistant.history_failed'));
        }
    }
}
