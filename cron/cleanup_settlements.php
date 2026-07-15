<?php
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = (PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['key'] ?? ''));
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}
require_once __DIR__ . '/../config.php';
$db = getDB();
if (!$db) { die(json_encode(['status' => 'error', 'message' => 'DB failed'])); }

$output = [];

// 1. Delete pending entries older than 3 days (stale, will be re-inserted if match settles)
$del = $db->exec("DELETE FROM pick_settlements WHERE result = 'pending' AND settlement_date < CURDATE() - INTERVAL 3 DAY");
$output['deleted_stale_pending'] = $del;

// 2. Delete duplicate match_results (keep earliest entry per unique match/date)
$dupes = $db->exec("DELETE mr1 FROM match_results mr1
    INNER JOIN match_results mr2
    WHERE mr1.id > mr2.id
    AND mr1.home_team = mr2.home_team
    AND mr1.away_team = mr2.away_team
    AND mr1.match_date = mr2.match_date");
$output['deleted_duplicate_scores'] = $dupes;

echo json_encode(['status' => 'ok', 'date' => date('Y-m-d H:i:s'), 'actions' => $output]);
