<?php
/**
 * cron/import_predictions.php — HTTP endpoint to receive scraped predictions
 * from external Node.js/Puppeteer scraper and compute intersection.
 *
 * External usage:
 *   node cron/scrape_predictions.js | curl -X POST -d @- https://predixa.co.tz/cron/import_predictions.php?key=pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580
 *
 * Or from a file:
 *   node cron/scrape_predictions.js > predictions.json
 *   curl -X POST --data-binary @predictions.json https://predixa.co.tz/cron/import_predictions.php?key=pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580
 *
 * Accommodates site delays: data accumulates in scraped_predictions staging
 * table across multiple runs; intersection fires when >= 3 sources agree.
 */

require_once __DIR__ . '/../config.php';

$secretKey = defined('CRON_SECRET') ? CRON_SECRET : 'change-me';
if (!isset($_GET['key']) || $_GET['key'] !== $secretKey) {
    http_response_code(403);
    die('Forbidden');
}

$db = getDB();
if (!$db) { http_response_code(500); die('DB connection failed'); }

header('Content-Type: text/plain');

$input = file_get_contents('php://input');
if (empty($input)) die("No input received\n");

$data = json_decode($input, true);
if (!$data || !isset($data['sources']) || !is_array($data['sources'])) {
    die("Invalid JSON: expected { sources: { sourceName: [picks...] } }\n");
}

// --- Create staging table ---
$db->exec("CREATE TABLE IF NOT EXISTS `scraped_predictions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `source` VARCHAR(50) NOT NULL,
    `match_name` VARCHAR(255) NOT NULL,
    `home_team` VARCHAR(255) NOT NULL,
    `away_team` VARCHAR(255) NOT NULL,
    `pick_value` VARCHAR(100) NOT NULL,
    `odds` VARCHAR(20) DEFAULT '',
    `league` VARCHAR(255) DEFAULT '',
    `match_time` VARCHAR(20) DEFAULT '',
    `pick_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_source` (`source`),
    INDEX `idx_pick_date` (`pick_date`),
    INDEX `idx_match` (`match_name`, `pick_value`),
    UNIQUE KEY `uq_source_match` (`source`, `match_name`, `pick_value`, `pick_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure intersection_sites column exists on admin_featured_picks
try { $db->exec("ALTER TABLE admin_featured_picks ADD COLUMN intersection_sites INT DEFAULT NULL AFTER admin_id"); } catch (Exception $e) {}

$today = date('Y-m-d');
$totalInserted = 0;
$totalSkipped = 0;
$processedSources = [];

// --- Insert or update each source's picks ---
foreach ($data['sources'] as $sourceName => $picks) {
    if (empty($picks) || !is_array($picks)) {
        echo "[$sourceName] 0 picks (empty)\n";
        continue;
    }

    $sourceKey = preg_replace('/[^a-z0-9_]/i', '_', $sourceName);
    $inserted = 0;
    $skipped = 0;

    $checkStmt = $db->prepare("SELECT COUNT(*) FROM scraped_predictions WHERE source = ? AND match_name = ? AND pick_value = ? AND pick_date = ?");
    $insertStmt = $db->prepare("INSERT INTO scraped_predictions (source, match_name, home_team, away_team, pick_value, odds, league, match_time, pick_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($picks as $pick) {
        $home = trim($pick['home'] ?? '');
        $away = trim($pick['away'] ?? '');
        $matchName = trim($pick['match'] ?? "$home vs $away");
        $pickVal = trim($pick['pick'] ?? '');
        $odds = trim($pick['odds'] ?? '');
        $league = trim($pick['league'] ?? '');
        $time = trim($pick['time'] ?? '');

        if (!$home || !$away || !$pickVal) continue;

        // Skip if match already has a result (already played)
        if (preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $matchName, $m)) {
            $playedCheck = $db->prepare("SELECT 1 FROM match_results WHERE home_team = ? AND away_team = ? AND match_date < CURDATE() LIMIT 1");
            $playedCheck->execute([trim($m[1]), trim($m[2])]);
            if ($playedCheck->fetchColumn()) { $skipped++; continue; }
            $playedCheck->execute([trim($m[2]), trim($m[1])]);
            if ($playedCheck->fetchColumn()) { $skipped++; continue; }
        }

        // Dedup
        $checkStmt->execute([$sourceKey, $matchName, $pickVal, $today]);
        if ($checkStmt->fetchColumn() > 0) { $skipped++; continue; }

        $insertStmt->execute([$sourceKey, $matchName, $home, $away, $pickVal, $odds, $league, $time, $today]);
        $inserted++;
    }

    echo "[$sourceKey] $inserted inserted, $skipped skipped\n";
    $totalInserted += $inserted;
    $totalSkipped += $skipped;
    $processedSources[] = "$sourceKey=$inserted";
}

echo "\n--- Computing intersection ---\n";

// Read all staging data for today
$allStaging = $db->prepare("SELECT * FROM scraped_predictions WHERE pick_date = ?");
$allStaging->execute([$today]);
$rows = $allStaging->fetchAll(PDO::FETCH_ASSOC);

echo "Total staging rows for today: " . count($rows) . "\n";

// Group by normalized match + pick
require_once __DIR__ . '/sources/normalizer.php';

$groups = []; // key => [match_name, home, away, pick, sources[]]
foreach ($rows as $r) {
    $homeN = normalize_team($r['home_team']);
    $awayN = normalize_team($r['away_team']);
    $teams = [$homeN, $awayN];
    sort($teams);
    $matchKey = implode('||', $teams) . '|' . $r['pick_value'];

    if (!isset($groups[$matchKey])) {
        $groups[$matchKey] = [
            'match_name' => $r['match_name'],
            'home' => $r['home_team'],
            'away' => $r['away_team'],
            'home_n' => $homeN,
            'away_n' => $awayN,
            'pick' => $r['pick_value'],
            'sources' => [],
            'source_count' => 0,
            'odds' => $r['odds'],
            'league' => $r['league'],
            'time' => $r['match_time'],
        ];
    }

    if (!in_array($r['source'], $groups[$matchKey]['sources'])) {
        $groups[$matchKey]['sources'][] = $r['source'];
        $groups[$matchKey]['source_count']++;
    }

    // Keep best odds
    if (is_numeric($r['odds']) && (!is_numeric($groups[$matchKey]['odds']) || (float)$r['odds'] > (float)$groups[$matchKey]['odds'])) {
        $groups[$matchKey]['odds'] = $r['odds'];
    }
}

$MIN_AGREEMENT = 3;
$intersections = [];

foreach ($groups as $key => $g) {
    if ($g['source_count'] >= $MIN_AGREEMENT) {
        $intersections[] = $g;
    }
}

// Sort by source_count descending
usort($intersections, fn($a, $b) => $b['source_count'] - $a['source_count']);

echo "Intersections found (>= $MIN_AGREEMENT sources): " . count($intersections) . "\n";

// --- Write to admin_featured_picks ---
$inserted = 0;
$skipped = 0;

foreach ($intersections as $ix) {
    // Check if already exists today
    $checkFeatured = $db->prepare("SELECT COUNT(*) FROM admin_featured_picks WHERE match_name = ? AND pick_value = ? AND DATE(created_at) = ? AND admin_id = 0");
    $checkFeatured->execute([$ix['match_name'], $ix['pick'], $today]);
    if ($checkFeatured->fetchColumn() > 0) {
        // Update intersection_sites if higher count
        try {
            $db->prepare("UPDATE admin_featured_picks SET intersection_sites = ? WHERE match_name = ? AND pick_value = ? AND DATE(created_at) = ? AND admin_id = 0 AND (intersection_sites IS NULL OR intersection_sites < ?)")
                ->execute([$ix['source_count'], $ix['match_name'], $ix['pick'], $today, $ix['source_count']]);
        } catch (Exception $e) {}
        $skipped++;
        continue;
    }

    // Skip if match already has a result (already played)
    if (preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $ix['match_name'], $m)) {
        $playedCheck = $db->prepare("SELECT 1 FROM match_results WHERE home_team = ? AND away_team = ? AND match_date < CURDATE() LIMIT 1");
        $playedCheck->execute([trim($m[1]), trim($m[2])]);
        if ($playedCheck->fetchColumn()) { $skipped++; continue; }
        $playedCheck->execute([trim($m[2]), trim($m[1])]);
        if ($playedCheck->fetchColumn()) { $skipped++; continue; }
    }

    $odds = is_numeric($ix['odds']) ? (float)$ix['odds'] : 0;

    try {
        $ins = $db->prepare("INSERT INTO admin_featured_picks (web_pick_id, match_name, pick_value, odds, league, match_time, admin_id, intersection_sites) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([0, $ix['match_name'], $ix['pick'], $odds, $ix['league'] ?: 'Intersection Pick', $ix['time'] ?: date('Y-m-d H:i:s'), 0, $ix['source_count']]);
        $inserted++;
        echo "  FEATURED: {$ix['match_name']} -> {$ix['pick']} ({$ix['source_count']} sources)\n";
    } catch (Exception $e) {
        echo "  INSERT ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nFeatured picks: $inserted inserted, $skipped skipped\n";
echo "Sources: " . implode(', ', $processedSources) . "\n";
echo "Done.\n";
