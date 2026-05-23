<?php

declare(strict_types=1);

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = '/' . trim($requestUri, '/');

$apiRoutes = [
    '/api/login'      => __DIR__ . '/api/login.php',
    '/api/products'   => __DIR__ . '/api/products.php',
    '/api/categories' => __DIR__ . '/api/categories.php',
    '/api/customers'  => __DIR__ . '/api/customers.php',
    '/api/suppliers'  => __DIR__ . '/api/suppliers.php',
    '/api/users'      => __DIR__ . '/api/users.php',
    '/api/stores'     => __DIR__ . '/api/stores.php',
    '/api/dashboard'  => __DIR__ . '/api/dashboard.php',
    '/api/sales'      => __DIR__ . '/api/sales.php',
    '/api/roles'      => __DIR__ . '/api/roles.php',
    '/api/stock'      => __DIR__ . '/api/stock.php',
    '/api/orders'     => __DIR__ . '/api/orders.php',
    '/api/export'     => __DIR__ . '/api/export.php',
    '/api/stripe'     => __DIR__ . '/api/stripe.php',
    '/api/logs'       => __DIR__ . '/api/logs.php',
    '/api/sse'        => __DIR__ . '/api/sse.php',
    '/api/ping'       => __DIR__ . '/api/ping.php',
];

if (isset($apiRoutes[$route])) {
    require $apiRoutes[$route];
    exit;
}

if (str_starts_with($route, '/api/')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'API endpoint not found']);
    exit;
}

// Serve SPA for all other routes
$publicDir = __DIR__ . '/public';
$filePath = $publicDir . $route;

if ($route === '/' || $route === '') {
    readfile($publicDir . '/index.html');
    exit;
}

if (is_file($filePath)) {
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'html' => 'text/html',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'json' => 'application/json',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($filePath);
    exit;
}

readfile($publicDir . '/index.html');
