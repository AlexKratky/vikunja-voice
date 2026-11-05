<?php

return [
    'upload_token' => env('APP_UPLOAD_TOKEN', ''),
    'locale' => env('VOICE_INPUT_LOCALE', 'en'),
    'timezone' => env('VOICE_TIMEZONE', 'Europe/Prague'),
    'retention_days' => (int) env('RETENTION_DAYS', 7),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'vikunja' => [
        'url' => rtrim(env('VIKUNJA_URL', ''), '/'),
        'token' => env('VIKUNJA_API_TOKEN', ''),
        'default_project_id' => (int) env('VIKUNJA_DEFAULT_PROJECT_ID', 0),
        'auto_assign_username' => env('VIKUNJA_AUTO_ASSIGN_USERNAME'),
        'auto_label' => env('VIKUNJA_AUTO_LABEL'),
    ],

    'ntfy' => [
        'url' => rtrim(env('NTFY_URL', 'https://ntfy.sh'), '/'),
        'topic' => env('NTFY_TOPIC', ''),
        'notify_success' => (bool) env('NTFY_NOTIFY_SUCCESS', true),
    ],

    // Voice commands, each on unless switched off. A switched-off command is
    // left out of the prompt, so its phrase is dictated as an ordinary task.
    'commands' => [
        'create_if_missing' => (bool) env('VOICE_COMMAND_CREATE_IF_MISSING', true),
        'comment' => (bool) env('VOICE_COMMAND_COMMENT', true),
        'complete' => (bool) env('VOICE_COMMAND_COMPLETE', true),
    ],

    'kuma' => [
        'push_url' => env('KUMA_PUSH_URL', ''),
    ],
];
