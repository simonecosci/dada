<?php

return [

    'deploy' => [
        // Spazio libero minimo (MB) richiesto sull'host prima di un deploy.
        'min_free_mb' => (int) env('DADA_DEPLOY_MIN_FREE_MB', 1024),
        // Numero di backup pre-deploy da conservare per deployment host.
        'backup_retention' => (int) env('DADA_DEPLOY_BACKUP_RETENTION', 10),
        // TTL (secondi) del lock di un run attivo.
        'lock_ttl' => (int) env('DADA_DEPLOY_LOCK_TTL', 3600),
    ],

    'ssh' => [
        'connect_timeout' => (int) env('DADA_SSH_CONNECT_TIMEOUT', 10),
        'command_timeout' => (int) env('DADA_SSH_COMMAND_TIMEOUT', 1800),
        // Directory temporanea per chiavi e file di supporto SSH.
        'tmp_path' => storage_path('app/private/ssh'),
    ],

];
