<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

$db = Database::connect();

switch ($method) {
    case 'GET':
        if (!empty($_GET['with_permissions'])) {
            $stmt = $db->query('
                SELECT r.*, p.code AS permission_code, p.module, p.description AS perm_description
                FROM roles r
                LEFT JOIN role_permissions rp ON rp.role_id = r.id
                LEFT JOIN permissions p ON p.id = rp.permission_id
                ORDER BY r.name, p.module
            ');
            $rows = $stmt->fetchAll();

            $roles = [];
            foreach ($rows as $row) {
                $rid = $row['id'];
                if (!isset($roles[$rid])) {
                    $roles[$rid] = [
                        'id'          => $rid,
                        'name'        => $row['name'],
                        'description' => $row['description'],
                        'permissions' => [],
                    ];
                }
                if ($row['permission_code']) {
                    $roles[$rid]['permissions'][] = [
                        'code'        => $row['permission_code'],
                        'module'      => $row['module'],
                        'description' => $row['perm_description'],
                    ];
                }
            }

            jsonSuccess(['roles' => array_values($roles)]);
        }

        $stmt = $db->query('SELECT * FROM roles ORDER BY name');
        jsonSuccess(['roles' => $stmt->fetchAll()]);
        break;

    default:
        jsonError('Method not allowed', 405);
}
