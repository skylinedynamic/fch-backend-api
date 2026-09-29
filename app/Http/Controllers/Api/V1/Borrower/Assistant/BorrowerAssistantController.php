<?php

namespace App\Http\Controllers\Api\V1\Borrower\Assistant;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Assistant\StoreAssistantMessageRequest;
use App\Http\Resources\Assistant\ChatMessageResource;
use App\Models\Assistant\AiAssistantConfig;
use App\Services\Assistant\SupportAssistantService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Borrower-facing counterpart to WlpAssistantController, same envelope
 * (session_id/message_id/answer/actions/sources/escalation/policy) for
 * consistency across both assistants, even though the Borrower Support
 * Assistant ticket didn't ship its own OpenAPI file.
 */
class BorrowerAssistantController extends Controller
{
    public function __construct(protected SupportAssistantService $assistantService)
    {
    }

    /**
     * GET /borrower/assistant/config — the small, safe-to-expose subset of
     * the assistant's state any authenticated borrower may read: whether
     * it's ready to answer, and the widget's welcome subtitle + suggestion
     * chips, live-derived from the current system prompt (see
     * SupportAssistantService::deriveWidgetIntro() — nothing here is stored
     * in the database). Never the system prompt itself.
     */
    public function config()
    {
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_BORROWER_SUPPORT);
        $isReady = $config->isReady();
        $intro = $isReady ? $this->assistantService->deriveWidgetIntro($config) : null;

        return ApiResponse::success([
            'is_ready' => $isReady,
            'welcome_subtitle' => $intro['welcome_subtitle'] ?? null,
            'quick_suggestions' => $intro['quick_suggestions'] ?? [],
        ]);
    }

    public function sendMessage(StoreAssistantMessageRequest $request)
    {
        try {
            $user = Auth::user();
            $data = $request->validated();

            $conversation = !empty($data['session_id'])
                ? $this->assistantService->findOwnConversationByUuid($data['session_id'], $user, AiAssistantConfig::TYPE_BORROWER_SUPPORT)
                : null;

            if (! $conversation) {
                $conversation = $this->assistantService->startOrGetConversation($user, AiAssistantConfig::TYPE_BORROWER_SUPPORT);
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
                'sources' => $result['sources'],
                'escalation' => $result['escalation'],
                'policy' => $result['policy'],
            ], __('assistant.message_sent'));
        } catch (\Exception $e) {
            Log::error('Borrower assistant sendMessage error', ['error' => $e->getMessage()]);
            return ApiResponse::serverError(__('assistant.message_failed'));
        }
    }

    public function history($sessionId)
    {
        try {
            $user = Auth::user();
            $conversation = $this->assistantService->findOwnConversationByUuid((string) $sessionId, $user, AiAssistantConfig::TYPE_BORROWER_SUPPORT);

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

    public function index()
    {
        try {
            $user = Auth::user();
            $conversations = $this->assistantService->listOwnConversations($user, AiAssistantConfig::TYPE_BORROWER_SUPPORT);

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
     * POST /borrower/assistant/sessions — explicitly starts a new
     * conversation, closing the caller's current open one first.
     */
    public function store()
    {
        try {
            $conversation = $this->assistantService->startNewConversation(Auth::user(), AiAssistantConfig::TYPE_BORROWER_SUPPORT);

            return ApiResponse::success([
                'session_id' => $conversation->uuid,
            ], __('assistant.session_started'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('assistant.history_failed'));
        }
    }

    public function destroy()
    {
        try {
            $user = Auth::user();
            $conversations = $this->assistantService->listOwnConversations($user, AiAssistantConfig::TYPE_BORROWER_SUPPORT);

            foreach ($conversations as $conversation) {
                $conversation->delete();
            }

            return ApiResponse::success(null, __('assistant.sessions_deleted'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('assistant.history_failed'));
        }
    }
}
