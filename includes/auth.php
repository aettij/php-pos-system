<?php

declare(strict_types=1);

final class Auth
{
    private static ?array $currentUser = null;

    public static function login(string $email, string $password): array
    {
        $db = Database::connect();

        $stmt = $db->prepare('
            SELECT u.*, s.name AS store_name
            FROM users u
            LEFT JOIN stores s ON s.id = u.store_id
            WHERE u.email = :email AND u.is_active = TRUE
            LIMIT 1
        ');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !verifyPassword($password, $user['password_hash'])) {
            jsonError('Invalid email or password', 401);
        }

        $roles = self::getUserRoles($user['id']);
        $permissions = self::getUserPermissions($user['id']);

        $token = generateToken();
        $expiresAt = time() + (int)(getenv('SESSION_LIFETIME') ?: 7200);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['token'] = $token;
        $_SESSION['expires_at'] = $expiresAt;

        $stmt = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $user['id']]);

        unset($user['password_hash'], $user['pin_code']);

        return [
            'user'        => $user,
            'roles'       => $roles,
            'permissions' => $permissions,
            'token'       => $token,
            'expires_at'  => $expiresAt,
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        jsonSuccess(null, 'Logged out successfully');
    }

    public static function requireAuth(): array
    {
        $token = self::getBearerToken();

        if (!$token || !isset($_SESSION['user_id']) || $_SESSION['token'] !== $token) {
            jsonError('Unauthorized', 401);
        }

        if (isset($_SESSION['expires_at']) && time() > $_SESSION['expires_at']) {
            self::logout();
            jsonError('Session expired', 401);
        }

        if (self::$currentUser === null) {
            $db = Database::connect();
            $stmt = $db->prepare('
                SELECT u.*, s.name AS store_name
                FROM users u
                LEFT JOIN stores s ON s.id = u.store_id
                WHERE u.id = :id AND u.is_active = TRUE
                LIMIT 1
            ');
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user) {
                jsonError('User not found', 401);
            }

            unset($user['password_hash'], $user['pin_code']);
            self::$currentUser = $user;
        }

        $_SESSION['expires_at'] = time() + (int)(getenv('SESSION_LIFETIME') ?: 7200);

        return self::$currentUser;
    }

    public static function currentUser(): ?array
    {
        return self::$currentUser;
    }

    public static function requirePermission(string $permission): void
    {
        $user = self::requireAuth();
        $roles = self::getUserRoles($user['id']);

        if (in_array('admin', $roles, true)) {
            return;
        }

        $perms = self::getUserPermissions($user['id']);

        if (!in_array($permission, $perms, true)) {
            jsonError('Forbidden: missing permission "' . $permission . '"', 403);
        }
    }

    public static function requireRole(string $role): void
    {
        $user = self::requireAuth();
        $roles = self::getUserRoles($user['id']);

        if (!in_array($role, $roles, true)) {
            jsonError('Forbidden: missing role "' . $role . '"', 403);
        }
    }

    public static function getUserRoles(string $userId): array
    {
        $db = Database::connect();
        $stmt = $db->prepare('
            SELECT r.name
            FROM roles r
            JOIN user_roles ur ON ur.role_id = r.id
            WHERE ur.user_id = :user_id
        ');
        $stmt->execute([':user_id' => $userId]);
        return array_column($stmt->fetchAll(), 'name');
    }

    public static function getUserPermissions(string $userId): array
    {
        $db = Database::connect();
        $stmt = $db->prepare('
            SELECT DISTINCT p.code
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN user_roles ur ON ur.role_id = rp.role_id
            WHERE ur.user_id = :user_id
        ');
        $stmt->execute([':user_id' => $userId]);
        return array_column($stmt->fetchAll(), 'code');
    }

    private static function getBearerToken(): ?string
    {
        $headers = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $reqHeaders = apache_request_headers();
            $headers = $reqHeaders['Authorization'] ?? '';
        }

        if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }

        // Fallback: token in query string (used by SSE)
        if (!empty($_GET['token'])) {
            return $_GET['token'];
        }

        return null;
    }
}
