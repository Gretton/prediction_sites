<?php
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['predictions']) || !is_array($input['predictions'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid JSON: expected predictions array']));
}

$db = getDB();
if (!$db) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'DB failed']));
}

$db->exec("CREATE TABLE IF NOT EXISTS `ml_predictions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `match_name` VARCHAR(255) NOT NULL,
    `home_team` VARCHAR(255) NOT NULL,
    `away_team` VARCHAR(255) NOT NULL,
    `league` VARCHAR(255) DEFAULT NULL,
    `match_date` DATE NOT NULL,
    `prob_1` DECIMAL(5,2) DEFAULT NULL,
    `prob_x` DECIMAL(5,2) DEFAULT NULL,
    `prob_2` DECIMAL(5,2) DEFAULT NULL,
    `prob_1x` DECIMAL(5,2) DEFAULT NULL,
    `prob_x2` DECIMAL(5,2) DEFAULT NULL,
    `prob_12` DECIMAL(5,2) DEFAULT NULL,
    `over_25` DECIMAL(5,2) DEFAULT NULL,
    `under_25` DECIMAL(5,2) DEFAULT NULL,
    `btts_yes` DECIMAL(5,2) DEFAULT NULL,
    `btts_no` DECIMAL(5,2) DEFAULT NULL,
    `expected_goals` DECIMAL(4,2) DEFAULT NULL,
    `confidence` DECIMAL(5,2) DEFAULT NULL,
    `recommended_pick` VARCHAR(100) DEFAULT NULL,
    `home_score` INT DEFAULT NULL,
    `away_score` INT DEFAULT NULL,
    `result` ENUM('pending','correct','incorrect','void') NOT NULL DEFAULT 'pending',
    `settled_at` DATETIME DEFAULT NULL,
    `model_name` VARCHAR(100) DEFAULT 'xgboost',
    `model_version` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_match_date` (`match_date`),
    INDEX `idx_result` (`result`),
    INDEX `idx_league` (`league`),
    INDEX `idx_match_name` (`match_name`),
    UNIQUE KEY `uq_ml_match` (`match_name`(100), `match_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$modelName = $input['model_name'] ?? 'xgboost';
$modelVersion = $input['model_version'] ?? date('Y-m-d');
$today = date('Y-m-d');
$inserted = 0;
$updated = 0;
$errors = 0;

$upsert = $db->prepare("
    INSERT INTO ml_predictions (match_name, home_team, away_team, league, match_date,
        prob_1, prob_x, prob_2, prob_1x, prob_x2, prob_12,
        over_25, under_25, btts_yes, btts_no, expected_goals,
        confidence, recommended_pick, model_name, model_version)
    VALUES (?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        prob_1 = VALUES(prob_1), prob_x = VALUES(prob_x), prob_2 = VALUES(prob_2),
        prob_1x = VALUES(prob_1x), prob_x2 = VALUES(prob_x2), prob_12 = VALUES(prob_12),
        over_25 = VALUES(over_25), under_25 = VALUES(under_25),
        btts_yes = VALUES(btts_yes), btts_no = VALUES(btts_no),
        expected_goals = VALUES(expected_goals), confidence = VALUES(confidence),
        recommended_pick = VALUES(recommended_pick),
        model_name = VALUES(model_name), model_version = VALUES(model_version)
");

foreach ($input['predictions'] as $pred) {
    if (empty($pred['match_name'])) { $errors++; continue; }
    $parts = preg_split('/\s+vs\.?\s+/i', trim($pred['match_name']), 2);
    $homeTeam = $pred['home_team'] ?? ($parts[0] ?? '');
    $awayTeam = $pred['away_team'] ?? ($parts[1] ?? '');
    try {
        $upsert->execute([
            $pred['match_name'], $homeTeam, $awayTeam,
            $pred['league'] ?? null, $pred['match_date'] ?? $today,
            $pred['prob_1'] ?? null, $pred['prob_x'] ?? null, $pred['prob_2'] ?? null,
            $pred['prob_1x'] ?? null, $pred['prob_x2'] ?? null, $pred['prob_12'] ?? null,
            $pred['over_25'] ?? null, $pred['under_25'] ?? null,
            $pred['btts_yes'] ?? null, $pred['btts_no'] ?? null,
            $pred['expected_goals'] ?? null,
            $pred['confidence'] ?? null,
            $pred['recommended_pick'] ?? null,
            $modelName, $modelVersion,
        ]);
        if ($upsert->rowCount() > 0) $inserted++; else $updated++;
    } catch (Exception $e) {
        $errors++;
        error_log("fetch_ml_predictions upsert error: " . $e->getMessage());
    }
}

echo json_encode([
    'status' => 'ok',
    'inserted' => $inserted,
    'updated' => $updated,
    'errors' => $errors,
    'total' => count($input['predictions']),
    'model_name' => $modelName,
    'model_version' => $modelVersion,
]);
