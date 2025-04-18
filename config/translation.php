<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Claude API Configuration
    |--------------------------------------------------------------------------
    |
    | This file is for configuring the Claude API integration for document translation.
    |
    */

    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'api_url' => 'https://api.anthropic.com/v1/messages',
        'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20240620'),
        'max_tokens' => env('CLAUDE_MAX_TOKENS', 8192),
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation Options
    |--------------------------------------------------------------------------
    |
    | Configure translation behavior
    |
    */

    'source_dir' => env('TRANSLATION_SOURCE_DIR', 'docs'),
    'target_dir' => env('TRANSLATION_TARGET_DIR', 'jp'),
    'supported_extensions' => [
        'md', 'txt', 'html'
    ],
];
