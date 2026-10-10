<?php

return [
    'internal_only' => (bool) env('CENTRAL_INTERNAL_ONLY', true),
    'exclusive_enabled' => (bool) env('CENTRAL_EXCLUSIVE_ENABLED', true),
    'owner_policy_file' => env('CENTRAL_OWNER_POLICY_FILE', ''),
    'alfa_backend_enabled' => (bool) env('CENTRAL_ALFA_BACKEND_ENABLED', false),
    'alfa_backend_key_file' => env('CENTRAL_ALFA_BACKEND_KEY_FILE', ''),
    'identity_bridge_enabled' => (bool) env('CENTRAL_IDENTITY_BRIDGE_ENABLED', false),
    'identity_redeem_url' => env('CENTRAL_IDENTITY_REDEEM_URL', ''),
    'identity_ca_file' => env('CENTRAL_IDENTITY_CA_FILE', ''),
    'identity_authority_url' => env('CENTRAL_IDENTITY_AUTHORITY_URL', ''),
    'identity_authority_key_file' => env('CENTRAL_IDENTITY_AUTHORITY_KEY_FILE', ''),
    'agent_user_daily_limit' => 20,
    'telegram_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'telegram_enabled' => (bool) env('TELEGRAM_ENABLED', false),
    'max_text' => 3000,
    'agents_enabled' => (bool) env('CENTRAL_AGENTS_ENABLED', false),
    'agent_mode' => 'mock', // Real inference deliberately unavailable until separately approved.
    'executor_url' => env('CENTRAL_EXECUTOR_URL', 'http://executor:8090'),
    'executor_key_file' => env('CENTRAL_EXECUTOR_KEY_FILE', '/run/secrets/executor-key'),
    'agent_daily_limit' => 20,
    'agent_timeout' => 120,
    'agent_context_chars' => 24000,
];
