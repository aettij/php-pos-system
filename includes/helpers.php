<?php

declare(strict_types=1);

function jsonResponse(mixed $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $status = 400, ?array $errors = null): void
{
    $response = ['success' => false, 'message' => $message];
    if ($errors !== null) {
        $response['errors'] = $errors;
    }
    jsonResponse($response, $status);
}

function jsonSuccess(mixed $data = null, string $message = 'Success', int $status = 200): void
{
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    jsonResponse($response, $status);
}

function getJsonInput(): array
{
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON input', 400);
    }
    return $data ?? [];
}

function sanitizeString(string $value): string
{
    return trim(strip_tags($value));
}

function sanitizeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sanitizeEmail(string $email): string
{
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return trim($email);
}

function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateUuid(string $uuid): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
}

function validateRequired(array $data, array $fields, string $prefix = ''): ?array
{
    $errors = [];
    foreach ($fields as $field) {
        $key = $prefix ? $prefix . '.' . $field : $field;
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $errors[$key] = "The field '$field' is required.";
        }
    }
    return empty($errors) ? null : $errors;
}

function generateToken(int $length = 64): string
{
    return bin2hex(random_bytes($length));
}

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function getPaginationParams(): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(500, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    return [$page, $perPage, $offset];
}

function getSearchTerm(): string
{
    return trim($_GET['search'] ?? '');
}

function buildPaginationMeta(int $total, int $page, int $perPage): array
{
    return [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ];
}

function getSortParams(array $allowedColumns = ['created_at']): string
{
    $sort = $_GET['sort'] ?? 'created_at';
    $dir = strtoupper($_GET['dir'] ?? 'DESC');

    if (!in_array($sort, $allowedColumns, true)) {
        $sort = 'created_at';
    }
    if (!in_array($dir, ['ASC', 'DESC'], true)) {
        $dir = 'DESC';
    }

    return "$sort $dir";
}
