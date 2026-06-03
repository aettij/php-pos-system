<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$productId = $_GET['id'] ?? null;

if ($productId && !validateUuid($productId)) {
    jsonError('Invalid product ID', 400);
}

$db = Database::connect();

switch ($method) {
    case 'GET':
        if ($productId) {
            $stmt = $db->prepare('
                SELECT p.*, c.name AS category_name, s.company_name AS supplier_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                WHERE p.id = :id
                LIMIT 1
            ');
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch();

            if (!$product) {
                jsonError('Product not found', 404);
            }

            jsonSuccess($product);
        }

        [$page, $perPage, $offset] = getPaginationParams();
        $search = getSearchTerm();
        $where = 'WHERE (p.store_id = :store_id OR p.store_id IS NULL)';
        $params = [':store_id' => $user['store_id']];

        if ($search) {
            $where .= ' AND (
                p.name ILIKE :search
                OR p.barcode ILIKE :search
                OR p.sku ILIKE :search
            )';
            $params[':search'] = "%$search%";
        }

        $categoryId = $_GET['category_id'] ?? '';
        if ($categoryId && validateUuid($categoryId)) {
            $where .= ' AND p.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM products p $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sort = getSortParams(['name', 'created_at', 'selling_price', 'stock_quantity']);

        $stmt = $db->prepare("
            SELECT p.*, c.name AS category_name, s.company_name AS supplier_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN suppliers s ON s.id = p.supplier_id
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
        $products = $stmt->fetchAll();

        jsonSuccess([
            'products'   => $products,
            'pagination' => buildPaginationMeta($total, $page, $perPage),
        ]);
        break;

    case 'POST':
        $input = getJsonInput();
        $errors = validateRequired($input, ['name', 'selling_price']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        $name = sanitizeString($input['name']);
        $barcode = sanitizeString($input['barcode'] ?? '');
        $sku = sanitizeString($input['sku'] ?? '');

        if ($barcode) {
            $check = $db->prepare('SELECT id FROM products WHERE barcode = :barcode LIMIT 1');
            $check->execute([':barcode' => $barcode]);
            if ($check->fetch()) {
                jsonError('Barcode already exists', 409);
            }
        }

        $stmt = $db->prepare('
            INSERT INTO products (
                store_id, category_id, supplier_id, name, description,
                barcode, sku, unit, purchase_price, selling_price,
                tax_rate, stock_quantity, stock_min, stock_max,
                image_url, is_active, is_service, allow_negative_stock
            ) VALUES (
                :store_id, :category_id, :supplier_id, :name, :description,
                :barcode, :sku, :unit, :purchase_price, :selling_price,
                :tax_rate, :stock_quantity, :stock_min, :stock_max,
                :image_url, :is_active, :is_service, :allow_negative_stock
            ) RETURNING *
        ');

        $stmt->execute([
            ':store_id'            => $user['store_id'],
            ':category_id'         => !empty($input['category_id']) ? $input['category_id'] : null,
            ':supplier_id'         => !empty($input['supplier_id']) ? $input['supplier_id'] : null,
            ':name'                => $name,
            ':description'         => sanitizeString($input['description'] ?? ''),
            ':barcode'             => $barcode ?: null,
            ':sku'                 => $sku ?: null,
            ':unit'                => sanitizeString($input['unit'] ?? 'piece'),
            ':purchase_price'      => (float)($input['purchase_price'] ?? 0),
            ':selling_price'       => (float)$input['selling_price'],
            ':tax_rate'            => (float)($input['tax_rate'] ?? 20.00),
            ':stock_quantity'      => (float)($input['stock_quantity'] ?? 0),
            ':stock_min'           => (float)($input['stock_min'] ?? 5),
            ':stock_max'           => isset($input['stock_max']) ? (float)$input['stock_max'] : null,
            ':image_url'           => filter_var($input['image_url'] ?? '', FILTER_SANITIZE_URL) ?: null,
            ':is_active'           => true,
            ':is_service'          => (bool)($input['is_service'] ?? false),
            ':allow_negative_stock' => (bool)($input['allow_negative_stock'] ?? false),
        ]);

        $product = $stmt->fetch();
        jsonSuccess($product, 'Product created', 201);
        break;

    case 'PUT':
    case 'PATCH':
        if (!$productId) {
            jsonError('Product ID is required', 400);
        }

        $input = getJsonInput();

        $fields = [];
        $params = [':id' => $productId];

        $allowedFields = [
            'category_id', 'supplier_id', 'name', 'description',
            'barcode', 'sku', 'unit', 'purchase_price', 'selling_price',
            'tax_rate', 'stock_quantity', 'stock_min', 'stock_max',
            'image_url', 'is_active', 'is_service', 'allow_negative_stock',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $value = match ($field) {
                    'category_id', 'supplier_id' => !empty($input[$field]) ? $input[$field] : null,
                    'name' => sanitizeString($input[$field]),
                    'description' => sanitizeString($input[$field]),
                    'barcode', 'sku' => sanitizeString($input[$field]) ?: null,
                    'unit' => sanitizeString($input[$field] ?? 'piece'),
                    'image_url' => filter_var($input[$field] ?? '', FILTER_SANITIZE_URL) ?: null,
                    'purchase_price', 'selling_price', 'tax_rate',
                    'stock_quantity', 'stock_min', 'stock_max' => (float)($input[$field]),
                    'is_active', 'is_service', 'allow_negative_stock' => (bool)($input[$field]),
                    default => $input[$field],
                };

                if ($field === 'stock_max' && ($value === 0.0 || $value === null)) {
                    $value = null;
                }

                $fields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        if (empty($fields)) {
            jsonError('No fields to update', 400);
        }

        $stmt = $db->prepare('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $product = $stmt->fetch();

        if (!$product) {
            jsonError('Product not found', 404);
        }

        jsonSuccess($product, 'Product updated');
        break;

    case 'DELETE':
        if (!$productId) {
            jsonError('Product ID is required', 400);
        }

        $stmt = $db->prepare('UPDATE products SET is_active = FALSE, updated_at = NOW() WHERE id = :id RETURNING id');
        $stmt->execute([':id' => $productId]);

        if (!$stmt->fetch()) {
            jsonError('Product not found', 404);
        }

        jsonSuccess(null, 'Product deactivated');
        break;

    default:
        jsonError('Method not allowed', 405);
}
