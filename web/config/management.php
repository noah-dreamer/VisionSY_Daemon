<?php
return [
    'api_version' => 1,
    'version' => env('VISIONSY_VERSION', '0.2.0'),
    'name' => env('DAEMON_NAME', 'VisionSY daemon'),
    'legacy_admin_enabled' => env('LEGACY_ADMIN_ENABLED', env('APP_ENV') !== 'production'),
    'allowed_origins' => env('MANAGEMENT_ALLOWED_ORIGINS', env('DASHBOARD_URL', '')),
    'dashboard_url' => env('DASHBOARD_URL', ''),
];
