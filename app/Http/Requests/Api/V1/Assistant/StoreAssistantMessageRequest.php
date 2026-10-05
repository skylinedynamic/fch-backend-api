<?php

namespace App\Http\Requests\Api\V1\Assistant;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreAssistantMessageRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Matches FCH_WLP_Assistant_OpenAPI_v1_1.yaml's request schema.
            'session_id' => ['nullable', 'uuid', 'exists:chat_conversations,uuid'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            // V1 rule: context is optional, non-authoritative UI context only.
            // context.client_id must never trigger a live lookup — see
            // SupportAssistantService, which never reads either field.
            'context' => ['nullable', 'array'],
            'context.current_page' => ['nullable', 'string', 'max:100'],
            'context.client_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
