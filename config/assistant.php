<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assistant Settings
    |--------------------------------------------------------------------------
    |
    | Per-assistant-type settings — a plain config file, not a database row,
    | so nothing here requires an app_configurations write or an .env value to
    | go live: it ships and changes with the code, the same way the system
    | prompt (app/Services/Assistant/*.txt) and knowledge files
    | (app/Services/Assistant/Knowledge/{type}/) already do.
    |
    | is_enabled defaults to false here on purpose: this file is git-tracked,
    | so whatever value is committed goes to every environment that deploys
    | this code. Flip it to true only once the real approved system prompt
    | and knowledge content have replaced the TEST_PLACEHOLDER_* files — never
    | commit it as true with placeholder content still in place.
    |
    | model/reasoning_effort are left null on purpose too: null means "use
    | the shared OpenAiResponsesService::defaultModel()/defaultReasoningEffort()"
    | (Settings > OpenAI Configuration), the same default Pre-Qualification
    | uses, rather than a separate value to keep in sync.
    |
    */

    'wlp_support' => [
        'is_enabled' => false,
        'model' => null,
        'reasoning_effort' => null,
        'max_output_tokens' => null,
        'vector_store_id' => null,
        'knowledge_manifest_version' => null,
    ],

    'borrower_support' => [
        'is_enabled' => false,
        'model' => null,
        'reasoning_effort' => null,
        'max_output_tokens' => null,
        'vector_store_id' => null,
        'knowledge_manifest_version' => null,
    ],

];
