<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $input = getJsonInput();
        $errors = validateRequired($input, ['email', 'password']);
        if ($errors) {
            jsonError('Validation failed', 422, $errors);
        }

        $email = sanitizeEmail($input['email']);
        if (!validateEmail($email)) {
            jsonError('Invalid email format', 422);
        }

        $result = Auth::login($email, $input['password']);
        jsonSuccess($result, 'Login successful');
        break;

    case 'DELETE':
        Auth::logout();
        break;

    case 'GET':
        try {
            $user = Auth::requireAuth();
            $roles = Auth::getUserRoles($user['id']);
            $permissions = Auth::getUserPermissions($user['id']);
            jsonSuccess([
                'user'        => $user,
                'roles'       => $roles,
                'permissions' => $permissions,
            ], 'Authenticated');
        } catch (\Exception $e) {
            jsonError('Not authenticated', 401);
        }
        break;

    default:
        jsonError('Method not allowed', 405);
}
