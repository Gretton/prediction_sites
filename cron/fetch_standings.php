<?php
$secretKey = 'pred-tz';
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['standings']) || !is_array($input['standings'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid JSON: expected standings array']));
}

$db = getDB();
if (!$db) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'DB failed']));
}

$db->exec("CREATE TABLE IF NOT EXISTS `league_standings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `league` VARCHAR(255) NOT NULL,
    `team` VARCHAR(255) NOT NULL,
    `position` INT NOT NULL DEFAULT 99,
    `played` INT DEFAULT 0,
    `wins` INT DEFAULT 0,
    `draws` INT DEFAULT 0,
    `losses` INT DEFAULT 0,
    `goals_for` INT DEFAULT 0,
    `goals_against` INT DEFAULT 0,
    `goal_diff` INT DEFAULT 0,
    `points` INT DEFAULT 0,
    `updated_at` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_standings_team_league_date` (`team`(100), `league`(100), `updated_at`),
    INDEX `idx_standings_league` (`league`(100)),
    INDEX `idx_standings_team` (`team`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$today = date('Y-m-d');
$inserted = 0;
$updated = 0;
$errors = 0;

$upsert = $db->prepare("
    INSERT INTO league_standings (league, team, position, played, wins, draws, losses, goals_for, goals_against, goal_diff, points, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        position = VALUES(position),
        played = VALUES(played),
        wins = VALUES(wins),
        draws = VALUES(draws),
        losses = VALUES(losses),
        goals_for = VALUES(goals_for),
        goals_against = VALUES(goals_against),
        goal_diff = VALUES(goal_diff),
        points = VALUES(points)
");

foreach ($input['standings'] as $entry) {
    if (empty($entry['team']) || empty($entry['league'])) {
        $errors++;
        continue;
    }
    try {
        $upsert->execute([
            $entry['league'],
            $entry['team'],
            (int)($entry['position'] ?? 99),
            (int)($entry['played'] ?? 0),
            (int)($entry['wins'] ?? 0),
            (int)($entry['draws'] ?? 0),
            (int)($entry['losses'] ?? 0),
            (int)($entry['goals_for'] ?? 0),
            (int)($entry['goals_against'] ?? 0),
            (int)($entry['goal_diff'] ?? 0),
            (int)($entry['points'] ?? 0),
            $today,
        ]);
        if ($upsert->rowCount() > 0) $inserted++; else $updated++;
    } catch (Exception $e) {
        $errors++;
        error_log("fetch_standings upsert error: " . $e->getMessage());
    }
}

echo json_encode([
    'status' => 'ok',
    'inserted' => $inserted,
    'updated' => $updated,
    'errors' => $errors,
    'total' => count($input['standings']),
]);
