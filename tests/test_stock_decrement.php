<?php
/**
 * Test: stock decrement should be exactly once per sale item.
 *
 * Simulates the EXACT flow from sales.php POST handler:
 *   1. Fetch product (is_service, stock_quantity)
 *   2. INSERT sale
 *   3. INSERT sale_items
 *   4. UPDATE products SET stock_quantity = stock_quantity - :qty
 *   5. INSERT stock_movements
 *
 * Runs inside a transaction, rolls back — no side effects.
 *
 * Usage:
 *   php tests/test_stock_decrement.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
Database::loadConfig();

$db = Database::connect();
$db->beginTransaction();

// 1. Use existing store + user (or create temp ones)
$st = $db->prepare("SELECT id FROM stores LIMIT 1");
$st->execute();
$storeId = $st->fetchColumn();
if (!$storeId) {
    $st = $db->prepare("INSERT INTO stores (id, name) VALUES (gen_random_uuid(), 'Test Store') RETURNING id");
    $st->execute();
    $storeId = $st->fetchColumn();
}

$st = $db->prepare("SELECT id FROM users LIMIT 1");
$st->execute();
$userId = $st->fetchColumn();
if (!$userId) {
    $st = $db->prepare("INSERT INTO users (id, email, password_hash, first_name, store_id) VALUES (gen_random_uuid(), 'test@test.com', :pwh, 'Test', :sid) RETURNING id");
    $st->execute([':pwh' => password_hash('test', PASSWORD_BCRYPT), ':sid' => $storeId]);
    $userId = $st->fetchColumn();
}

$name = 'TEST-' . bin2hex(random_bytes(4));
$initialStock = 100;

$insert = $db->prepare("
    INSERT INTO products (store_id, name, selling_price, purchase_price, stock_quantity, stock_min, is_service, is_active, allow_negative_stock)
    VALUES (:store_id, :name, 10, 5, :stock, 5, FALSE, TRUE, FALSE)
    RETURNING id, stock_quantity
");
$insert->execute([':store_id' => $storeId, ':name' => $name, ':stock' => $initialStock]);
$p = $insert->fetch();
$pid = $p['id'];
$before = (float)$p['stock_quantity'];
echo "Product: $pid  stock before: $before\n";

// 2. Prepare all the same statements as sales.php POST handler
$pStmt = $db->prepare('SELECT purchase_price, is_service, allow_negative_stock, stock_quantity FROM products WHERE id = :id AND is_active = TRUE');

$saleStmt = $db->prepare("
    INSERT INTO sales (store_id, user_id, customer_id, status, subtotal, discount_amount, tax_amount, total_amount, paid_amount, change_amount, payment_method_id, notes)
    VALUES (:store_id, :user_id, NULL, 'completed', :subtotal, 0, 0, :total, :total, 0, 1, 'test')
    RETURNING id
");

$itemStmt = $db->prepare("
    INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, purchase_price, discount_pct, tax_rate)
    VALUES (:sale_id, :product_id, :quantity, :unit_price, :purchase_price, 0, 20)
");

$stockStmt = $db->prepare("
    UPDATE products SET stock_quantity = stock_quantity - :qty, updated_at = NOW()
    WHERE id = :product_id AND (is_service::text NOT IN ('1', 't', 'true') OR is_service IS NULL)
");

$movementStmt = $db->prepare("
    INSERT INTO stock_movements (product_id, store_id, user_id, movement_type, quantity, unit_cost, reference_id, reference_type, notes)
    VALUES (:product_id, :store_id, :user_id, 'sale', :qty, :unit_cost, :reference_id, 'sale', :notes)
");

// 3. Fetch product + simulate the sale
$qty = 7;

$pStmt->execute([':id' => $pid]);
$product = $pStmt->fetch();

$saleStmt->execute([
    ':store_id' => $storeId,
    ':user_id'  => $userId,
    ':subtotal' => $qty * 10,
    ':total'    => $qty * 10,
]);
$saleId = $saleStmt->fetchColumn();

// Insert sale_item
$itemStmt->execute([
    ':sale_id'        => $saleId,
    ':product_id'     => $pid,
    ':quantity'       => $qty,
    ':unit_price'     => 10,
    ':purchase_price' => 5,
]);

// === THIS IS THE CRITICAL STOCK DECREMENT  ===
$stockStmt->execute([
    ':qty'        => $qty,
    ':product_id' => $pid,
]);
$affected = $stockStmt->rowCount();

// Insert stock movement
$movementStmt->execute([
    ':product_id'   => $pid,
    ':store_id'     => $storeId,
    ':user_id'      => $userId,
    ':qty'          => -$qty,
    ':unit_cost'    => 5,
    ':reference_id' => $saleId,
    ':notes'        => 'Test sale',
]);

// 4. Verify stock after ONE decrement
$check = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id');
$check->execute([':id' => $pid]);
$after = (float)$check->fetchColumn();
$expected = $before - $qty;

echo "--- Results ---\n";
echo "Sold qty:         $qty\n";
echo "Affected rows:    $affected\n";
echo "Stock before:     $before\n";
echo "Stock after:      $after\n";
echo "Expected after:   $expected\n";
echo "Difference:       " . ($before - $after) . "\n";
echo "Result:           " . (($after === $expected) ? "✓ CORRECT (once)" : "✗ BUG! x" . (($before - $after) / $qty) . "\n") . "\n";

// 5. Run decrement AGAIN to simulate double-click / duplicate request
$stockStmt->execute([':qty' => $qty, ':product_id' => $pid]);
$check->execute([':id' => $pid]);
$after2 = (float)$check->fetchColumn();
$expected2 = $expected - $qty;
echo "\n--- Double-submit simulation ---\n";
echo "After 2nd decrement: $after2\n";
echo "Expected after 2nd:  $expected2\n";
echo "Double-submit would cause: " . (($expected - $after) === $qty ? "✓ single decrement per request" : "✗") . "\n";
echo "If stock < expected after 2nd call → there is a SECOND decrement path.\n";

$db->rollBack();
echo "\nRolled back — no data changed.\n";
