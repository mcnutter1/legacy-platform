<?php

$csv = fn (?string $v) => array_values(array_filter(array_map('trim', explode(',', (string) $v))));

return [
    'release' => env('LEGACY_RELEASE', 'dev'),

    'auth' => [
        'driver' => env('AUTH_DRIVER', 'mcnutt'),
        'login_base' => env('AUTH_LOGIN_BASE', 'https://login.mcnutt.cloud'),
        'app_id' => env('AUTH_APP_ID'),
        'app_secret' => env('AUTH_APP_SECRET'),
        'expected_issuer' => env('AUTH_EXPECTED_ISSUER', 'https://login.mcnutt.cloud'),
        'validate_endpoint' => env('AUTH_VALIDATE_ENDPOINT', 'https://login.mcnutt.cloud/api/validate.php'),
        'login_url_template' => env('AUTH_LOGIN_URL_TEMPLATE', '{login_base}/?return_url={return_url}&app_id={app_id}'),
        'logout_url_template' => env('AUTH_LOGOUT_URL_TEMPLATE', '{login_base}/logout_confirm.php?token={token}&return_url={return_url}'),
        'revalidate_seconds' => (int) env('AUTH_REVALIDATE_SECONDS', 300),
        'fresh_seconds' => (int) env('AUTH_FRESH_SECONDS', 60),
        'callback_max_age_seconds' => (int) env('AUTH_CALLBACK_MAX_AGE_SECONDS', 120),
        'validate_on_callback' => (bool) env('AUTH_VALIDATE_ON_CALLBACK', true),
        'allowed_principal_types' => ['user'],
        'http_timeout' => (int) env('AUTH_HTTP_TIMEOUT', 8),
        'absolute_session_hours' => (int) env('AUTH_ABSOLUTE_SESSION_HOURS', 12),
        'email_is_verified' => (bool) env('AUTH_EMAIL_IS_VERIFIED', false),
        // Central principals (uid) allowed to create workspaces. Empty = nobody; "*" = any signed-in user.
        'workspace_creators' => $csv(env('LEGACY_WORKSPACE_CREATORS', '')),
    ],

    'stepup' => [
        'required' => (bool) env('STEPUP_REQUIRED', true),
        'ttl_seconds' => (int) env('STEPUP_TTL_SECONDS', 600),
        'issuer' => env('STEPUP_TOTP_ISSUER', 'McNutt Legacy'),
    ],

    'vault' => [
        'root' => env('LEGACY_PRIVATE_STORAGE', storage_path('app/private-vault')),
        // Base64 32-byte key-encryption key; independent of APP_KEY so key custody can be separated.
        'root_key' => env('VAULT_ROOT_KEY'),
        'root_key_id' => env('VAULT_ROOT_KEY_ID', 'k1'),
        'previous_keys' => env('VAULT_PREVIOUS_KEYS', ''), // "id:base64,id:base64" for rotation
        'max_bytes' => (int) env('VAULT_MAX_BYTES', 25 * 1024 * 1024),
        'max_pages' => (int) env('VAULT_MAX_PAGES', 400),
        'max_pixels' => (int) env('VAULT_MAX_PIXELS', 60_000_000),
        'scanner' => env('VAULT_SCANNER', 'auto'), // auto|clamd|clamscan|none
        'require_scan' => (bool) env('VAULT_REQUIRE_SCAN', false),
        'download_ttl_seconds' => (int) env('VAULT_DOWNLOAD_TTL', 120),
        'job_dir' => env('LEGACY_JOB_DIR', storage_path('app/jobs')),
    ],

    'runner' => [
        'python' => env('RUNNER_PYTHON', base_path('runners/.venv/bin/python')),
        'module' => 'legacy_runners',
        'workdir' => base_path('runners'),
        'timeout_seconds' => ['interactive' => 25, 'bulk' => 60],
        'memory_mb' => (int) env('RUNNER_MEMORY_MB', 768),
        'max_output_bytes' => (int) env('RUNNER_MAX_OUTPUT_BYTES', 8 * 1024 * 1024),
        'threads' => (int) env('RUNNER_THREADS', 1),
        'lease_seconds' => 60,
        'worker_seconds' => ['interactive' => 55, 'bulk' => 110],
        'max_per_workspace' => (int) env('RUNNER_MAX_PER_WORKSPACE', 2),
        'sandbox' => env('RUNNER_SANDBOX', 'none'), // none|bwrap
    ],

    'notifications' => [
        'driver' => env('NOTIFICATIONS_DRIVER', 'log'), // mcnutt|log|disabled
        'base_url' => 'https://notifications.mcnutt.cloud',
        'api_token' => env('NOTIFICATIONS_API_TOKEN'),
        'from' => env('NOTIFICATIONS_FROM', 'McNutt Legacy <legacy@mcnutt.cloud>'),
        'provider' => env('NOTIFICATIONS_PROVIDER'),
        'timeout' => 10,
    ],

    'openai' => [
        'enabled' => (bool) env('OPENAI_EXTRACTION_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_EXTRACTION_MODEL', 'gpt-5.1-mini'),
        'base_url' => 'https://api.openai.com/v1',
        'timeout' => 45,
    ],

    'mcp' => [
        'enabled' => (bool) env('MCP_ENABLED', true),
        'protocol_versions' => ['2025-11-25', '2025-06-18', '2025-03-26'],
        'allowed_origins' => $csv(env('MCP_ALLOWED_ORIGINS', 'https://chatgpt.com,https://chat.openai.com')),
        'access_token_ttl' => 900,
        'refresh_token_ttl' => 60 * 60 * 24 * 30,
        'dynamic_registration' => (bool) env('MCP_DYNAMIC_REGISTRATION', true),
        'allowed_redirect_hosts' => $csv(env('MCP_REDIRECT_HOSTS', 'chatgpt.com,chat.openai.com')),
        'max_results' => 50,
    ],

    'features' => [
        'connector_snowball_api' => (bool) env('FEATURE_SNOWBALL_API', false),
        'connector_monarch_api' => (bool) env('FEATURE_MONARCH_API', false),
        'connector_invoices_api' => (bool) env('FEATURE_INVOICES_API', false),
        'connector_property_api' => (bool) env('FEATURE_PROPERTY_API', false),
        'sms' => false,
        'standalone_entity_returns' => false,
        'chat_attachment_forwarding' => (bool) env('FEATURE_CHAT_ATTACHMENTS', false),
    ],

    'backup_status_file' => env('LEGACY_BACKUP_STATUS_FILE'),
    'trusted_proxies' => env('TRUSTED_PROXIES'),
];
