<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

// Check admin via DB
$db = Database::connect();
$roleStmt = $db->prepare('
    SELECT r.name FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = :uid
');
$roleStmt->execute([':uid' => $user['id']]);
$userRoles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
$isAdmin = in_array('admin', $userRoles, true);

switch ($method) {
    case 'GET':
        if (!$isAdmin) {
            jsonError('Forbidden', 403);
        }

        $action = $_GET['action'] ?? 'list';

        if ($action === 'channels') {
            jsonSuccess(['channels' => Logger::getChannels()]);
            break;
        }

        if ($action === 'dates') {
            $channel = sanitizeString($_GET['channel'] ?? 'app');
            jsonSuccess(['dates' => Logger::getLogDates($channel)]);
            break;
        }

        // Default: read log lines
        $channel = sanitizeString($_GET['channel'] ?? 'app');
        $date = sanitizeString($_GET['date'] ?? date('Y-m-d'));
        $level = sanitizeString($_GET['level'] ?? '');
        $search = sanitizeString($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(500, max(10, (int)($_GET['per_page'] ?? 100)));

        $lines = Logger::getLogFiles($channel, $date);

        // Filter by level
        if ($level) {
            $lines = array_filter($lines, fn($l) => stripos($l['level'], $level) !== false);
        }

        // Filter by search
        if ($search) {
            $lines = array_filter($lines, fn($l) => stripos($l['message'], $search) !== false);
        }

        // Reverse (newest first)
        $lines = array_reverse(array_values($lines));

        $total = count($lines);
        $offset = ($page - 1) * $perPage;
        $pageLines = array_slice($lines, $offset, $perPage);

        jsonSuccess([
            'logs'       => $pageLines,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ]);
        break;

    case 'POST':
        // Frontend error logging (any authenticated user)
        $input = getJsonInput();
        $level = sanitizeString($input['level'] ?? 'ERROR');
        $message = sanitizeString($input['message'] ?? '');
        $context = $input['context'] ?? null;

        if (empty($message)) {
            jsonError('Message required', 400);
        }

        // Sanitize context to avoid leaking sensitive data
        if ($context && is_array($context)) {
            $safe = ['url' => $context['url'] ?? '', 'line' => $context['line'] ?? '', 'col' => $context['col'] ?? ''];
            if (!empty($context['stack'])) $safe['stack'] = substr($context['stack'], 0, 1000);
            $context = $safe;
        }

        Logger::write($level, '[FE] ' . $message, $context, 'frontend');
        jsonSuccess(null, 'Logged');
        break;

    default:
        jsonError('Method not allowed', 405);
}
