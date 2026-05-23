<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$userId = $_GET['id'] ?? null;

if ($userId && !validateUuid($userId)) {
    jsonError('Invalid user ID', 400);
}

$db = Database::connect();

switch ($method) {
    case 'GET':
        if ($userId) {
            $stmt = $db->prepare('
                SELECT u.id, u.store_id, u.first_name, u.last_name, u.email,
                       u.phone, u.is_active, u.last_login_at, u.created_at,
                       s.name AS store_name
                FROM users u
                LEFT JOIN stores s ON s.id = u.store_id
                WHERE u.id = :id
                LIMIT 1
            ');
            $stmt->execute([':id' => $userId]);
            $found = $stmt->fetch();

            if (!$found) {
                jsonError('User not found', 404);
            }

            $rolesStmt = $db->prepare('
                SELECT r.id, r.name FROM roles r
                JOIN user_roles ur ON ur.role_id = r.id
                WHERE ur.user_id = :user_id
            ');
            $rolesStmt->execute([':user_id' => $userId]);
            $found['roles'] = $rolesStmt->fetchAll();

            jsonSuccess($found);
        }

        [$page, $perPage, $offset] = getPaginationParams();
        $search = getSearchTerm();
        $where = 'WHERE 1=1';
        $params = [];

        if ($search) {
            $where .= ' AND (
                u.first_name ILIKE :search
                OR u.last_name ILIKE :search
                OR u.email ILIKE :search
            )';
            $params[':search'] = "%$search%";
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM users u $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sort = getSortParams(['first_name', 'last_name', 'email', 'created_at', 'last_login_at']);

        $stmt = $db->prepare("
            SELECT u.id, u.store_id, u.first_name, u.last_name, u.email,
                   u.phone, u.is_active, u.last_login_at, u.created_at,
                   s.name AS store_name
            FROM users u
            LEFT JOIN stores s ON s.id = u.store_id
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
        $users = $stmt->fetchAll();

        foreach ($users as &$u) {
            $rStmt = $db->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :uid');
            $rStmt->execute([':uid' => $u['id']]);
            $u['roles'] = array_column($rStmt->fetchAll(), 'name');
        }

        jsonSuccess([
            'users'      => $users,
            'pagination' => buildPaginationMeta($total, $page, $perPage),
        ]);
        break;

    case 'POST':
        Auth::requirePermission('user.manage');

        $input = getJsonInput();
        $errors = validateRequired($input, ['first_name', 'last_name', 'email', 'password']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        $email = sanitizeEmail($input['email']);
        if (!validateEmail($email)) {
            jsonError('Invalid email format', 422);
        }

        $check = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $check->execute([':email' => $email]);
        if ($check->fetch()) {
            jsonError('Email already exists', 409);
        }

        $stmt = $db->prepare('
            INSERT INTO users (store_id, first_name, last_name, email, phone, password_hash, pin_code)
            VALUES (:store_id, :first_name, :last_name, :email, :phone, :password_hash, :pin_code)
            RETURNING id, store_id, first_name, last_name, email, phone, is_active, created_at
        ');
        $stmt->execute([
            ':store_id'      => !empty($input['store_id']) ? $input['store_id'] : $user['store_id'],
            ':first_name'    => sanitizeString($input['first_name']),
            ':last_name'     => sanitizeString($input['last_name']),
            ':email'         => $email,
            ':phone'         => sanitizeString($input['phone'] ?? ''),
            ':password_hash' => hashPassword($input['password']),
            ':pin_code'      => !empty($input['pin_code']) ? $input['pin_code'] : null,
        ]);

        $newUser = $stmt->fetch();

        if (!empty($input['roles']) && is_array($input['roles'])) {
            $roleStmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id) ON CONFLICT DO NOTHING');
            foreach ($input['roles'] as $roleId) {
                $roleStmt->execute([':user_id' => $newUser['id'], ':role_id' => (int)$roleId]);
            }
        }

        jsonSuccess($newUser, 'User created', 201);
        break;

    case 'PUT':
    case 'PATCH':
        if (!$userId) {
            jsonError('User ID is required', 400);
        }

        if ($userId !== $user['id']) {
            Auth::requirePermission('user.manage');
        }

        $input = getJsonInput();
        $fields = [];
        $params = [':id' => $userId];

        foreach (['first_name', 'last_name', 'phone', 'pin_code'] as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = sanitizeString((string)$input[$field]);
            }
        }

        if (array_key_exists('is_active', $input)) {
            Auth::requirePermission('user.manage');
            $fields[] = 'is_active = :is_active';
            $params[':is_active'] = (bool)$input['is_active'];
        }

        if (array_key_exists('store_id', $input)) {
            Auth::requirePermission('user.manage');
            $fields[] = 'store_id = :store_id';
            $params[':store_id'] = !empty($input['store_id']) ? $input['store_id'] : null;
        }

        if (array_key_exists('password', $input) && !empty($input['password'])) {
            $fields[] = 'password_hash = :password_hash';
            $params[':password_hash'] = hashPassword($input['password']);
        }

        if (array_key_exists('email', $input)) {
            $email = sanitizeEmail($input['email']);
            if (!validateEmail($email)) {
                jsonError('Invalid email format', 422);
            }
            $check = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
            $check->execute([':email' => $email, ':id' => $userId]);
            if ($check->fetch()) {
                jsonError('Email already in use', 409);
            }
            $fields[] = 'email = :email';
            $params[':email'] = $email;
        }

        if (empty($fields)) {
            jsonError('No fields to update', 400);
        }

        $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING id, store_id, first_name, last_name, email, phone, is_active, updated_at');
        $stmt->execute($params);
        $updated = $stmt->fetch();

        if (!$updated) {
            jsonError('User not found', 404);
        }

        if (isset($input['roles']) && is_array($input['roles'])) {
            Auth::requirePermission('user.manage');
            $db->prepare('DELETE FROM user_roles WHERE user_id = :uid')->execute([':uid' => $userId]);
            $roleStmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id) ON CONFLICT DO NOTHING');
            foreach ($input['roles'] as $roleId) {
                $roleStmt->execute([':user_id' => $userId, ':role_id' => (int)$roleId]);
            }
        }

        jsonSuccess($updated, 'User updated');
        break;

    case 'DELETE':
        if (!$userId) {
            jsonError('User ID is required', 400);
        }

        Auth::requirePermission('user.manage');

        if ($userId === $user['id']) {
            jsonError('Cannot deactivate yourself', 409);
        }

        $stmt = $db->prepare('UPDATE users SET is_active = FALSE WHERE id = :id RETURNING id');
        $stmt->execute([':id' => $userId]);

        if (!$stmt->fetch()) {
            jsonError('User not found', 404);
        }

        jsonSuccess(null, 'User deactivated');
        break;

    default:
        jsonError('Method not allowed', 405);
}
