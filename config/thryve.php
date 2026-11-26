<?php

return [
    // Timestamps are stored in UTC; business days (attendance, usage resets) use this zone.
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Asia/Manila'),

    // Disk for user files. Never served directly; access only through authorized API routes.
    'private_disk' => 'private',

    // A file that was uploaded but never sent with a message is removed after this long. Until then it counts toward
    // the per-message file limit, so a failed send or a reloaded page cannot lock someone out of attaching files for good.
    'pending_upload_minutes' => (int) env('THRYVE_PENDING_UPLOAD_MINUTES', 60),

    // Installer: the marker file proves installation finished; env_path is where the wizard writes settings.
    // Both can be overridden (tests, disposable demo runs) without touching the real files.
    'install_marker' => env('THRYVE_INSTALL_MARKER', storage_path('app/installed.json')),
    'env_path' => env('THRYVE_ENV_PATH', base_path('.env')),

    // First-login acknowledgment. The text is a PLACEHOLDER until the privacy/acceptable-use policy is approved (decision D11).
    // Bump `version` whenever the text changes so everyone must acknowledge it again.
    'policy' => [
        'version' => '1',
        'title' => 'Acceptable use of AI tools',
        'body' => [
            'PLACEHOLDER: replace this text once the company policy is approved.',
            'AI conversations, uploaded files and usage records are stored and can be reviewed by authorized administrators.',
            'Do not enter passwords, payment card numbers, government ID numbers or other highly sensitive personal data.',
            'You are responsible for checking AI answers before you rely on them.',
        ],
    ],

    // Settings Super Admin can change. `default` applies until a value is saved; `rules` validate every write;
    // `secret` values are never returned or written to the audit log. Later phases add keys here (SMTP, token budgets, ...).
    'settings' => [
        'company_name' => ['default' => 'Thryve', 'rules' => ['string', 'min:1', 'max:100'], 'label' => 'Company name', 'group' => 'general'],
        'default_theme' => ['default' => 'system', 'rules' => ['string', 'in:system,light,dark'], 'label' => 'Default theme', 'group' => 'general'],
        'idle_minutes' => ['default' => 30, 'type' => 'int', 'rules' => ['integer', 'between:1,480'], 'label' => 'Attendance idle threshold (minutes)', 'group' => 'work'],
        'analysis_idle_minutes' => ['default' => 15, 'type' => 'int', 'rules' => ['integer', 'between:1,240'], 'label' => 'Conversation analysis delay (minutes)', 'group' => 'work'],
        // Conversation analysis (P07): off until a Super Admin switches it on, and it needs a report agent.
        'analysis_enabled' => ['default' => false, 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Analyze conversations in the background', 'group' => 'work'],
        'analysis_batch_size' => ['default' => 5, 'type' => 'int', 'rules' => ['integer', 'between:1,50'], 'label' => 'Conversations analyzed per run', 'group' => 'work'],
        'file_max_count' => ['default' => 6, 'type' => 'int', 'rules' => ['integer', 'between:1,20'], 'label' => 'Files per message', 'group' => 'files'],
        'file_max_mb' => ['default' => 15, 'type' => 'int', 'rules' => ['integer', 'between:1,100'], 'label' => 'Maximum file size (MB)', 'group' => 'files'],
        'sensitive_data_mode' => ['default' => 'warn', 'rules' => ['string', 'in:warn,block'], 'label' => 'Sensitive data handling', 'group' => 'security'],
        // Tokens (P04). Placeholders until decisions D05/D09: a person's limit comes from their account, else this default.
        'default_daily_token_limit' => ['default' => 50000, 'type' => 'int', 'rules' => ['integer', 'between:0,100000000'], 'label' => 'Default daily tokens per person', 'group' => 'tokens'],
        'system_daily_token_budget' => ['default' => null, 'type' => 'int', 'rules' => ['nullable', 'integer', 'between:0,1000000000'], 'label' => 'Daily tokens for background analysis (empty = no limit)', 'group' => 'tokens'],
        'hermes_model_label' => ['default' => 'thryve-bot', 'rules' => ['string', 'min:1', 'max:100'], 'label' => 'Model name recorded in usage', 'group' => 'tokens'],
        // Email (SMTP). The password is encrypted at rest and never shown again.
        'smtp_enabled' => ['default' => false, 'type' => 'bool', 'rules' => ['boolean'], 'label' => 'Send email', 'group' => 'email'],
        'smtp_host' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'], 'label' => 'SMTP server', 'group' => 'email'],
        'smtp_port' => ['default' => 587, 'type' => 'int', 'rules' => ['integer', 'between:1,65535'], 'label' => 'SMTP port', 'group' => 'email'],
        'smtp_encryption' => ['default' => 'tls', 'rules' => ['string', 'in:tls,ssl,none'], 'label' => 'Encryption', 'group' => 'email'],
        'smtp_username' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255'], 'label' => 'SMTP username', 'group' => 'email'],
        'smtp_password' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255'], 'label' => 'SMTP password', 'group' => 'email', 'secret' => true],
        'smtp_from_address' => ['default' => null, 'rules' => ['nullable', 'email', 'max:255'], 'label' => 'From address', 'group' => 'email'],
        'smtp_from_name' => ['default' => null, 'rules' => ['nullable', 'string', 'max:100'], 'label' => 'From name', 'group' => 'email'],
    ],

    // In-app notifications. `audience`: who receives it. `email`: also send by email when SMTP is enabled.
    // Who receives what is decision D07; these are defaults, not approved rules. Attendance corrections never notify.
    'notification_types' => [
        'token.threshold' => ['audience' => 'subject', 'email' => true],
        'provider.threshold' => ['audience' => 'admins', 'email' => true],
        'provider.failure' => ['audience' => 'admins', 'email' => true],
        'provider.reauth' => ['audience' => 'admins', 'email' => true],
        'system.update' => ['audience' => 'super_admins', 'email' => true],
        'system.health' => ['audience' => 'super_admins', 'email' => false],
        'system.test' => ['audience' => 'subject', 'email' => false],
        'account.invitation_failed' => ['audience' => 'admins', 'email' => false],
    ],

    // Attendance (decision D06 is still open: these are documented defaults, not approved rules).
    // The browser sends a heartbeat every `heartbeat_seconds`. A session with no heartbeat for `timeout_seconds`
    // is treated as closed at its last heartbeat (browser closed, laptop asleep, network gone). Background tabs are
    // throttled by browsers to roughly one timer per minute, so the timeout is several heartbeats long.
    'attendance' => [
        'heartbeat_seconds' => 30,
        'timeout_seconds' => 150,
    ],

    // Backups (P10). `path` holds one folder per backup. `offsite_disk` names a Laravel filesystem disk (sftp, s3, ...)
    // that receives a copy of every scheduled backup; the destination is decision D10, so it is unset by default.
    'backups' => [
        'path' => env('THRYVE_BACKUP_PATH', storage_path('app/private/backups')),
        'offsite_disk' => env('THRYVE_BACKUP_OFFSITE_DISK'),
        'retention_days' => 14, // scheduled (nightly) backups, here and off-server
        'update_retention_days' => 30, // backups taken before an update; the one an unfinished update needs is never removed
        'tables' => null, // null = every table; tests restrict this to a scratch table
        'schedule_time' => '02:00', // Philippine time
    ],

    // Updates (P10). Only packages signed with the owner's private key are accepted: `public_key` is the matching
    // public key (PEM file). Without it, updates are refused. `base_path` and `dir` can be redirected for rehearsals.
    'update' => [
        'public_key' => env('THRYVE_UPDATE_PUBLIC_KEY', storage_path('app/update-public.pem')),
        'base_path' => env('THRYVE_UPDATE_BASE', base_path()),
        'dir' => env('THRYVE_UPDATE_DIR', storage_path('app/updates')),
        // Top-level paths a release package may contain. Anything else (including .env and storage/) is refused.
        'allowed_paths' => ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'public', 'vendor', 'composer.json', 'composer.lock', 'artisan', 'VERSION'],
        // Never overwritten by an update, even inside an allowed folder.
        'protected_paths' => ['.env', 'storage', 'public/storage', 'bootstrap/cache', 'public/hot'],
    ],

    // Placeholder until the password policy is approved (see plan D07/D11).
    'min_password_length' => 12,
    'min_php' => '8.3.0',
];
