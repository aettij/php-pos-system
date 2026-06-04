<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
Database::loadConfig();

$db = Database::connect();
$db->beginTransaction();

// Get/ create store + user
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

// Create test product
$name = 'TEST-' . bin2hex(random_bytes(4));
$initialStock = 100;
$qty = 7;

$insert = $db->prepare("
    INSERT INTO products (store_id, name, selling_price, purchase_price, stock_quantity, stock_min, is_service, is_active, allow_negative_stock)
    VALUES (:store_id, :name, 10, 5, :stock, 5, FALSE, TRUE, FALSE)
    RETURNING id, stock_quantity
");
$insert->execute([':store_id' => $storeId, ':name' => $name, ':stock' => $initialStock]);
$p = $insert->fetch();
$pid = $p['id'];
echo "Product: $pid\n";

$check = $db->prepare('SELECT stock_quantity FROM products WHERE id = :id');

// === STEP BY STEP ===
$check->execute([':id' => $pid]);
echo "Initial:                " . $check->fetchColumn() . "\n";

// 1. After sale INSERT
$saleStmt = $db->prepare("INSERT INTO sales (store_id, user_id, status, subtotal, total_amount, paid_amount) VALUES (:store_id, :user_id, 'completed', :sub, :sub, :sub) RETURNING id");
$saleStmt->execute([':store_id' => $storeId, ':user_id' => $userId, ':sub' => $qty * 10]);
$saleId = $saleStmt->fetchColumn();
$check->execute([':id' => $pid]);
echo "After sale INSERT:     " . $check->fetchColumn() . "\n";

// 2. After sale_item INSERT
$itemStmt = $db->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, purchase_price) VALUES (:sale_id, :product_id, :qty, 10, 5)");
$itemStmt->execute([':sale_id' => $saleId, ':product_id' => $pid, ':qty' => $qty]);
$check->execute([':id' => $pid]);
echo "After sale_item INSERT: " . $check->fetchColumn() . "\n";

// 3. After stock decrement UPDATE
$stockStmt = $db->prepare("UPDATE products SET stock_quantity = stock_quantity - :qty, updated_at = NOW() WHERE id = :product_id AND (is_service::text NOT IN ('1', 't', 'true') OR is_service IS NULL)");
$stockStmt->execute([':qty' => $qty, ':product_id' => $pid]);
$affected = $stockStmt->rowCount();
$check->execute([':id' => $pid]);
echo "After stock UPDATE:    " . $check->fetchColumn() . "  (affected: $affected)\n";

// 4. After stock_movement INSERT
$movementStmt = $db->prepare("INSERT INTO stock_movements (product_id, store_id, user_id, movement_type, quantity, reference_id, reference_type) VALUES (:product_id, :store_id, :user_id, 'sale', :qty, :ref, 'sale')");
$movementStmt->execute([':product_id' => $pid, ':store_id' => $storeId, ':user_id' => $userId, ':qty' => -$qty, ':ref' => $saleId]);
$check->execute([':id' => $pid]);
echo "After movement INSERT: " . $check->fetchColumn() . "\n";

// Summary
$check->execute([':id' => $pid]);
$final = (float)$check->fetchColumn();
$expected = $initialStock - $qty;
echo "\nExpected final:  $expected\n";
echo "Actual final:    $final\n";
echo "Result:          " . (($final === $expected) ? "✓ PASS (single decrement)" : "✗ FAIL (decreased by " . ($initialStock - $final) . " instead of $qty)") . "\n";

$db->rollBack();
echo "Rolled back.\n";
