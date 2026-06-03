<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/docx.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$saleId = $_GET['id'] ?? null;

if ($saleId && !validateUuid($saleId)) {
    jsonError('Invalid sale ID', 400);
}

$db = Database::connect();

switch ($method) {
    case 'GET':
        if ($saleId) {
            $stmt = $db->prepare('
                SELECT s.*,
                       u.first_name || \' \' || u.last_name AS cashier,
                       c.first_name || \' \' || c.last_name AS customer_name,
                       c.phone AS customer_phone,
                       pm.name AS payment_method_name
                FROM sales s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN customers c ON c.id = s.customer_id
                LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
                WHERE s.id = :id
                LIMIT 1
            ');
            $stmt->execute([':id' => $saleId]);
            $sale = $stmt->fetch();

            if (!$sale) {
                jsonError('Sale not found', 404);
            }

            $itemsStmt = $db->prepare('
                SELECT si.*, p.name AS product_name, p.barcode, p.sku
                FROM sale_items si
                JOIN products p ON p.id = si.product_id
                WHERE si.sale_id = :sale_id
                ORDER BY si.id
            ');
            $itemsStmt->execute([':sale_id' => $saleId]);
            $sale['items'] = $itemsStmt->fetchAll();

            // DOCX invoice download
            if (!empty($_GET['docx'])) {
                $customer = [
                    'first_name' => $sale['customer_name'] ?? 'Client libre',
                    'phone'      => $sale['customer_phone'] ?? '',
                ];
                DocxWriter::streamInvoice($sale, $sale['items'], $customer);
            }

            jsonSuccess($sale);
        }

        [$page, $perPage, $offset] = getPaginationParams();
        $search = getSearchTerm();
        $where = 'WHERE (s.store_id = :store_id OR s.store_id IS NULL)';
        $params = [':store_id' => $user['store_id']];

        if ($search) {
            $where .= ' AND (
                s.sale_number ILIKE :search
            )';
            $params[':search'] = "%$search%";
        }

        if (!empty($_GET['status'])) {
            $where .= ' AND s.status = :status';
            $params[':status'] = sanitizeString($_GET['status']);
        }

        if (!empty($_GET['date_from'])) {
            $where .= ' AND s.sale_date >= :date_from';
            $params[':date_from'] = $_GET['date_from'] . ' 00:00:00';
        }

        if (!empty($_GET['date_to'])) {
            $where .= ' AND s.sale_date <= :date_to';
            $params[':date_to'] = $_GET['date_to'] . ' 23:59:59';
        }

        if (!empty($_GET['customer_id'])) {
            if (!validateUuid($_GET['customer_id'])) {
                jsonError('Invalid customer ID', 400);
            }
            $where .= ' AND s.customer_id = :customer_id';
            $params[':customer_id'] = $_GET['customer_id'];
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM sales s $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sort = getSortParams(['sale_date', 'total_amount', 'created_at']);

        $stmt = $db->prepare("
            SELECT s.*,
                   u.first_name || ' ' || u.last_name AS cashier,
                   c.first_name || ' ' || c.last_name AS customer_name,
                   pm.name AS payment_method_name
            FROM sales s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN customers c ON c.id = s.customer_id
            LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
            $where
            ORDER BY $sort
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        $sales = $stmt->fetchAll();

        jsonSuccess([
            'sales'      => $sales,
            'pagination' => buildPaginationMeta($total, $page, $perPage),
        ]);
        break;

    case 'POST':
        $input = getJsonInput();
        $errors = validateRequired($input, ['items']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        if (!is_array($input['items']) || empty($input['items'])) {
            jsonError('Sale must contain at least one item', 422);
        }

        try {
            $db->beginTransaction();

            $subtotal = 0;
            $items = [];

            foreach ($input['items'] as $item) {
                $itemErrors = validateRequired($item, ['product_id', 'quantity', 'unit_price']);
                if ($itemErrors) {
                    throw new \InvalidArgumentException('Each item requires product_id, quantity, and unit_price');
                }

                if (!validateUuid($item['product_id'])) {
                    throw new \InvalidArgumentException('Invalid product ID');
                }

                $qty = (float)$item['quantity'];
                $price = (float)$item['unit_price'];
                $discount = (float)($item['discount_pct'] ?? 0);
                $tax = (float)($item['tax_rate'] ?? 20.00);

                $itemSubtotal = $qty * $price * (1 - $discount / 100);
                $subtotal += $itemSubtotal;

                // Get purchase price
                $pStmt = $db->prepare('SELECT purchase_price, is_service, allow_negative_stock, stock_quantity FROM products WHERE id = :id AND is_active = TRUE');
                $pStmt->execute([':id' => $item['product_id']]);
                $product = $pStmt->fetch();

                if (!$product) {
                    throw new \InvalidArgumentException("Product {$item['product_id']} not found or inactive");
                }

                if (!$product['is_service'] && !$product['allow_negative_stock'] && $product['stock_quantity'] < $qty) {
                    throw new \InvalidArgumentException("Insufficient stock for product {$item['product_id']}");
                }

                $items[] = [
                    'product_id'     => $item['product_id'],
                    'quantity'       => $qty,
                    'unit_price'     => $price,
                    'purchase_price' => (float)$product['purchase_price'],
                    'discount_pct'   => $discount,
                    'tax_rate'       => $tax,
                    'is_service'     => (bool)$product['is_service'],
                ];
            }

            $taxAmount = $subtotal * (20 / 120); // Standard VAT 20%
            $discountAmount = (float)($input['discount_amount'] ?? 0);
            $totalAmount = $subtotal - $discountAmount;

            $paymentMethodId = (int)($input['payment_method_id'] ?? 1);
            $paidAmount = (float)($input['paid_amount'] ?? $totalAmount);
            $changeAmount = max(0, $paidAmount - $totalAmount);

            $stmt = $db->prepare('
                INSERT INTO sales (
                    store_id, user_id, customer_id, status,
                    subtotal, discount_amount, tax_amount, total_amount,
                    paid_amount, change_amount, payment_method_id, notes
                ) VALUES (
                    :store_id, :user_id, :customer_id, \'completed\',
                    :subtotal, :discount_amount, :tax_amount, :total_amount,
                    :paid_amount, :change_amount, :payment_method_id, :notes
                ) RETURNING *
            ');
            $stmt->execute([
                ':store_id'        => $user['store_id'],
                ':user_id'         => $user['id'],
                ':customer_id'     => !empty($input['customer_id']) ? $input['customer_id'] : null,
                ':subtotal'        => round($subtotal, 2),
                ':discount_amount' => round($discountAmount, 2),
                ':tax_amount'      => round($taxAmount, 2),
                ':total_amount'    => round($totalAmount, 2),
                ':paid_amount'     => round($paidAmount, 2),
                ':change_amount'   => round($changeAmount, 2),
                ':payment_method_id' => $paymentMethodId,
                ':notes'           => sanitizeString($input['notes'] ?? ''),
            ]);

            $sale = $stmt->fetch();

            $itemStmt = $db->prepare('
                INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, purchase_price, discount_pct, tax_rate)
                VALUES (:sale_id, :product_id, :quantity, :unit_price, :purchase_price, :discount_pct, :tax_rate)
            ');

            $stockStmt = $db->prepare('
                UPDATE products SET stock_quantity = stock_quantity - :qty, updated_at = NOW()
                WHERE id = :product_id AND (is_service::text NOT IN (\'1\', \'t\', \'true\') OR is_service IS NULL)
            ');

            $movementStmt = $db->prepare('
                INSERT INTO stock_movements (product_id, store_id, user_id, movement_type, quantity, unit_cost, reference_id, reference_type, notes)
                VALUES (:product_id, :store_id, :user_id, \'sale\', :qty, :unit_cost, :reference_id, \'sale\', :notes)
            ');

            foreach ($items as $item) {
                $itemStmt->execute([
                    ':sale_id'        => $sale['id'],
                    ':product_id'     => $item['product_id'],
                    ':quantity'       => $item['quantity'],
                    ':unit_price'     => $item['unit_price'],
                    ':purchase_price' => $item['purchase_price'],
                    ':discount_pct'   => $item['discount_pct'],
                    ':tax_rate'       => $item['tax_rate'],
                ]);

                $stockStmt->execute([
                    ':qty'        => $item['quantity'],
                    ':product_id' => $item['product_id'],
                ]);
                $affected = $stockStmt->rowCount();
                if ($affected === 0 && !$item['is_service']) {
                    throw new \RuntimeException("Stock decrement failed for product {$item['product_id']} — product not found or is_service flag mismatch");
                }
                Logger::debug('Stock decrement', [
                    'product_id' => $item['product_id'],
                    'qty'        => $item['quantity'],
                    'affected'   => $affected,
                ]);

                $movementStmt->execute([
                    ':product_id'   => $item['product_id'],
                    ':store_id'     => $user['store_id'],
                    ':user_id'      => $user['id'],
                    ':qty'          => -$item['quantity'],
                    ':unit_cost'    => $item['purchase_price'],
                    ':reference_id' => $sale['id'],
                    ':notes'        => 'Vente ' . ($sale['sale_number'] ?? ''),
                ]);
            }

            $db->commit();
            Logger::info('Sale created with stock update', [
                'sale_id'  => $sale['id'],
                'items'    => count($items),
            ]);

            $stmt2 = $db->prepare('
                SELECT s.*,
                       u.first_name || \' \' || u.last_name AS cashier,
                       c.first_name || \' \' || c.last_name AS customer_name,
                       pm.name AS payment_method_name
                FROM sales s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN customers c ON c.id = s.customer_id
                LEFT JOIN payment_methods pm ON pm.id = s.payment_method_id
                WHERE s.id = :id LIMIT 1
            ');
            $stmt2->execute([':id' => $sale['id']]);
            $sale = $stmt2->fetch();

            $itemsStmt = $db->prepare('
                SELECT si.*, p.name AS product_name, p.barcode
                FROM sale_items si JOIN products p ON p.id = si.product_id
                WHERE si.sale_id = :sale_id
            ');
            $itemsStmt->execute([':sale_id' => $sale['id']]);
            $sale['items'] = $itemsStmt->fetchAll();

            jsonSuccess($sale, 'Sale completed', 201);
        } catch (\InvalidArgumentException $e) {
            $db->rollBack();
            jsonError($e->getMessage(), 422);
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Failed to create sale: ' . $e->getMessage(), 500);
        }
        break;

    case 'DELETE':
        if (!$saleId) {
            jsonError('Sale ID is required', 400);
        }

        try {
            $db->beginTransaction();

            // Get sale items to restore stock
            $itemsStmt = $db->prepare('
                SELECT product_id, quantity FROM sale_items WHERE sale_id = :sale_id
            ');
            $itemsStmt->execute([':sale_id' => $saleId]);
            $items = $itemsStmt->fetchAll();

            if (empty($items)) {
                jsonError('Sale has no items', 404);
            }

            // Restore stock for each item
            $restoreStmt = $db->prepare('
                UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
                WHERE id = :product_id AND (is_service::text NOT IN (\'1\', \'t\', \'true\') OR is_service IS NULL)
            ');
            $movementStmt = $db->prepare('
                INSERT INTO stock_movements (product_id, store_id, user_id, movement_type, quantity, unit_cost, reference_id, reference_type, notes)
                VALUES (:product_id, :store_id, :user_id, \'return\', :qty, NULL, :reference_id, \'sale_cancellation\', :notes)
            ');

            foreach ($items as $item) {
                $restoreStmt->execute([
                    ':qty' => $item['quantity'],
                    ':product_id' => $item['product_id'],
                ]);

                $movementStmt->execute([
                    ':product_id'   => $item['product_id'],
                    ':store_id'     => $user['store_id'],
                    ':user_id'      => $user['id'],
                    ':qty'          => $item['quantity'],
                    ':reference_id' => $saleId,
                    ':notes'        => 'Annulation de la vente',
                ]);
            }

            // Mark sale as cancelled
            $stmt = $db->prepare('UPDATE sales SET status = \'cancelled\' WHERE id = :id AND status = \'completed\' RETURNING id');
            $stmt->execute([':id' => $saleId]);

            if (!$stmt->fetch()) {
                $db->rollBack();
                jsonError('Sale not found or already cancelled', 404);
            }

            $db->commit();
            jsonSuccess(null, 'Sale cancelled and stock restored');
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Failed to cancel sale: ' . $e->getMessage(), 500);
        }
        break;

    default:
        jsonError('Method not allowed', 405);
}
