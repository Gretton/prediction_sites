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

$db->exec("CREATE TABLE IF NOT EXISTS `pick_settlements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `web_pick_id` INT NOT NULL,
    `match_name` VARCHAR(255) NOT NULL,
    `pick_value` VARCHAR(100) NOT NULL,
    `odds` DECIMAL(6,2) DEFAULT NULL,
    `home_score` INT DEFAULT NULL,
    `away_score` INT DEFAULT NULL,
    `result` ENUM('won','lost','pending','void') NOT NULL DEFAULT 'pending',
    `settlement_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_pick_match` (`web_pick_id`, `settlement_date`),
    INDEX `idx_result` (`result`),
    INDEX `idx_date` (`settlement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$today = date('Y-m-d');
$inserted = 0;
$skipped = 0;

// Use different ID ranges to avoid collisions:
// web_picks IDs: use as-is (positive ints)
// admin_featured_picks IDs: offset by 1_000_000_000
$AFP_OFFSET = 1000000000;

// Record today's picks from web_picks
$stmt = $db->prepare("SELECT wp.id, wp.match_name, wp.pick_value, wp.odds FROM web_picks wp
    LEFT JOIN pick_settlements ps ON wp.id = ps.web_pick_id AND ps.settlement_date = ?
    WHERE DATE(wp.detected_at) = ? AND ps.id IS NULL AND wp.pick_type IN ('rollover','parlay','over_15')");
$stmt->execute([$today, $today]);
foreach ($stmt->fetchAll() as $p) {
    $db->prepare("INSERT IGNORE INTO pick_settlements (web_pick_id, match_name, pick_value, odds, settlement_date) VALUES (?, ?, ?, ?, ?)")
        ->execute([$p['id'], $p['match_name'], $p['pick_value'], $p['odds'], $today]);
    $inserted++;
}

// Record today's picks from admin_featured_picks (odds-signals + scraper intersections)
$stmt2 = $db->prepare("SELECT afp.id, afp.match_name, afp.pick_value, afp.odds FROM admin_featured_picks afp
    LEFT JOIN pick_settlements ps ON (afp.id + ?) = ps.web_pick_id AND ps.settlement_date = ?
    WHERE DATE(afp.created_at) = CURDATE() AND ps.id IS NULL");
$stmt2->execute([$AFP_OFFSET, $today]);
foreach ($stmt2->fetchAll() as $p) {
    $db->prepare("INSERT IGNORE INTO pick_settlements (web_pick_id, match_name, pick_value, odds, settlement_date) VALUES (?, ?, ?, ?, ?)")
        ->execute([$p['id'] + $AFP_OFFSET, $p['match_name'], $p['pick_value'], $p['odds'], $today]);
    $inserted++;
}

echo json_encode(['status' => 'ok', 'inserted' => $inserted, 'skipped' => $skipped]);
