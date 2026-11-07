<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'key' => env('ANTHROPIC_API_KEY'),
    'model' => env('CLAUDE_MODEL'),
    'version' => env('ANTHROPIC_API_VERSION', '2026-10-21'),
    'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
    'monthly_requests' => (int) env('AI_MONTHLY_REQUEST_LIMIT', 50),
    'max_output_tokens' => 4096,
    'max_input_bytes' => 60000,
];
