<?php

return [
    'disk' => env('BACKUP_DISK', 'local'),
    'prefix' => trim(env('BACKUP_PREFIX', 'system-backups'), '/'),
    'format_version' => 1,
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
    'retention_count' => max(1, (int) env('BACKUP_RETENTION_COUNT', 14)),
    'schedule' => env('BACKUP_SCHEDULE', 'daily'),
    'include_disks' => ['local', 'public'],
];
