<?php
/**
 * One-time migration: normalizes team names across all tables.
 *
 * 1. Extracts all unique team names from match_results
 * 2. Normalizes them, groups duplicates, populates `teams` table
 * 3. Adds home_team_id / away_team_id to match_results, backfills
 * 4. Adds team_id to league_standings, backfills
 * 5. Creates missing team entries for league_standings teams
 *
 * Usage: php cron/normalize_teams.php pred-tz
 *   or via HTTP: https://predixa.co.tz/cron/normalize_teams.php?key=pred-tz
 */

$isCLI = php_sapi_name() === 'cli';
$secretKey = 'pred-tz';
$providedKey = $isCLI ? ($argv[1] ?? '') : ($_GET['key'] ?? '');
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

require_once __DIR__ . '/../config.php';

$db = getDB();
if (!$db) { die(json_encode(['status' => 'error', 'message' => 'DB failed'])); }

$log = [];
$start = microtime(true);

// --- Step 1: Create teams table ---
$db->exec("CREATE TABLE IF NOT EXISTS `teams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `normalized_name` VARCHAR(255) NOT NULL,
    `aliases` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_teams_normalized` (`normalized_name`(100)),
    INDEX `idx_teams_name` (`name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$log[] = "Teams table OK";

// --- Step 2: Extract all unique team names ---
$allNames = $db->query("
    SELECT DISTINCT home_team as name FROM match_results
    UNION
    SELECT DISTINCT away_team FROM match_results
    UNION
    SELECT DISTINCT team FROM league_standings
    ORDER BY name
")->fetchAll(PDO::FETCH_COLUMN);
$log[] = "Found " . count($allNames) . " unique team names";

// Group by normalized name
$groups = [];
foreach ($allNames as $name) {
    if (empty(trim($name))) continue;
    $norm = normalizeTeamName($name);
    $groups[$norm][] = $name;
}

// --- Step 3: Populate teams table ---
$insertTeam = $db->prepare("
    INSERT INTO teams (name, normalized_name, aliases)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE name = VALUES(name), aliases = VALUES(aliases)
");
$newTeams = 0;
foreach ($groups as $norm => $variants) {
    $variants = array_unique($variants);
    $canonical = $variants[0];
    if (count($variants) > 1) {
        $counts = [];
        foreach ($variants as $v) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM match_results WHERE home_team = ? OR away_team = ?");
            $stmt->execute([$v, $v]);
            $counts[$v] = (int)$stmt->fetchColumn();
        }
        arsort($counts);
        $canonical = array_key_first($counts);
    }
    $aliases = array_values(array_filter($variants, fn($v) => $v !== $canonical));
    $insertTeam->execute([$canonical, $norm, !empty($aliases) ? json_encode($aliases) : null]);
    $newTeams++;
}
$log[] = "Inserted/updated $newTeams teams";

// --- Step 4: Add team_id columns to match_results ---
try { $db->exec("ALTER TABLE match_results ADD COLUMN home_team_id INT DEFAULT NULL AFTER home_team"); $log[] = "Added home_team_id to match_results"; } catch (Exception $e) { $log[] = "home_team_id already exists"; }
try { $db->exec("ALTER TABLE match_results ADD COLUMN away_team_id INT DEFAULT NULL AFTER away_team"); $log[] = "Added away_team_id to match_results"; } catch (Exception $e) { $log[] = "away_team_id already exists"; }
try { $db->exec("ALTER TABLE match_results ADD INDEX idx_home_team_id (home_team_id)"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE match_results ADD INDEX idx_away_team_id (away_team_id)"); } catch (Exception $e) {}

// --- Step 5: Backfill match_results team_ids ---
$backfill = $db->prepare("
    UPDATE match_results mr
    JOIN teams t ON t.normalized_name = ?
    SET mr.home_team_id = t.id
    WHERE mr.home_team_id IS NULL AND mr.home_team = ?
");
$backfillAway = $db->prepare("
    UPDATE match_results mr
    JOIN teams t ON t.normalized_name = ?
    SET mr.away_team_id = t.id
    WHERE mr.away_team_id IS NULL AND mr.away_team = ?
");
$filled = 0;
foreach ($groups as $norm => $variants) {
    foreach (array_unique($variants) as $variant) {
        $backfill->execute([$norm, $variant]);
        $filled += $backfill->rowCount();
        $backfillAway->execute([$norm, $variant]);
        $filled += $backfillAway->rowCount();
    }
}
$log[] = "Backfilled $filled team_ids in match_results";

// --- Step 6: Add team_id to league_standings ---
try { $db->exec("ALTER TABLE league_standings ADD COLUMN team_id INT DEFAULT NULL AFTER team"); $log[] = "Added team_id to league_standings"; } catch (Exception $e) { $log[] = "team_id already exists in league_standings"; }
try { $db->exec("ALTER TABLE league_standings ADD INDEX idx_team_id (team_id)"); } catch (Exception $e) {}

$lsRows = $db->query("SELECT id, team FROM league_standings WHERE team_id IS NULL AND team IS NOT NULL AND team != ''")->fetchAll();
$backfillLS = $db->prepare("UPDATE league_standings SET team_id = ? WHERE id = ?");
$lsFilled = 0;
foreach ($lsRows as $r) {
    $norm = normalizeTeamName($r['team']);
    $t = $db->prepare("SELECT id FROM teams WHERE normalized_name = ? LIMIT 1");
    $t->execute([$norm]);
    $tid = $t->fetchColumn();
    if ($tid) {
        $backfillLS->execute([$tid, $r['id']]);
        $lsFilled++;
    }
}
$log[] = "Backfilled $lsFilled team_ids in league_standings";

// --- Step 7: Count remaining unmapped ---
$unmappedMatch = $db->query("SELECT COUNT(*) FROM match_results WHERE home_team_id IS NULL AND home_team IS NOT NULL AND home_team != ''")->fetchColumn();
$unmappedLS = $db->query("SELECT COUNT(*) FROM league_standings WHERE team_id IS NULL AND team IS NOT NULL AND team != ''")->fetchColumn();
$log[] = "Unmapped match_results rows: $unmappedMatch";
$log[] = "Unmapped league_standings rows: $unmappedLS";

$elapsed = round(microtime(true) - $start, 2);
$log[] = "Completed in {$elapsed}s";

echo json_encode(['status' => 'ok', 'log' => $log, 'elapsed' => $elapsed]);
