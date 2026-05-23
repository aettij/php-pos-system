<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$categoryId = $_GET['id'] ?? null;

if ($categoryId && !validateUuid($categoryId)) {
    jsonError('Invalid category ID', 400);
}

$db = Database::connect();

switch ($method) {
    case 'GET':
        if ($categoryId) {
            $stmt = $db->prepare('
                SELECT c.*, p.name AS parent_name
                FROM categories c
                LEFT JOIN categories p ON p.id = c.parent_id
                WHERE c.id = :id
                LIMIT 1
            ');
            $stmt->execute([':id' => $categoryId]);
            $category = $stmt->fetch();

            if (!$category) {
                jsonError('Category not found', 404);
            }

            jsonSuccess($category);
        }

        $search = getSearchTerm();
        $where = 'WHERE (c.store_id = :store_id OR c.store_id IS NULL)';
        $params = [':store_id' => $user['store_id']];

        if ($search) {
            $where .= ' AND c.name ILIKE :search';
            $params[':search'] = "%$search%";
        }

        $sort = getSortParams(['name', 'sort_order', 'created_at']);

        $stmt = $db->prepare("
            SELECT c.*, p.name AS parent_name,
                   (SELECT COUNT(*) FROM products WHERE category_id = c.id AND is_active = TRUE) AS product_count
            FROM categories c
            LEFT JOIN categories p ON p.id = c.parent_id
            $where
            ORDER BY $sort
        ");
        $stmt->execute($params);
        $categories = $stmt->fetchAll();

        jsonSuccess(['categories' => $categories]);
        break;

    case 'POST':
        $input = getJsonInput();
        $errors = validateRequired($input, ['name']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        $stmt = $db->prepare('
            INSERT INTO categories (store_id, parent_id, name, description, color, icon, sort_order)
            VALUES (:store_id, :parent_id, :name, :description, :color, :icon, :sort_order)
            RETURNING *
        ');
        $stmt->execute([
            ':store_id'    => $user['store_id'],
            ':parent_id'   => !empty($input['parent_id']) ? $input['parent_id'] : null,
            ':name'        => sanitizeString($input['name']),
            ':description' => sanitizeString($input['description'] ?? ''),
            ':color'       => preg_match('/^#[0-9a-fA-F]{6}$/', $input['color'] ?? '') ? $input['color'] : null,
            ':icon'        => sanitizeString($input['icon'] ?? ''),
            ':sort_order'  => (int)($input['sort_order'] ?? 0),
        ]);

        $category = $stmt->fetch();
        jsonSuccess($category, 'Category created', 201);
        break;

    case 'PUT':
    case 'PATCH':
        if (!$categoryId) {
            jsonError('Category ID is required', 400);
        }

        $input = getJsonInput();
        $fields = [];
        $params = [':id' => $categoryId];

        foreach (['name', 'description', 'color', 'icon'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = sanitizeString($input[$field]);
                if ($field === 'color') {
                    $value = preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
                }
                $fields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        if (array_key_exists('parent_id', $input)) {
            $fields[] = 'parent_id = :parent_id';
            $params[':parent_id'] = !empty($input['parent_id']) ? $input['parent_id'] : null;
        }

        if (array_key_exists('sort_order', $input)) {
            $fields[] = 'sort_order = :sort_order';
            $params[':sort_order'] = (int)$input['sort_order'];
        }

        if (array_key_exists('is_active', $input)) {
            $fields[] = 'is_active = :is_active';
            $params[':is_active'] = (bool)$input['is_active'];
        }

        if (empty($fields)) {
            jsonError('No fields to update', 400);
        }

        $stmt = $db->prepare('UPDATE categories SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING *');
        $stmt->execute($params);
        $category = $stmt->fetch();

        if (!$category) {
            jsonError('Category not found', 404);
        }

        jsonSuccess($category, 'Category updated');
        break;

    case 'DELETE':
        if (!$categoryId) {
            jsonError('Category ID is required', 400);
        }

        $check = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id AND is_active = TRUE');
        $check->execute([':id' => $categoryId]);
        if ((int)$check->fetchColumn() > 0) {
            jsonError('Cannot delete category with active products. Deactivate products first.', 409);
        }

        $childCheck = $db->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = :id');
        $childCheck->execute([':id' => $categoryId]);
        if ((int)$childCheck->fetchColumn() > 0) {
            jsonError('Cannot delete category with sub-categories. Remove children first.', 409);
        }

        $stmt = $db->prepare('DELETE FROM categories WHERE id = :id RETURNING id');
        $stmt->execute([':id' => $categoryId]);

        if (!$stmt->fetch()) {
            jsonError('Category not found', 404);
        }

        jsonSuccess(null, 'Category deleted');
        break;

    default:
        jsonError('Method not allowed', 405);
}
