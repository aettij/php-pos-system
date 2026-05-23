<?php

declare(strict_types=1);

function handleCORS(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    $allowedOrigins = getenv('CORS_ORIGIN') ?: '*';

    if ($allowedOrigins === '*') {
        header('Access-Control-Allow-Origin: *');
    } elseif (in_array($origin, explode(',', $allowedOrigins), true)) {
        header("Access-Control-Allow-Origin: $origin");
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
