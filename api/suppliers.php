<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$supplierId = $_GET['id'] ?? null;

if ($supplierId && !validateUuid($supplierId)) {
    jsonError('Invalid supplier ID', 400);
}

$db = Database::connect();

switch ($method) {
    case 'GET':
        if ($supplierId) {
            $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $supplierId]);
            $supplier = $stmt->fetch();

            if (!$supplier) {
                jsonError('Supplier not found', 404);
            }

            $productsStmt = $db->prepare('
                SELECT id, name, barcode, selling_price, stock_quantity
                FROM products WHERE supplier_id = :supplier_id AND is_active = TRUE
                ORDER BY name
            ');
            $productsStmt->execute([':supplier_id' => $supplierId]);
            $supplier['products'] = $productsStmt->fetchAll();

            jsonSuccess($supplier);
        }

        [$page, $perPage, $offset] = getPaginationParams();
        $search = getSearchTerm();
        $where = 'WHERE (store_id = :store_id OR store_id IS NULL)';
        $params = [':store_id' => $user['store_id']];

        if ($search) {
            $where .= ' AND (
                company_name ILIKE :search
                OR contact_name ILIKE :search
                OR email ILIKE :search
            )';
            $params[':search'] = "%$search%";
        }

        if (isset($_GET['is_active'])) {
            $where .= ' AND is_active = :is_active';
            $params[':is_active'] = $_GET['is_active'] ? 't' : 'f';
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM suppliers $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sort = getSortParams(['company_name', 'created_at']);

        $stmt = $db->prepare("
            SELECT * FROM suppliers
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
        $suppliers = $stmt->fetchAll();

        jsonSuccess([
            'suppliers'  => $suppliers,
            'pagination' => buildPaginationMeta($total, $page, $perPage),
        ]);
        break;

    case 'POST':
        $input = getJsonInput();
        $errors = validateRequired($input, ['company_name']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        $stmt = $db->prepare('
            INSERT INTO suppliers (
                store_id, company_name, contact_name, email, phone,
                address, city, country, tax_id, payment_terms, notes
            ) VALUES (
                :store_id, :company_name, :contact_name, :email, :phone,
                :address, :city, :country, :tax_id, :payment_terms, :notes
            ) RETURNING *
        ');
        $stmt->execute([
            ':store_id'      => $user['store_id'],
            ':company_name'  => sanitizeString($input['company_name']),
            ':contact_name'  => sanitizeString($input['contact_name'] ?? ''),
            ':email'         => isset($input['email']) ? sanitizeEmail($input['email']) : null,
            ':phone'         => sanitizeString($input['phone'] ?? ''),
            ':address'       => sanitizeString($input['address'] ?? ''),
            ':city'          => sanitizeString($input['city'] ?? ''),
            ':country'       => sanitizeString($input['country'] ?? 'MA'),
            ':tax_id'        => sanitizeString($input['tax_id'] ?? ''),
            ':payment_terms' => sanitizeString($input['payment_terms'] ?? ''),
            ':notes'         => sanitizeString($input['notes'] ?? ''),
        ]);

        $supplier = $stmt->fetch();
        jsonSuccess($supplier, 'Supplier created', 201);
        break;

    case 'PUT':
    case 'PATCH':
        if (!$supplierId) {
            jsonError('Supplier ID is required', 400);
        }

        $input = getJsonInput();
        $fields = [];
        $params = [':id' => $supplierId];

        $allowedFields = [
            'company_name', 'contact_name', 'phone', 'address',
            'city', 'country', 'tax_id', 'payment_terms', 'notes', 'is_active',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = match ($field) {
                    'is_active' => (bool)$input[$field],
                    default => sanitizeString((string)$input[$field]),
                };
            }
        }

        if (array_key_exists('email', $input)) {
            $email = sanitizeEmail($input['email']);
            $fields[] = 'email = :email';
            $params[':email'] = $email ?: null;
        }

        if (empty($fields)) {
            jsonError('No fields to update', 400);
        }

        $stmt = $db->prepare('UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $supplier = $stmt->fetch();

        if (!$supplier) {
            jsonError('Supplier not found', 404);
        }

        jsonSuccess($supplier, 'Supplier updated');
        break;

    case 'DELETE':
        if (!$supplierId) {
            jsonError('Supplier ID is required', 400);
        }

        $check = $db->prepare('SELECT COUNT(*) FROM products WHERE supplier_id = :id AND is_active = TRUE');
        $check->execute([':id' => $supplierId]);
        if ((int)$check->fetchColumn() > 0) {
            jsonError('Cannot delete supplier linked to active products', 409);
        }

        $stmt = $db->prepare('UPDATE suppliers SET is_active = FALSE WHERE id = :id RETURNING id');
        $stmt->execute([':id' => $supplierId]);

        if (!$stmt->fetch()) {
            jsonError('Supplier not found', 404);
        }

        jsonSuccess(null, 'Supplier deactivated');
        break;

    default:
        jsonError('Method not allowed', 405);
}
