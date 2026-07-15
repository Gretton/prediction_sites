<?php
$isCLI = php_sapi_name() === 'cli';
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = $isCLI ? ($argv[1] ?? '') : ($_GET['key'] ?? '');

if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

require_once __DIR__ . '/../config.php';
$db = getDB();
if (!$db) { die(json_encode(['status' => 'error', 'message' => 'DB failed'])); }

// Ensure scraper_results table exists
$db->exec("CREATE TABLE IF NOT EXISTS `scraper_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `match_name` VARCHAR(255) NOT NULL,
    `pick_value` VARCHAR(100) NOT NULL,
    `source_count` INT NOT NULL DEFAULT 0,
    `league` VARCHAR(255) DEFAULT '',
    `match_time` VARCHAR(100) DEFAULT '',
    `odds` DECIMAL(10,2) DEFAULT 0,
    `detected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_scraper_pick` (`match_name`(100), `pick_value`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Clean up old-style intersection records (previous format stored in admin_featured_picks with intersection_sites column)
try { $db->exec("DELETE FROM admin_featured_picks WHERE admin_id = 0 AND intersection_sites IS NOT NULL"); } catch (Exception $e) {}
// Also remove the now-unused column if it exists
try { $db->exec("ALTER TABLE admin_featured_picks DROP COLUMN intersection_sites"); } catch (Exception $e) {}

// Read JSON body
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'No JSON body']));
}

$data = json_decode($raw, true);
if (!$data || !isset($data['intersections'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid JSON — missing intersections']));
}

$intersections = $data['intersections'];
$today = date('Y-m-d');
$inserted = 0;
$skipped = 0;
$scraperPickId = 0;

foreach ($intersections as $ix) {
    $match  = $ix['match'] ?? '';
    $pick   = $ix['pick'] ?? '';
    $sCount = $ix['source_count'] ?? 0;
    $league = $ix['league'] ?? 'Intersection Pick';
    $time   = $ix['time'] ?? 'TBD';
    $odds   = is_numeric($ix['odds'] ?? '') ? (float)$ix['odds'] : 0;

    if (!$match || !$pick) continue;

    // Always store raw scraper data for counting (upsert by match+picks+date)
    try {
        $db->prepare("INSERT INTO scraper_results (match_name, pick_value, source_count, league, match_time, odds) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE source_count = GREATEST(source_count, ?), odds = ?")
            ->execute([$match, $pick, $sCount, $league, $time, $odds, $sCount, $odds]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'scraper_results: ' . $e->getMessage()]) . "\n";
        continue;
    }

    // Check if this pick already exists in dashboard (any admin_id — admin, odds-signals, or intersection)
    $check = $db->prepare("SELECT COUNT(*) FROM admin_featured_picks WHERE match_name = ? AND pick_value = ? AND DATE(created_at) = ?");
    $check->execute([$match, $pick, $today]);

    if ($check->fetchColumn() > 0) {
        $skipped++;
        continue;
    }

    // Skip if match already has a result (already played)
    if (preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $match, $m)) {
        $playedCheck = $db->prepare("SELECT 1 FROM match_results WHERE home_team = ? AND away_team = ? AND match_date < CURDATE() LIMIT 1");
        $playedCheck->execute([trim($m[1]), trim($m[2])]);
        if ($playedCheck->fetchColumn()) { $skipped++; continue; }
        $playedCheck->execute([trim($m[2]), trim($m[1])]);
        if ($playedCheck->fetchColumn()) { $skipped++; continue; }
    }

    // New pick — insert as intersection record with unique negative web_pick_id to avoid uniq_pick_admin collision
    try {
        $scraperPickId--;
        $db->prepare("INSERT INTO admin_featured_picks (web_pick_id, match_name, pick_value, odds, league, match_time, admin_id) VALUES (?, ?, ?, ?, ?, ?, 0)")
            ->execute([$scraperPickId, $match, $pick, $odds, $league, $time]);
        $inserted++;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'INSERT: ' . $e->getMessage()]) . "\n";
    }
}

echo json_encode([
    'status' => 'ok',
    'inserted' => $inserted,
    'skipped' => $skipped,
    'total_intersections' => count($intersections),
    'sources' => $data['sources'] ?? [],
    'generated_at' => $data['generated_at'] ?? null,
]) . "\n";
