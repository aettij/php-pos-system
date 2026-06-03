<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/docx.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$orderId = $_GET['id'] ?? null;

if ($orderId && !validateUuid($orderId)) {
    jsonError('Invalid order ID', 400);
}

$db = Database::connect();
$storeId = $user['store_id'];

switch ($method) {
    case 'GET':
        // DOCX download
        if (!empty($_GET['docx']) && $orderId) {
            Auth::requirePermission('purchase_orders.view');

            $stmt = $db->prepare('
                SELECT po.*, s.company_name, s.contact_name, s.phone, s.email, s.address AS supplier_address
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                WHERE po.id = :id AND (po.store_id = :store_id OR po.store_id IS NULL)
            ');
            $stmt->execute([':id' => $orderId, ':store_id' => $storeId]);
            $order = $stmt->fetch();

            if (!$order) {
                jsonError('Order not found', 404);
            }

            $itemsStmt = $db->prepare('
                SELECT poi.*, p.name AS product_name, p.barcode
                FROM purchase_order_items poi
                JOIN products p ON p.id = poi.product_id
                WHERE poi.order_id = :order_id
                ORDER BY poi.id
            ');
            $itemsStmt->execute([':order_id' => $orderId]);
            $items = $itemsStmt->fetchAll();

            $supplier = [
                'company_name' => $order['company_name'],
                'contact_name' => $order['contact_name'],
                'phone'        => $order['phone'],
                'email'        => $order['email'],
                'address'      => $order['supplier_address'],
            ];

            DocxWriter::streamPurchaseOrder($order, $items, $supplier);
        }

        // Single order
        if ($orderId) {
            $stmt = $db->prepare('
                SELECT po.*, s.company_name, s.contact_name, s.phone, s.email,
                       u.first_name || \' \' || u.last_name AS user_name
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                LEFT JOIN users u ON u.id = po.user_id
                WHERE po.id = :id AND (po.store_id = :store_id OR po.store_id IS NULL)
            ');
            $stmt->execute([':id' => $orderId, ':store_id' => $storeId]);
            $order = $stmt->fetch();

            if (!$order) {
                jsonError('Order not found', 404);
            }

            $itemsStmt = $db->prepare('
                SELECT poi.*, p.name AS product_name, p.barcode, p.selling_price
                FROM purchase_order_items poi
                JOIN products p ON p.id = poi.product_id
                WHERE poi.order_id = :order_id
                ORDER BY poi.id
            ');
            $itemsStmt->execute([':order_id' => $orderId]);
            $order['items'] = $itemsStmt->fetchAll();

            jsonSuccess($order);
        }

        // List orders
        [$page, $perPage, $offset] = getPaginationParams();
        $search = getSearchTerm();
        $where = 'WHERE (po.store_id = :store_id OR po.store_id IS NULL)';
        $params = [':store_id' => $storeId];

        if ($search) {
            $where .= ' AND (po.order_number ILIKE :search OR s.company_name ILIKE :search)';
            $params[':search'] = "%$search%";
        }

        if (!empty($_GET['status'])) {
            $where .= ' AND po.status = :status';
            $params[':status'] = sanitizeString($_GET['status']);
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT po.*, s.company_name, s.contact_name,
                   u.first_name || ' ' || u.last_name AS user_name,
                   COALESCE(ic.items_count, 0) AS items_count,
                   COALESCE(ic.total_qty, 0) AS total_qty
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN users u ON u.id = po.user_id
            LEFT JOIN LATERAL (
                SELECT COUNT(*)::INT AS items_count,
                       COALESCE(SUM(ordered_qty), 0) AS total_qty
                FROM purchase_order_items
                WHERE order_id = po.id
            ) ic ON true
            $where
            ORDER BY po.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        $orders = $stmt->fetchAll();

        jsonSuccess([
            'orders'     => $orders,
            'pagination' => buildPaginationMeta($total, $page, $perPage),
        ]);
        break;

    case 'POST':
        $input = getJsonInput();
        $errors = validateRequired($input, ['supplier_id', 'items']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        if (!validateUuid($input['supplier_id'])) {
            jsonError('Invalid supplier ID', 400);
        }

        if (!is_array($input['items']) || empty($input['items'])) {
            jsonError('Order must contain at least one item', 422);
        }

        // Validate supplier exists
        $supplierStmt = $db->prepare('SELECT id FROM suppliers WHERE id = :id AND is_active = TRUE');
        $supplierStmt->execute([':id' => $input['supplier_id']]);
        if (!$supplierStmt->fetch()) {
            jsonError('Supplier not found or inactive', 404);
        }

        try {
            $db->beginTransaction();

            $items = [];
            $total = 0;

            foreach ($input['items'] as $item) {
                $itemErrors = validateRequired($item, ['product_id', 'ordered_qty', 'unit_price']);
                if ($itemErrors) {
                    throw new \InvalidArgumentException('Each item requires product_id, ordered_qty, and unit_price');
                }

                if (!validateUuid($item['product_id'])) {
                    throw new \InvalidArgumentException('Invalid product ID');
                }

                $qty = (float)$item['ordered_qty'];
                $price = (float)$item['unit_price'];
                $discount = (float)($item['discount_pct'] ?? 0);
                $tax = (float)($item['tax_rate'] ?? 20.00);

                $subtotal = $qty * $price * (1 - $discount / 100);
                $total += $subtotal;

                $items[] = [
                    'product_id'  => $item['product_id'],
                    'ordered_qty' => $qty,
                    'unit_price'  => $price,
                    'tax_rate'    => $tax,
                    'discount_pct' => $discount,
                    'subtotal'    => $subtotal,
                ];
            }

            $discountAmount = (float)($input['discount_amount'] ?? 0);
            $taxAmount = $total * (20 / 120); // Standard VAT calc
            $totalAmount = max(0, $total - $discountAmount + $taxAmount);

            $stmt = $db->prepare('
                INSERT INTO purchase_orders (store_id, supplier_id, user_id, status, order_date, expected_date, notes, total_amount, tax_amount, discount_amount)
                VALUES (:store_id, :supplier_id, :user_id, :status, :order_date, :expected_date, :notes, :total_amount, :tax_amount, :discount_amount)
                RETURNING *
            ');
            $stmt->execute([
                ':store_id'        => $storeId,
                ':supplier_id'     => $input['supplier_id'],
                ':user_id'         => $user['id'],
                ':status'          => $input['status'] ?? 'draft',
                ':order_date'      => $input['order_date'] ?? date('Y-m-d'),
                ':expected_date'   => $input['expected_date'] ?? null,
                ':notes'           => sanitizeString($input['notes'] ?? ''),
                ':total_amount'    => round($totalAmount, 2),
                ':tax_amount'      => round($taxAmount, 2),
                ':discount_amount' => round($discountAmount, 2),
            ]);
            $order = $stmt->fetch();

            $itemStmt = $db->prepare('
                INSERT INTO purchase_order_items (order_id, product_id, ordered_qty, unit_price, tax_rate, discount_pct)
                VALUES (:order_id, :product_id, :ordered_qty, :unit_price, :tax_rate, :discount_pct)
            ');

            foreach ($items as $item) {
                $itemStmt->execute([
                    ':order_id'    => $order['id'],
                    ':product_id'  => $item['product_id'],
                    ':ordered_qty' => $item['ordered_qty'],
                    ':unit_price'  => $item['unit_price'],
                    ':tax_rate'    => $item['tax_rate'],
                    ':discount_pct' => $item['discount_pct'],
                ]);
            }

            $db->commit();

            // Fetch full order with items
            $orderStmt = $db->prepare('
                SELECT po.*, s.company_name
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                WHERE po.id = :id
            ');
            $orderStmt->execute([':id' => $order['id']]);
            $order = $orderStmt->fetch();

            $itemsStmt = $db->prepare('
                SELECT poi.*, p.name AS product_name, p.barcode
                FROM purchase_order_items poi
                JOIN products p ON p.id = poi.product_id
                WHERE poi.order_id = :order_id
            ');
            $itemsStmt->execute([':order_id' => $order['id']]);
            $order['items'] = $itemsStmt->fetchAll();

            jsonSuccess($order, 'Order created', 201);
        } catch (\InvalidArgumentException $e) {
            $db->rollBack();
            jsonError($e->getMessage(), 422);
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Failed to create order: ' . $e->getMessage(), 500);
        }
        break;

    case 'PUT':
    case 'PATCH':
        if (!$orderId) {
            jsonError('Order ID is required', 400);
        }

        $input = getJsonInput();

        // Status update (receive order)
        if (isset($input['status']) && $input['status'] === 'received') {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("
                    UPDATE purchase_orders SET status = 'received', received_date = NOW(), updated_at = NOW()
                    WHERE id = :id AND (store_id = :store_id OR store_id IS NULL) AND status IN ('sent', 'partial')
                    RETURNING *
                ");
                $stmt->execute([':id' => $orderId, ':store_id' => $storeId]);
                $order = $stmt->fetch();

                if (!$order) {
                    $db->rollBack();
                    jsonError('Order not found or cannot be received', 404);
                }

                // Update received_qty for all items to match ordered_qty
                $updateStmt = $db->prepare('
                    UPDATE purchase_order_items SET received_qty = ordered_qty WHERE order_id = :order_id
                ');
                $updateStmt->execute([':order_id' => $orderId]);

                // Get order items to update stock
                $itemsStmt = $db->prepare('
                    SELECT product_id, ordered_qty FROM purchase_order_items WHERE order_id = :order_id
                ');
                $itemsStmt->execute([':order_id' => $orderId]);
                $orderItems = $itemsStmt->fetchAll();

                $stockStmt = $db->prepare("
                    UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
                    WHERE id = :product_id AND (is_service::text NOT IN ('1', 't', 'true') OR is_service IS NULL)
                ");

                $movementStmt = $db->prepare('
                    INSERT INTO stock_movements (product_id, store_id, user_id, movement_type, quantity, unit_cost, reference_id, reference_type, notes)
                    VALUES (:product_id, :store_id, :user_id, \'purchase\', :qty, NULL, :reference_id, \'purchase_order\', :notes)
                ');

                foreach ($orderItems as $oi) {
                    $stockStmt->execute([
                        ':qty'        => $oi['ordered_qty'],
                        ':product_id' => $oi['product_id'],
                    ]);

                    $movementStmt->execute([
                        ':product_id'   => $oi['product_id'],
                        ':store_id'     => $storeId,
                        ':user_id'      => $user['id'],
                        ':qty'          => $oi['ordered_qty'],
                        ':reference_id' => $orderId,
                        ':notes'        => 'Réception commande ' . ($order['order_number'] ?? ''),
                    ]);
                }

                $db->commit();

                jsonSuccess($order, 'Order received');
            } catch (\Exception $e) {
                $db->rollBack();
                jsonError('Failed to receive order: ' . $e->getMessage(), 500);
            }
            break;
        }

        // General update (notes, expected_date, status)
        $fields = [];
        $params = [':id' => $orderId];

        $allowedFields = ['status', 'expected_date', 'notes', 'discount_amount', 'tax_amount', 'total_amount'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $input[$field];
            }
        }

        if (empty($fields)) {
            jsonError('No fields to update', 400);
        }

        $fields[] = 'updated_at = NOW()';
        $stmt = $db->prepare('UPDATE purchase_orders SET ' . implode(', ', $fields) . ' WHERE id = :id AND (store_id = :store_id OR store_id IS NULL) RETURNING *');
        $params[':store_id'] = $storeId;
        $stmt->execute($params);
        $order = $stmt->fetch();

        if (!$order) {
            jsonError('Order not found', 404);
        }

        jsonSuccess($order, 'Order updated');
        break;

    case 'DELETE':
        if (!$orderId) {
            jsonError('Order ID is required', 400);
        }

        $stmt = $db->prepare("UPDATE purchase_orders SET status = 'cancelled', updated_at = NOW() WHERE id = :id AND (store_id = :store_id OR store_id IS NULL) AND status IN ('draft', 'sent') RETURNING id");
        $stmt->execute([':id' => $orderId, ':store_id' => $storeId]);

        if (!$stmt->fetch()) {
            jsonError('Order not found or cannot be cancelled', 404);
        }

        jsonSuccess(null, 'Order cancelled');
        break;

    default:
        jsonError('Method not allowed', 405);
}
