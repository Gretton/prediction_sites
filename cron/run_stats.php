<?php
require_once __DIR__ . '/../config.php';

$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== STATS_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit(1);
}

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1;

// Run collector inline (nohup doesn't work on shared hosting)
$cmd = 'php ' . __DIR__ . '/collect_match_stats.php --date ' . escapeshellarg($date) . ' --limit ' . (int)$limit;
$output = shell_exec($cmd . ' 2>&1');

header('Content-Type: application/json');
echo json_encode([
    'status' => 'completed',
    'date' => $date,
    'limit' => $limit,
    'output' => trim($output),
]);
