<?php

return [
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Africa/Tripoli'),
    'week_starts_on' => 0,
    'weekend_days' => [5, 6],
    'localization' => [
        'default' => 'ar',
        'cookie' => [
            'name' => 'project_desk_locale',
            'minutes' => 60 * 24 * 365,
        ],
        'supported' => [
            'ar' => [
                'code' => 'ar',
                'dir' => 'rtl',
                'tag' => 'ar',
                'label' => 'العربية',
            ],
            'en' => [
                'code' => 'en',
                'dir' => 'ltr',
                'tag' => 'en',
                'label' => 'English',
            ],
        ],
    ],
    'data_center' => [
        'csv_max_kilobytes' => (int) env('CSV_MAX_FILE_KB', 10 * 1024),
        'xlsx_max_kilobytes' => (int) env('XLSX_MAX_FILE_KB', 10 * 1024),
        'xlsx_max_uncompressed_megabytes' => (int) env('XLSX_MAX_UNCOMPRESSED_MB', 100),
        'xlsx_max_archive_entries' => (int) env('XLSX_MAX_ARCHIVE_ENTRIES', 2000),
        'csv_max_rows' => (int) env('CSV_MAX_ROWS', 5000),
        'preview_rows' => 20,
        'backup_max_kilobytes' => (int) env('BACKUP_MAX_FILE_KB', 512 * 1024),
        'backup_max_expanded_kilobytes' => (int) env('BACKUP_MAX_EXPANDED_KB', 2 * 1024 * 1024),
        'backup_max_entries' => (int) env('BACKUP_MAX_ENTRIES', 10000),
        'backup_disk' => env('BACKUP_DISK', 'local'),
        'backup_directory' => 'backups/project-desk',
        'backup_file_disks' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BACKUP_FILE_DISKS', 'local')),
        ))),
        'backup_encryption_key' => env('BACKUP_ENCRYPTION_KEY'),
        'backup_previous_encryption_keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BACKUP_PREVIOUS_ENCRYPTION_KEYS', '')),
        ))),
        'restore_confirmation' => 'RESTORE PROJECT DESK',
        'restore_nonce_ttl_seconds' => (int) env('BACKUP_RESTORE_NONCE_TTL', 600),
        'restore_attempts_per_ten_minutes' => (int) env('BACKUP_RESTORE_ATTEMPTS', 3),
    ],
    'uploads' => [
        'max_file_kilobytes' => (int) env('UPLOAD_MAX_FILE_KB', 25 * 1024),
        'allowed_mime_types' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'rate_limit_per_minute' => (int) env('UPLOAD_RATE_LIMIT_PER_MINUTE', 20),
        'project_quota_bytes' => (int) env('UPLOAD_PROJECT_QUOTA_BYTES', 10 * 1024 * 1024 * 1024),
        'user_project_quota_bytes' => (int) env('UPLOAD_USER_PROJECT_QUOTA_BYTES', 2 * 1024 * 1024 * 1024),
        'project_file_limit' => (int) env('UPLOAD_PROJECT_FILE_LIMIT', 10000),
        'orphan_retention_hours' => (int) env('UPLOAD_ORPHAN_RETENTION_HOURS', 72),
        'malware_scanner' => [
            'driver' => env('MALWARE_SCANNER_DRIVER', 'none'),
            'executable' => env('MALWARE_SCANNER_EXECUTABLE', 'clamdscan'),
            'arguments' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MALWARE_SCANNER_ARGUMENTS', '--fdpass,--no-summary')),
            ))),
            'timeout_seconds' => (int) env('MALWARE_SCANNER_TIMEOUT', 30),
            'callback' => env('MALWARE_SCANNER_CALLBACK'),
        ],
    ],
    'operations' => [
        'release_sha' => env('APP_RELEASE_SHA', ''),
        'minimum_free_disk_bytes' => (int) env('MINIMUM_FREE_DISK_BYTES', 5 * 1024 * 1024 * 1024),
        'rclone_remote' => env('RCLONE_REMOTE', ''),
        'production_evidence_path' => env('PRODUCTION_EVIDENCE_PATH', storage_path('app/private/production-evidence.json')),
        'clamav_signature_paths' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CLAMAV_SIGNATURE_PATHS', '/var/lib/clamav/daily.cld,/var/lib/clamav/daily.cvd')),
        ))),
        'clamav_signature_max_age_seconds' => (int) env('CLAMAV_SIGNATURE_MAX_AGE_SECONDS', 48 * 60 * 60),
    ],
];
