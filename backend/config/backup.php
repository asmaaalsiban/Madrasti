<?php

return [
    /*
    | Where backups are kept. Default: storage/app/backups on this server. In production point BACKUP_DISK at a disk on
    | ANOTHER machine (an S3-compatible bucket, a Hetzner Storage Box...): a backup on the same server is lost with it.
    */
    'disk' => env('BACKUP_DISK', 'backups'),

    // A password makes every backup file an encrypted zip (AES-256). Keep it somewhere safe: without it a backup cannot be opened.
    'password' => env('BACKUP_PASSWORD'),

    // Which backups are kept: every one of the last `days` days, and the first one of each of the last `months` months.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    'keep_months' => (int) env('BACKUP_KEEP_MONTHS', 6),

    // Where to email when a backup fails (empty: only the log).
    'alert_email' => env('BACKUP_ALERT_EMAIL'),

    // A school whose newest backup is older than this many hours is reported as stale by `backup:status`.
    'stale_hours' => 36,

    // File disks of each school that are saved with its database.
    'file_disks' => ['local', 'public'],
];
