<?php
require_once __DIR__ . '/../config.php';

$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== STATS_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit(1);
}

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

header('Content-Type: application/json');
echo json_encode([
    'status' => 'started',
    'date' => $date,
    'limit' => $limit,
]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

$cmd = 'php ' . __DIR__ . '/collect_match_stats.php --date ' . escapeshellarg($date);
if ($limit) $cmd .= ' --limit ' . (int)$limit;

$logFile = __DIR__ . '/../logs/stats_collector_' . $date . '.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

ignore_user_abort(true);

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $bgCmd = 'start /B cmd /c "' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1"';
    exec($bgCmd);
} else {
    $bgCmd = 'nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    exec($bgCmd);
}
