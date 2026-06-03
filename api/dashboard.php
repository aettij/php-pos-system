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

$todayStart = date('Y-m-d 00:00:00');
$monthStart = date('Y-m-01 00:00:00');

$cacheKey = 'dashboard_' . ($storeId ?? 'all');
$cached = Cache::get($cacheKey, 15);

if ($cached !== null) {
    jsonSuccess(json_decode($cached, true));
}

$storeClause = '(store_id = :store_id OR store_id IS NULL)';

// Combined product stats (total, low stock, out of stock, stock value)
$stmt = $db->prepare("
    SELECT
        COUNT(*) FILTER (WHERE is_active = TRUE)::INT AS total_products,
        COUNT(*) FILTER (WHERE is_active = TRUE AND (is_service::text NOT IN ('1', 't', 'true') OR is_service IS NULL) AND stock_quantity <= stock_min AND stock_quantity > 0)::INT AS low_stock,
        COUNT(*) FILTER (WHERE is_active = TRUE AND (is_service::text NOT IN ('1', 't', 'true') OR is_service IS NULL) AND stock_quantity <= 0)::INT AS out_of_stock,
        COALESCE(SUM(stock_quantity * purchase_price) FILTER (WHERE is_active = TRUE AND (is_service::text NOT IN ('1', 't', 'true') OR is_service IS NULL)), 0) AS stock_value
    FROM products
    WHERE $storeClause
");
$stmt->execute([':store_id' => $storeId]);
$invStats = $stmt->fetch();

// Combined today + month revenue
$stmt = $db->prepare("
    SELECT
        COUNT(*) FILTER (WHERE sale_date >= :today)::INT AS today_transactions,
        COALESCE(SUM(total_amount) FILTER (WHERE sale_date >= :today), 0) AS today_revenue,
        COALESCE(AVG(total_amount) FILTER (WHERE sale_date >= :today), 0) AS today_avg_basket,
        COALESCE(SUM(discount_amount) FILTER (WHERE sale_date >= :today), 0) AS today_discounts,
        COUNT(*) FILTER (WHERE sale_date >= :month)::INT AS month_transactions,
        COALESCE(SUM(total_amount) FILTER (WHERE sale_date >= :month), 0) AS month_revenue,
        COALESCE(SUM(total_amount - tax_amount) FILTER (WHERE sale_date >= :month), 0) AS month_net_revenue
    FROM sales
    WHERE status = 'completed'
      AND sale_date >= :month
      AND $storeClause
");
$stmt->execute([':today' => $todayStart, ':month' => $monthStart, ':store_id' => $storeId]);
$revStats = $stmt->fetch();

// Active customers count
$stmt = $db->prepare("
    SELECT COUNT(*)::INT FROM customers
    WHERE is_active = TRUE
      AND $storeClause
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
    WHERE (s.store_id = :store_id OR s.store_id IS NULL)
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
      AND (s.store_id = :store_id OR s.store_id IS NULL)
    GROUP BY DATE(s.sale_date)
    ORDER BY day
");
$stmt->execute([':store_id' => $storeId]);
$revenueByDay = $stmt->fetchAll();

$lowStockCount = (int)$invStats['low_stock'];
$outOfStockCount = (int)$invStats['out_of_stock'];

$data = [
    'today' => [
        'transactions' => (int)$revStats['today_transactions'],
        'revenue'      => (float)$revStats['today_revenue'],
        'avg_basket'   => round((float)$revStats['today_avg_basket'], 2),
        'discounts'    => (float)$revStats['today_discounts'],
    ],
    'month' => [
        'transactions' => (int)$revStats['month_transactions'],
        'revenue'      => (float)$revStats['month_revenue'],
        'net_revenue'  => (float)$revStats['month_net_revenue'],
    ],
    'inventory' => [
        'total_products'  => (int)$invStats['total_products'],
        'low_stock'       => $lowStockCount,
        'out_of_stock'    => $outOfStockCount,
        'active_alerts'   => $lowStockCount + $outOfStockCount,
        'stock_value'     => (float)$invStats['stock_value'],
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
