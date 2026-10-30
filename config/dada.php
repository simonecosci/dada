<?php

return [

    // First admin, created by AdminSeeder only if no admin exists yet.
    // Without a password one is generated and printed in the init container log.
    'admin' => [
        'email' => env('DADA_ADMIN_EMAIL') ?: null,
        'name' => env('DADA_ADMIN_NAME') ?: null,
        'password' => env('DADA_ADMIN_PASSWORD') ?: null,
    ],

    'deploy' => [
        // Minimum free space (MB) required on the host before a deploy.
        'min_free_mb' => (int) env('DADA_DEPLOY_MIN_FREE_MB', 1024),
        // Number of pre-deploy backups to keep per deployment host.
        'backup_retention' => (int) env('DADA_DEPLOY_BACKUP_RETENTION', 10),
        // TTL (seconds) of the lock of an active run.
        'lock_ttl' => (int) env('DADA_DEPLOY_LOCK_TTL', 3600),
    ],

    // Browser Reverb client (public values, never the secret). null = page origin
    // (with Docker, nginx forwards /app to Reverb on the same web port).
    'reverb' => [
        'host' => env('REVERB_CLIENT_HOST') ?: null,
        'port' => env('REVERB_CLIENT_PORT') ? (int) env('REVERB_CLIENT_PORT') : null,
        'scheme' => env('REVERB_CLIENT_SCHEME') ?: null,
    ],

    'ssh' => [
        'connect_timeout' => (int) env('DADA_SSH_CONNECT_TIMEOUT', 10),
        'command_timeout' => (int) env('DADA_SSH_COMMAND_TIMEOUT', 1800),
        // Temporary directory for SSH keys and support files.
        'tmp_path' => storage_path('app/private/ssh'),
    ],

];
