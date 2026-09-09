<?php

return [
    'api_key_required' => 'An OpenAI API key is required before this configuration can be saved.',
    'api_key_regex' => 'The API key must start with a lowercase "sk-" and contain no spaces. Check for a stray capital letter or a truncated paste.',
    'api_key_string' => 'The API key must be a valid text value.',
    'api_key_max' => 'The API key is longer than any valid OpenAI key.',

    'aue_model_regex' => 'The model name may only contain letters, numbers, dots, dashes and underscores.',
    'aue_model_string' => 'The model name must be a valid text value.',
    'aue_model_max' => 'The model name is too long.',

    'reasoning_effort_required' => 'Please choose a reasoning effort.',
    'reasoning_effort_in' => 'Reasoning effort must be low, medium or high.',
];
