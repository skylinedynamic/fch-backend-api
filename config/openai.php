<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fallback Models
    |--------------------------------------------------------------------------
    |
    | Offered in the admin Settings dropdown when the live model list cannot be
    | retrieved from OpenAI — typically a restricted project key that lacks the
    | "api.model.read" scope, which returns a 403. The field remains free-text,
    | so a model outside this list can still be entered.
    |
    */

    'fallback_models' => [
        'gpt-5.6-luna',
        'gpt-5.6-sol',
        'gpt-5.6-terra',
        'gpt-5',
        'gpt-5-mini',
        'gpt-5-nano',
        'gpt-5-pro',
        'o3',
        'o4-mini',
        'gpt-4.1',
        'gpt-4.1-mini',
        'gpt-4o',
        'gpt-4o-mini',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | Last-resort value used only when no model has been chosen in Settings.
    | The model is configured in the database (app_configurations, module
    | "openai") via Settings > OpenAI Configuration, not through .env.
    |
    */

    'default_model' => 'gpt-5.6-luna',

    /*
    |--------------------------------------------------------------------------
    | Lender Matrix Version Label
    |--------------------------------------------------------------------------
    |
    | Recorded against each internal AUE result so an evaluation can be traced
    | back to the lender data it was grounded in. "runtime" means the matrix was
    | read live from the database at call time.
    |
    */

    'lender_matrix_version' => 'runtime',

    /*
    |--------------------------------------------------------------------------
    | Reasoning-capable Model Prefixes
    |--------------------------------------------------------------------------
    |
    | Only these model families accept "reasoning.effort" and a non-default
    | "text.verbosity" on the Responses API. Sending either to any other model
    | is a hard 400, so the client omits them unless the configured model
    | matches one of these prefixes.
    |
    */

    'reasoning_model_prefixes' => ['o1', 'o3', 'o4', 'gpt-5'],

    /*
    |--------------------------------------------------------------------------
    | Reasoning Effort Options
    |--------------------------------------------------------------------------
    |
    | The selectable effort levels. Single source of truth: the Settings screen
    | renders these and the save request validates against the same values, so
    | the two can never drift apart.
    |
    */

    'reasoning_efforts' => [
        ['value' => 'low', 'label' => 'Low — fastest and cheapest'],
        ['value' => 'medium', 'label' => 'Medium — balanced (recommended)'],
        ['value' => 'high', 'label' => 'High — most thorough, slowest and most costly'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Discovery
    |--------------------------------------------------------------------------
    |
    | Patterns used to narrow the live /v1/models response down to models that
    | can actually serve a Responses API text call.
    |
    */

    'model_include_pattern' => '/^(gpt-|o1|o3|o4)/i',
    'model_exclude_pattern' => '/(audio|realtime|transcribe|tts|image|search|embedding|moderation)/i',

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | The evaluation runs inline on submit, so a transient OpenAI failure has to
    | be recovered here — there is no queue behind it to try again later. Each
    | call is retried up to max_attempts times with a linearly growing delay
    | (retry_delay_ms x attempt number).
    |
    | Only transient failures are repeated. retryable_statuses lists the HTTP
    | codes worth another attempt: rate limiting and upstream/gateway faults. A
    | 400/401/403 is a payload or credential problem that will fail identically
    | every time, so it is never retried — that would only make the borrower wait
    | longer for the same error.
    |
    | Budget the worst case against the web server timeout: 3 attempts on the
    | slowest call can add roughly a minute to a submit request.
    |
    */

    'max_attempts' => 3,
    'retry_delay_ms' => 2000,
    'retryable_statuses' => [408, 409, 425, 429, 500, 502, 503, 504],

];
