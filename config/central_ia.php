<?php

return [
    'exclusive_enabled' => (bool) env('CENTRAL_IA_EXCLUSIVE_ENABLED', true),
    'owner_policy_file' => env('CENTRAL_IA_OWNER_POLICY_FILE', ''),
    'browser_tickets_enabled' => (bool) env('CENTRAL_IA_BROWSER_TICKETS_ENABLED', false),
    'ui_enabled' => (bool) env('CENTRAL_IA_UI_ENABLED', false),
    'backend_url' => env('CENTRAL_IA_BACKEND_URL', ''),
    'backend_key_file' => env('CENTRAL_IA_BACKEND_KEY_FILE', ''),
    'backend_ca_file' => env('CENTRAL_IA_BACKEND_CA_FILE', ''),
    'enabled' => (bool) env('CENTRAL_IA_ENABLED', false),
    'key_file' => env('CENTRAL_IA_KEY_FILE', ''),
    'audience' => 'central-agentes',
    'ticket_seconds' => 120,
];
