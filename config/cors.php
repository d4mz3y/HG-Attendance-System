<?php

$configuredOrigins = trim((string) env('CORS_ALLOWED_ORIGINS', ''));
$originList = $configuredOrigins !== '' ? $configuredOrigins : (string) env('APP_URL', 'http://localhost');

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', $originList)
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-CSRF-TOKEN', 'X-Device-Token', 'X-Requested-With', 'X-XSRF-TOKEN'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => false,
];
