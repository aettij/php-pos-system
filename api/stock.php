<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

$db = Database::connect();

switch ($method) {
    case 'GET':
        // Get stock movements or use v_stock_status
        $view = $_GET['view'] ?? 'status';

        if ($view === 'movements') {
            [$page, $perPage, $offset] = getPaginationParams();
            $productId = $_GET['product_id'] ?? null;
            $where = 'WHERE (sm.store_id = :store_id OR sm.store_id IS NULL)';
            $params = [':store_id' => $user['store_id']];

            if ($productId && validateUuid($productId)) {
                $where .= ' AND sm.product_id = :product_id';
                $params[':product_id'] = $productId;
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM stock_movements sm $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $db->prepare("
                SELECT sm.*, p.name AS product_name, p.barcode,
                       u.first_name || ' ' || u.last_name AS user_name
                FROM stock_movements sm
                JOIN products p ON p.id = sm.product_id
                LEFT JOIN users u ON u.id = sm.user_id
                $where
                ORDER BY sm.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();
            $movements = $stmt->fetchAll();

            jsonSuccess([
                'movements'  => $movements,
                'pagination' => buildPaginationMeta($total, $page, $perPage),
            ]);
        } else {
            // Stock status view (no pagination — show all with a safety cap)
            $search = getSearchTerm();
            $where = 'WHERE (p.store_id = :store_id OR p.store_id IS NULL)';
            $params = [':store_id' => $user['store_id']];

            if ($search) {
                $where .= ' AND (p.name ILIKE :search OR p.barcode ILIKE :search)';
                $params[':search'] = "%$search%";
            }

            $filter = $_GET['filter'] ?? 'all';
            if ($filter === 'low_stock') {
                $where .= ' AND p.stock_quantity <= p.stock_min AND p.stock_quantity > 0';
            } elseif ($filter === 'out_of_stock') {
                $where .= ' AND p.stock_quantity <= 0';
            } elseif ($filter === 'overstock') {
                $where .= ' AND p.stock_max IS NOT NULL AND p.stock_quantity >= p.stock_max';
            }

            $stmt = $db->prepare("
                SELECT p.id, p.name, p.barcode, p.sku, p.stock_quantity, p.stock_min,
                       p.stock_max, p.selling_price, p.purchase_price, p.is_service,
                       c.name AS category_name,
                       CASE
                           WHEN p.stock_quantity <= 0 THEN 'out_of_stock'
                           WHEN p.stock_quantity <= p.stock_min THEN 'low_stock'
                           WHEN p.stock_max IS NOT NULL AND p.stock_quantity >= p.stock_max THEN 'overstock'
                           ELSE 'ok'
                       END AS stock_status,
                       (p.stock_quantity * p.purchase_price) AS stock_value
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                $where
                ORDER BY
                    CASE
                        WHEN p.stock_quantity <= 0 THEN 0
                        WHEN p.stock_quantity <= p.stock_min THEN 1
                        ELSE 2
                    END,
                    p.name
                LIMIT 2000
            ");
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            jsonSuccess(['products' => $products]);
        }
        break;

    case 'POST':
        Auth::requirePermission('stock.adjust');

        $input = getJsonInput();
        $errors = validateRequired($input, ['product_id', 'movement_type', 'quantity']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        if (!validateUuid($input['product_id'])) {
            jsonError('Invalid product ID', 400);
        }

        $quantity = (float)$input['quantity'];
        if ($quantity === 0.0) {
            jsonError('Quantity must not be zero', 422);
        }

        $validTypes = ['sale', 'return', 'purchase', 'adjustment', 'inventory', 'transfer_in', 'transfer_out', 'loss'];
        if (!in_array($input['movement_type'], $validTypes, true)) {
            jsonError('Invalid movement type', 422);
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('
                INSERT INTO stock_movements (product_id, store_id, user_id, movement_type, quantity, unit_cost, reference_id, reference_type, notes)
                VALUES (:product_id, :store_id, :user_id, :movement_type, :quantity, :unit_cost, :reference_id, :reference_type, :notes)
                RETURNING *
            ');
            $stmt->execute([
                ':product_id'     => $input['product_id'],
                ':store_id'       => $user['store_id'],
                ':user_id'        => $user['id'],
                ':movement_type'  => $input['movement_type'],
                ':quantity'       => $quantity,
                ':unit_cost'      => !empty($input['unit_cost']) ? (float)$input['unit_cost'] : null,
                ':reference_id'   => $input['reference_id'] ?? null,
                ':reference_type' => $input['reference_type'] ?? null,
                ':notes'          => sanitizeString($input['notes'] ?? ''),
            ]);

            $movement = $stmt->fetch();

            $updateStmt = $db->prepare('
                UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
                WHERE id = :product_id
                RETURNING stock_quantity
            ');
            $updateStmt->execute([
                ':qty' => $quantity,
                ':product_id' => $input['product_id'],
            ]);
            $newStock = (float)$updateStmt->fetchColumn();

            $db->commit();

            jsonSuccess([
                'movement'         => $movement,
                'new_stock'        => $newStock,
            ], 'Stock adjusted', 201);
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Failed to adjust stock: ' . $e->getMessage(), 500);
        }
        break;

    default:
        jsonError('Method not allowed', 405);
}
