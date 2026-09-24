<?php

$localOrigins = 'http://localhost:5173,http://127.0.0.1:5173';
$defaultOrigins = env('APP_ENV', 'production') === 'local' ? $localOrigins : '';
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', $defaultOrigins)))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With', 'X-XSRF-TOKEN'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => true,
];
