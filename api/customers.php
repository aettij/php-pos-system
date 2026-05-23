<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$customerId = $_GET['id'] ?? null;

if ($customerId && !validateUuid($customerId)) {
    jsonError('Invalid customer ID', 400);
}

$db = Database::connect();

switch ($method) {
    case 'GET':
        if ($customerId) {
            $stmt = $db->prepare('
                SELECT * FROM customers WHERE id = :id
                LIMIT 1
            ');
            $stmt->execute([':id' => $customerId]);
            $customer = $stmt->fetch();

            if (!$customer) {
                jsonError('Customer not found', 404);
            }

            $salesStmt = $db->prepare('
                SELECT id, sale_number, total_amount, sale_date, status
                FROM sales
                WHERE customer_id = :customer_id
                ORDER BY sale_date DESC
                LIMIT 10
            ');
            $salesStmt->execute([':customer_id' => $customerId]);
            $customer['recent_sales'] = $salesStmt->fetchAll();

            jsonSuccess($customer);
        }

        [$page, $perPage, $offset] = getPaginationParams();
        $search = getSearchTerm();
        $where = 'WHERE (store_id = :store_id OR store_id IS NULL)';
        $params = [':store_id' => $user['store_id']];

        if ($search) {
            $where .= ' AND (
                first_name ILIKE :search
                OR last_name ILIKE :search
                OR email ILIKE :search
                OR phone ILIKE :search
            )';
            $params[':search'] = "%$search%";
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM customers $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sort = getSortParams(['first_name', 'last_name', 'created_at', 'total_spent', 'loyalty_points']);

        $stmt = $db->prepare("
            SELECT * FROM customers
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
        $customers = $stmt->fetchAll();

        jsonSuccess([
            'customers'  => $customers,
            'pagination' => buildPaginationMeta($total, $page, $perPage),
        ]);
        break;

    case 'POST':
        $input = getJsonInput();
        $errors = validateRequired($input, ['first_name', 'last_name']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        $email = isset($input['email']) ? sanitizeEmail($input['email']) : '';
        if ($email && !validateEmail($email)) {
            jsonError('Invalid email format', 422);
        }

        if ($email) {
            $check = $db->prepare('SELECT id FROM customers WHERE email = :email LIMIT 1');
            $check->execute([':email' => $email]);
            if ($check->fetch()) {
                jsonError('Email already exists', 409);
            }
        }

        $stmt = $db->prepare('
            INSERT INTO customers (
                store_id, first_name, last_name, email, phone,
                address, city, birth_date, notes
            ) VALUES (
                :store_id, :first_name, :last_name, :email, :phone,
                :address, :city, :birth_date, :notes
            ) RETURNING *
        ');
        $stmt->execute([
            ':store_id'   => $user['store_id'],
            ':first_name' => sanitizeString($input['first_name']),
            ':last_name'  => sanitizeString($input['last_name']),
            ':email'      => $email ?: null,
            ':phone'      => sanitizeString($input['phone'] ?? ''),
            ':address'    => sanitizeString($input['address'] ?? ''),
            ':city'       => sanitizeString($input['city'] ?? ''),
            ':birth_date' => $input['birth_date'] ?? null,
            ':notes'      => sanitizeString($input['notes'] ?? ''),
        ]);

        $customer = $stmt->fetch();
        jsonSuccess($customer, 'Customer created', 201);
        break;

    case 'PUT':
    case 'PATCH':
        if (!$customerId) {
            jsonError('Customer ID is required', 400);
        }

        $input = getJsonInput();
        $fields = [];
        $params = [':id' => $customerId];

        $allowedFields = [
            'first_name', 'last_name', 'phone', 'address',
            'city', 'birth_date', 'notes', 'is_active',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = match ($field) {
                    'is_active' => (bool)$input[$field],
                    'birth_date' => $input[$field],
                    default => sanitizeString((string)$input[$field]),
                };
            }
        }

        if (array_key_exists('email', $input)) {
            $email = sanitizeEmail($input['email']);
            if ($email && !validateEmail($email)) {
                jsonError('Invalid email format', 422);
            }
            $fields[] = 'email = :email';
            $params[':email'] = $email ?: null;
        }

        if (empty($fields)) {
            jsonError('No fields to update', 400);
        }

        $stmt = $db->prepare('UPDATE customers SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $customer = $stmt->fetch();

        if (!$customer) {
            jsonError('Customer not found', 404);
        }

        jsonSuccess($customer, 'Customer updated');
        break;

    case 'DELETE':
        if (!$customerId) {
            jsonError('Customer ID is required', 400);
        }

        $stmt = $db->prepare('UPDATE customers SET is_active = FALSE WHERE id = :id RETURNING id');
        $stmt->execute([':id' => $customerId]);

        if (!$stmt->fetch()) {
            jsonError('Customer not found', 404);
        }

        jsonSuccess(null, 'Customer deactivated');
        break;

    default:
        jsonError('Method not allowed', 405);
}
