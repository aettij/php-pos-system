<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$storeId = $_GET['id'] ?? null;

if ($storeId && !validateUuid($storeId)) {
    jsonError('Invalid store ID', 400);
}

$db = Database::connect();

switch ($method) {
    case 'GET':
        if ($storeId) {
            $stmt = $db->prepare('SELECT * FROM stores WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $storeId]);
            $store = $stmt->fetch();

            if (!$store) {
                jsonError('Store not found', 404);
            }

            jsonSuccess($store);
        }

        $stmt = $db->prepare('SELECT * FROM stores WHERE is_active = TRUE ORDER BY name');
        $stmt->execute();
        $stores = $stmt->fetchAll();

        jsonSuccess(['stores' => $stores]);
        break;

    case 'POST':
        Auth::requirePermission('settings.manage');

        $input = getJsonInput();
        $errors = validateRequired($input, ['name']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        $stmt = $db->prepare('
            INSERT INTO stores (name, address, city, country, phone, email, tax_id, currency, timezone)
            VALUES (:name, :address, :city, :country, :phone, :email, :tax_id, :currency, :timezone)
            RETURNING *
        ');
        $stmt->execute([
            ':name'     => sanitizeString($input['name']),
            ':address'  => sanitizeString($input['address'] ?? ''),
            ':city'     => sanitizeString($input['city'] ?? ''),
            ':country'  => sanitizeString($input['country'] ?? 'MA'),
            ':phone'    => sanitizeString($input['phone'] ?? ''),
            ':email'    => isset($input['email']) ? sanitizeEmail($input['email']) : null,
            ':tax_id'   => sanitizeString($input['tax_id'] ?? ''),
            ':currency' => strtoupper(sanitizeString($input['currency'] ?? 'MAD')),
            ':timezone' => sanitizeString($input['timezone'] ?? 'Africa/Casablanca'),
        ]);

        $store = $stmt->fetch();
        jsonSuccess($store, 'Store created', 201);
        break;

    case 'PUT':
    case 'PATCH':
        Auth::requirePermission('settings.manage');

        if (!$storeId) {
            jsonError('Store ID is required', 400);
        }

        $input = getJsonInput();
        $fields = [];
        $params = [':id' => $storeId];

        foreach (['name', 'address', 'city', 'country', 'phone', 'tax_id', 'timezone', 'is_active'] as $field) {
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

        if (array_key_exists('currency', $input)) {
            $fields[] = 'currency = :currency';
            $params[':currency'] = strtoupper(sanitizeString($input['currency']));
        }

        if (empty($fields)) {
            jsonError('No fields to update', 400);
        }

        $stmt = $db->prepare('UPDATE stores SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $store = $stmt->fetch();

        if (!$store) {
            jsonError('Store not found', 404);
        }

        jsonSuccess($store, 'Store updated');
        break;

    case 'DELETE':
        Auth::requirePermission('settings.manage');

        if (!$storeId) {
            jsonError('Store ID is required', 400);
        }

        $stmt = $db->prepare('UPDATE stores SET is_active = FALSE WHERE id = :id RETURNING id');
        $stmt->execute([':id' => $storeId]);

        if (!$stmt->fetch()) {
            jsonError('Store not found', 404);
        }

        jsonSuccess(null, 'Store deactivated');
        break;

    default:
        jsonError('Method not allowed', 405);
}
