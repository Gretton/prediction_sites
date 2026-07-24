<?php
require_once __DIR__ . '/../config.php';

$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== STATS_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit(1);
}

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 9999;

// Pass params to collect_match_stats.php via global (shell_exec blocked on Namecheap)
$GLOBALS['_collector_opts'] = [];
$GLOBALS['_collector_opts']['date'] = $date;
$GLOBALS['_collector_opts']['limit'] = (string)$limit;
if (isset($_GET['test'])) $GLOBALS['_collector_opts']['test'] = true;

ob_start();
include __DIR__ . '/collect_match_stats.php';
$output = ob_get_clean();

header('Content-Type: application/json');
echo json_encode([
    'status' => 'completed',
    'date' => $date,
    'limit' => $limit,
    'output' => trim($output),
]);
