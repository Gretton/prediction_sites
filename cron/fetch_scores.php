<?php
$secretKey = 'pred-tz';
$providedKey = (PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['key'] ?? ''));
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}
require_once __DIR__ . '/../config.php';
$db = getDB();
if (!$db) { die(json_encode(['status' => 'error', 'message' => 'DB failed'])); }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['matches'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid JSON body — expected { matches: [...] }']));
}

$db->exec("CREATE TABLE IF NOT EXISTS `match_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `home_team` VARCHAR(255) NOT NULL,
    `away_team` VARCHAR(255) NOT NULL,
    `home_score` INT DEFAULT NULL,
    `away_score` INT DEFAULT NULL,
    `match_date` DATE NOT NULL,
    `league` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `first_seen_at` DATETIME DEFAULT NULL,
    INDEX `idx_match_date` (`match_date`),
    INDEX `idx_home_team` (`home_team`),
    INDEX `idx_away_team` (`away_team`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add column if missing (existing DB)
try { $db->exec("ALTER TABLE match_results ADD COLUMN first_seen_at DATETIME DEFAULT NULL AFTER created_at"); } catch (PDOException $e) {}

$today = date('Y-m-d');
$inserted = 0;
$skipped = 0;

function normalizeTeam($name) {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $name = preg_replace('/^(FC|CF|AC|SC|RC|SS|CD|AS|SK|FK|NK|UD|CD|CA|CR|EC|AA|AE|SSC|Real|Atletico)\s+/i', '', $name);
    $name = preg_replace('/\s+(FC|CF|AC|SC|RC|SS|CD|AS|SK|FK|NK|UD|CD|CA|CR|EC|AA|AE|SSC)$/i', '', $name);
    return trim(mb_strtolower($name));
}

$checkStmt = $db->prepare("SELECT id, home_score, away_score, first_seen_at FROM match_results WHERE home_team = ? AND away_team = ? AND match_date = ?");
$insertStmt = $db->prepare("INSERT INTO match_results (home_team, away_team, home_score, away_score, match_date, first_seen_at) VALUES (?, ?, ?, ?, ?, NOW())");
$updateStmt = $db->prepare("UPDATE match_results SET home_score = ?, away_score = ? WHERE id = ?");

foreach ($input['matches'] as $m) {
    $home = normalizeTeam($m['home_team'] ?? '');
    $away = normalizeTeam($m['away_team'] ?? '');
    $hs = (int)($m['home_score'] ?? 0);
    $as = (int)($m['away_score'] ?? 0);
    if (empty($home) || empty($away)) { $skipped++; continue; }

    $checkStmt->execute([$home, $away, $today]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        if ((int)$existing['home_score'] !== $hs || (int)$existing['away_score'] !== $as) {
            $updateStmt->execute([$hs, $as, $existing['id']]);
            $inserted++;
        } else {
            $skipped++;
        }
    } else {
        $insertStmt->execute([$home, $away, $hs, $as, $today]);
        $inserted++;
    }
}

echo json_encode(['status' => 'ok', 'inserted' => $inserted, 'skipped' => $skipped, 'date' => $today]);
