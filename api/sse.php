<?php

declare(strict_types=1);

$_GET['token'] = $_GET['token'] ?? '';
require_once __DIR__ . '/../includes/bootstrap.php';

$user = Auth::requireAuth();
$storeId = $user['store_id'] ?? 'none';
$mutexFile = sys_get_temp_dir() . "/superma_mutex_{$storeId}";

// Ensure mutex file exists
if (!file_exists($mutexFile)) {
    file_put_contents($mutexFile, '0');
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');

// Disable output buffering
if (ob_get_level()) {
    ob_end_clean();
}
ob_implicit_flush(true);

$maxTime = 55; // stay connected ~55s before browser auto-reconnects
$startTime = time();
$lastMtime = filemtime($mutexFile);

// Send initial connected event
echo "event: connected\ndata: {}\n\n";

while (true) {
    // Check timeout
    if ((time() - $startTime) >= $maxTime) {
        echo "event: timeout\ndata: {}\n\n";
        break;
    }

    clearstatcache(true, $mutexFile);
    $currentMtime = filemtime($mutexFile);

    if ($currentMtime !== $lastMtime) {
        $lastMtime = $currentMtime;
        echo "event: refresh\ndata: " . json_encode(['time' => $currentMtime]) . "\n\n";
    }

    // Send keepalive every 10s
    if ((time() - $startTime) % 10 === 0) {
        echo ": keepalive\n\n";
    }

    sleep(2);
}
