<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$storeId = $user['store_id'] ?? 'none';
$mutexFile = sys_get_temp_dir() . "/superma_mutex_{$storeId}";

file_put_contents($mutexFile, (string)time());

jsonSuccess(['ok' => true]);
