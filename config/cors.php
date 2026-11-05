<?php

return [
    'paths' => ['api/v1/*'],
    'allowed_methods' => ['GET', 'OPTIONS'],
    // This API exposes published content only; keys are public read-only project identifiers.
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-API-KEY'],
    'exposed_headers' => ['X-Sitemap-Pages'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
