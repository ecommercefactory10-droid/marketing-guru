<?php

return [
    'app_name' => $_ENV['APP_NAME'] ?? 'SaaS API',
    'app_debug' => (bool)($_ENV['APP_DEBUG'] ?? true),
    'db' => [
        'connection' => $_ENV['DB_CONNECTION'] ?? 'sqlite',
        'database' => $_ENV['DB_DATABASE'] ?? __DIR__ . '/../database/database.sqlite',
    ],
    'cors_origin' => $_ENV['CORS_ORIGIN'] ?? '*',
];