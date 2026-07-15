<?php
/**
 * Multi-Source Intersection Pipeline — reads pre-computed JSON from Node.js scrapers
 *
 * The Node.js scrapers (cron/scrapers/run_all.js) fetch and normalize picks from
 * multiple prediction sites, compute intersections, and write the result to
 * cron/scrapers/output/picks.json. This script reads that file and writes
 * qualifying intersection picks into admin_featured_picks (Top Picks).
 *
 * Sites that haven't updated yet return empty result sets gracefully.
 *
 * Usage: php cron/build_intersection.php key=pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580
 *   OR   https://predixa.co.tz/cron/build_intersection.php?key=pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580
 */

$startTime = microtime(true);
$isCLI = php_sapi_name() === 'cli';

// --- Auth ---
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = $isCLI ? ($argv[1] ?? '') : ($_GET['key'] ?? '');
if ($providedKey !== $secretKey) {
    die("Access denied. Provide ?key=pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580\n");
}

// --- Bootstrap ---
require_once __DIR__ . '/../config.php';

$db = getDB();
if (!$db) { die("DB connection failed\n"); }

// Ensure intersection_sites column exists
try { $db->exec("ALTER TABLE admin_featured_picks ADD COLUMN intersection_sites INT DEFAULT NULL AFTER admin_id"); } catch (Exception $e) {}

$log = [];

// --- Read pre-computed JSON ---
$jsonPath = __DIR__ . '/scrapers/output/picks.json';
if (!file_exists($jsonPath)) {
    die("picks.json not found. Run 'npm run scrape' in cron/scrapers/ first.\n");
}

$jsonData = json_decode(file_get_contents($jsonPath), true);
if (!$jsonData || !isset($jsonData['intersections'])) {
    die("Invalid picks.json format.\n");
}

$intersections = $jsonData['intersections'];
$sourceStatuses = $jsonData['sources'] ?? [];

echo "[" . date('H:i:s') . "] Read picks.json generated at: " . ($jsonData['generated_at'] ?? 'unknown') . "\n";
echo "  Sources: " . count($sourceStatuses) . "\n";
foreach ($sourceStatuses as $name => $st) {
    $log[] = "$name={$st['count']}";
    echo "  $name: {$st['count']} picks ({$st['status']})\n";
}
echo "[" . date('H:i:s') . "] Intersections found: " . count($intersections) . "\n";

// --- Write into admin_featured_picks ---
echo "[" . date('H:i:s') . "] Writing to admin_featured_picks...\n";

$inserted = 0;
$skipped = 0;
$today = date('Y-m-d');

foreach ($intersections as $ix) {
    // Check if already featured today
    $check = $db->prepare("SELECT COUNT(*) FROM admin_featured_picks WHERE match_name = ? AND pick_value = ? AND DATE(created_at) = ? AND admin_id = 0");
    $check->execute([$ix['match'], $ix['pick'], $today]);
    if ($check->fetchColumn() > 0) {
        // Update intersection_sites count
        $sc = $ix['source_count'] ?? 0;
        if ($sc > 0) {
            try {
                $db->prepare("UPDATE admin_featured_picks SET intersection_sites = ? WHERE match_name = ? AND pick_value = ? AND DATE(created_at) = ? AND admin_id = 0 AND intersection_sites < ?")
                    ->execute([$sc, $ix['match'], $ix['pick'], $today, $sc]);
            } catch (Exception $e) {}
        }
        $skipped++;
        continue;
    }

    // Skip if match already has a result (already played)
    if (preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $ix['match'], $m)) {
        $playedCheck = $db->prepare("SELECT 1 FROM match_results WHERE home_team = ? AND away_team = ? AND match_date < CURDATE() LIMIT 1");
        $playedCheck->execute([trim($m[1]), trim($m[2])]);
        if ($playedCheck->fetchColumn()) { $skipped++; continue; }
        // Check reverse team order
        $playedCheck->execute([trim($m[2]), trim($m[1])]);
        if ($playedCheck->fetchColumn()) { $skipped++; continue; }
    }

    $odds = is_numeric($ix['odds'] ?? '') ? (float)$ix['odds'] : 0;

    try {
        $ins = $db->prepare("INSERT INTO admin_featured_picks (web_pick_id, match_name, pick_value, odds, league, match_time, admin_id, intersection_sites) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([0, $ix['match'], $ix['pick'], $odds, $ix['league'] ?: 'Intersection Pick', $ix['time'] ?: date('Y-m-d H:i:s'), 0, $ix['source_count'] ?? 0]);
        $inserted++;
    } catch (Exception $e) {
        echo "  INSERT ERROR: " . $e->getMessage() . "\n";
    }
}

// --- Clean up old intersection entries (keep only today's) ---
try {
    $db->exec("DELETE FROM admin_featured_picks WHERE admin_id = 0 AND intersection_sites IS NOT NULL AND DATE(created_at) < '$today'");
} catch (Exception $e) {}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\n[" . date('H:i:s') . "] Done in {$elapsed}s\n";
echo "  Inserted: $inserted\n";
echo "  Skipped (already exists): $skipped\n";
echo "  Sources: " . implode(', ', $log) . "\n";
echo "  Total intersections: " . count($intersections) . "\n";
