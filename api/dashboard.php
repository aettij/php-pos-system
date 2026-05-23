<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/cache.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonError('Method not allowed', 405);
}

$db = Database::connect();
$storeId = $user['store_id'];

$storeParam = ':store_id';
$storeClause = '(s.store_id = :store_id OR s.store_id IS NULL)';

$todayStart = date('Y-m-d 00:00:00');
$monthStart = date('Y-m-01 00:00:00');

$cacheKey = 'dashboard_' . ($storeId ?? 'all');
$cached = Cache::get($cacheKey, 15);

if ($cached !== null) {
    jsonSuccess(json_decode($cached, true));
}

// Today's revenue
$stmt = $db->prepare("
        SELECT
            COUNT(s.id)::INT AS total_transactions,
            COALESCE(SUM(s.total_amount), 0) AS total_revenue,
            COALESCE(AVG(s.total_amount), 0) AS avg_basket,
            COALESCE(SUM(s.discount_amount), 0) AS total_discounts
        FROM sales s
        WHERE s.status = 'completed'
          AND s.sale_date >= :today
          AND $storeClause
    ");
    $stmt->execute([':today' => $todayStart, ':store_id' => $storeId]);
    $todayStats = $stmt->fetch();

    // Monthly revenue
    $stmt = $db->prepare("
        SELECT
            COUNT(s.id)::INT AS total_transactions,
            COALESCE(SUM(s.total_amount), 0) AS total_revenue,
            COALESCE(SUM(s.total_amount - s.tax_amount), 0) AS net_revenue
        FROM sales s
        WHERE s.status = 'completed'
          AND s.sale_date >= :month
          AND $storeClause
    ");
    $stmt->execute([':month' => $monthStart, ':store_id' => $storeId]);
    $monthStats = $stmt->fetch();

// Low stock products count
$stmt = $db->prepare("
    SELECT COUNT(*)::INT FROM products
    WHERE is_active = TRUE AND is_service = FALSE
      AND stock_quantity <= stock_min
      AND (store_id = :store_id OR store_id IS NULL)
");
$stmt->execute([':store_id' => $storeId]);
$lowStockCount = (int)$stmt->fetchColumn();

// Out of stock count
$stmt = $db->prepare("
    SELECT COUNT(*)::INT FROM products
    WHERE is_active = TRUE AND is_service = FALSE
      AND stock_quantity <= 0
      AND (store_id = :store_id OR store_id IS NULL)
");
$stmt->execute([':store_id' => $storeId]);
$outOfStockCount = (int)$stmt->fetchColumn();

// Active products count
$stmt = $db->prepare("
    SELECT COUNT(*)::INT FROM products
    WHERE is_active = TRUE
      AND (store_id = :store_id OR store_id IS NULL)
");
$stmt->execute([':store_id' => $storeId]);
$totalProducts = (int)$stmt->fetchColumn();

// Active customers count
$stmt = $db->prepare("
    SELECT COUNT(*)::INT FROM customers
    WHERE is_active = TRUE
      AND (store_id = :store_id OR store_id IS NULL)
");
$stmt->execute([':store_id' => $storeId]);
$totalCustomers = (int)$stmt->fetchColumn();

// Recent sales (last 10)
$stmt = $db->prepare("
    SELECT s.id, s.sale_number, s.total_amount, s.sale_date, s.status,
           u.first_name || ' ' || u.last_name AS cashier,
           c.first_name || ' ' || c.last_name AS customer
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE $storeClause
    ORDER BY s.created_at DESC
    LIMIT 10
");
$stmt->execute([':store_id' => $storeId]);
$recentSales = $stmt->fetchAll();

// Top products this month
$stmt = $db->prepare("
    SELECT p.name, SUM(si.quantity) AS total_qty, COALESCE(SUM(si.subtotal), 0) AS total_revenue
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    JOIN sales s ON s.id = si.sale_id AND s.status = 'completed'
    WHERE s.sale_date >= :month
      AND (s.store_id = :store_id OR s.store_id IS NULL)
    GROUP BY p.name
    ORDER BY total_revenue DESC
    LIMIT 10
");
$stmt->execute([':month' => $monthStart, ':store_id' => $storeId]);
$topProducts = $stmt->fetchAll();

// Revenue by day (last 14 days)
$stmt = $db->prepare("
    SELECT DATE(s.sale_date) AS day, COUNT(s.id)::INT AS transactions,
           COALESCE(SUM(s.total_amount), 0) AS revenue
    FROM sales s
    WHERE s.status = 'completed'
      AND s.sale_date >= NOW() - INTERVAL '14 days'
      AND $storeClause
    GROUP BY DATE(s.sale_date)
    ORDER BY day
");
$stmt->execute([':store_id' => $storeId]);
$revenueByDay = $stmt->fetchAll();

// Stock value
$stmt = $db->prepare("
    SELECT COALESCE(SUM(stock_quantity * purchase_price), 0) AS total_stock_value
    FROM products
    WHERE is_active = TRUE AND is_service = FALSE
      AND (store_id = :store_id OR store_id IS NULL)
");
$stmt->execute([':store_id' => $storeId]);
$stockValue = (float)$stmt->fetchColumn();

$activeAlerts = $lowStockCount + $outOfStockCount;

$data = [
    'today' => [
        'transactions' => (int)$todayStats['total_transactions'],
        'revenue'      => (float)$todayStats['total_revenue'],
        'avg_basket'   => round((float)$todayStats['avg_basket'], 2),
        'discounts'    => (float)$todayStats['total_discounts'],
    ],
    'month' => [
        'transactions' => (int)$monthStats['total_transactions'],
        'revenue'      => (float)$monthStats['total_revenue'],
        'net_revenue'  => (float)$monthStats['net_revenue'],
    ],
    'inventory' => [
        'total_products'  => $totalProducts,
        'low_stock'       => $lowStockCount,
        'out_of_stock'    => $outOfStockCount,
        'active_alerts'   => $activeAlerts,
        'stock_value'     => $stockValue,
    ],
    'customers' => [
        'total' => $totalCustomers,
    ],
    'recent_sales'   => $recentSales,
    'top_products'   => $topProducts,
    'revenue_by_day' => $revenueByDay,
];

Cache::set($cacheKey, json_encode($data, JSON_UNESCAPED_UNICODE));
jsonSuccess($data);
