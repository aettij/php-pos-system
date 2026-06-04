<?php
/**
 * Test: stock decrement should be exactly once per sale item
 *
 * Usage:
 *   php tests/test_stock_decrement.php
 *   (runs inside a transaction, rolls back — no side effects)
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
Database::loadConfig();

$db = Database::connect();
$db->beginTransaction();

// 1. Create a test product with 100 stock
$name = 'TEST-' . bin2hex(random_bytes(4));
$storeId = '00000000-0000-0000-0000-000000000001';
$insert = $db->prepare("
    INSERT INTO products (store_id, name, selling_price, purchase_price, stock_quantity, stock_min, is_service, is_active, allow_negative_stock)
    VALUES (:store_id, :name, 10, 5, 100, 5, FALSE, TRUE, FALSE)
    RETURNING id, stock_quantity
");
$insert->execute([':store_id' => $storeId, ':name' => $name]);
$p = $insert->fetch();
$pid = $p['id'];
$before = (float)$p['stock_quantity'];
echo "Product $pid  stock before: $before\n";

// 2. Run EXACT same decrement query as sales.php POST handler
$qty = 7;
$decrement = $db->prepare("
    UPDATE products SET stock_quantity = stock_quantity - :qty, updated_at = NOW()
    WHERE id = :product_id AND (is_service::text NOT IN ('1', 't', 'true') OR is_service IS NULL)
");
$decrement->execute([':qty' => $qty, ':product_id' => $pid]);
$affected = $decrement->rowCount();

// 3. Verify
$check = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id');
$check->execute([':id' => $pid]);
$after = (float)$check->fetchColumn();
$expected = $before - $qty;

echo "Decrement qty:     $qty\n";
echo "Stock after:       $after\n";
echo "Expected:          $expected\n";
echo "Affected rows:     $affected\n";
echo "Δ:                 " . ($before - $after) . "\n";
echo "Result:            " . (($after === $expected) ? "✓ CORRECT (once)" : "✗ BUG — decreased by " . ($before - $after) . " instead of $qty") . "\n\n";

if ($after !== $expected) {
    echo "If this shows a bug, check for DB triggers:\n";
    echo "  \\dt+ products  —  \\dy  —  SELECT * FROM information_schema.triggers;\n";
}

$db->rollBack();
echo "Rolled back — no data changed.\n";
