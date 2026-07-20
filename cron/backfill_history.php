<?php
/**
 * Backfill: populate missing league + historical H2H data in match_results.
 *
 * Usage:
 *   php backfill_history.php [--leagues] [--h2h] [--rapidapi-key=KEY]
 *
 * Options:
 *   --leagues       Backfill missing league values (from scraper_results + API)
 *   --h2h           Fetch additional historical H2H fixtures via API
 *   --rapidapi-key  Your API-Football key (https://rapidapi.com/api-sports/api/api-football)
 *   --dry-run       Show what would be done without writing
 *   --force         Re-process already-filled rows
 *   --limit=N       Max API calls (default: 80, free tier: 100/day)
 *
 * Run --leagues first. If many remain unmatched, get an API key and re-run.
 */

$dryRun = in_array('--dry-run', $argv ?? []);
$force = in_array('--force', $argv ?? []);
$doLeagues = in_array('--leagues', $argv ?? []);
$doH2H = in_array('--h2h', $argv ?? []);
$apiKey = '';
$apiLimit = 80;
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--rapidapi-key=(.+)$/i', $a, $m)) $apiKey = $m[1];
    if (preg_match('/^--limit=(\d+)$/i', $a, $m)) $apiLimit = (int)$m[1];
}
if (!$doLeagues && !$doH2H) {
    echo "Usage: php backfill_history.php --leagues [--h2h] [--rapidapi-key=KEY] [--dry-run] [--force] [--limit=N]\n";
    exit;
}

require_once __DIR__ . '/../config.php';
$db = getDB();
if (!$db) { echo "DB unavailable\n"; exit; }

echo "=== match_results Backfill ===\n\n";

/* ───────── Phase 1: Backfill leagues from scraper_results ───────── */
if ($doLeagues) {
    echo "--- Phase 1a: Backfill leagues from scraper_results ---\n";

    $where = $force ? "WHERE (league IS NULL OR league = '')" : "WHERE (league IS NULL OR league = '') AND match_date IS NOT NULL";
    $total = $db->query("SELECT COUNT(*) FROM match_results $where")->fetchColumn();
    echo "  Matches missing league: $total\n";

    if ($total > 0) {
        $rows = $db->query("SELECT id, home_team, away_team, match_date FROM match_results $where LIMIT 5000")->fetchAll();
        $updated = 0;
        foreach ($rows as $r) {
            $home = $db->quote($r['home_team']);
            $away = $db->quote($r['away_team']);
            $matchNameLike = $db->quote('%' . $r['home_team'] . '%vs%' . $r['away_team'] . '%');
            $matchNameLikeRev = $db->quote('%' . $r['away_team'] . '%vs%' . $r['home_team'] . '%');

            $league = $db->query("
                SELECT league FROM scraper_results
                WHERE (match_name LIKE $matchNameLike OR match_name LIKE $matchNameLikeRev)
                  AND league IS NOT NULL AND league != ''
                LIMIT 1
            ")->fetchColumn();

            if ($league) {
                if (!$dryRun) {
                    $db->prepare("UPDATE match_results SET league = ? WHERE id = ?")->execute([$league, $r['id']]);
                }
                $updated++;
            }
        }
        echo "  Updated from scraper_results: $updated\n";
    }

    /* ───── Phase 1b: Backfill leagues via API-Football ───── */
    if ($apiKey) {
        echo "\n--- Phase 1b: Backfill leagues via API-Football ---\n";
        $remaining = $db->query("SELECT COUNT(*) FROM match_results WHERE (league IS NULL OR league = '')")->fetchColumn();
        echo "  Still missing league: $remaining\n";

        $rows = $db->query("
            SELECT DISTINCT home_team, away_team FROM match_results
            WHERE (league IS NULL OR league = '') AND home_team IS NOT NULL AND away_team IS NOT NULL
            LIMIT $apiLimit
        ")->fetchAll();

        $apiCalls = 0;
        $apiMatched = 0;
        foreach ($rows as $r) {
            if ($apiCalls >= $apiLimit) break;
            $teams = [$r['home_team'], $r['away_team']];
            foreach ($teams as $team) {
                if ($apiCalls >= $apiLimit) break;
                $url = 'https://api-football-v1.p.rapidapi.com/v3/fixtures?search=' . urlencode($team) . '&last=5';
                $resp = apiFootballGet($url, $apiKey);
                $apiCalls++;
                if (!$resp || empty($resp['response'])) continue;

                foreach ($resp['response'] as $fix) {
                    $leagueName = $fix['league']['name'] ?? '';
                    $country = $fix['league']['country'] ?? '';
                    $fullLeague = $country ? "$country - $leagueName" : $leagueName;
                    if (!$fullLeague) continue;

                    $h = strtolower(trim($fix['teams']['home']['name'] ?? ''));
                    $a = strtolower(trim($fix['teams']['away']['name'] ?? ''));
                    $hs = $fix['goals']['home'] ?? null;
                    $as = $fix['goals']['away'] ?? null;
                    $date = substr($fix['fixture']['date'] ?? '', 0, 10);
                    if (!$h || !$a || !$date) continue;

                    $hNorm = strtolower(trim(preg_replace('/[^a-z0-9]/', '', $h)));
                    $aNorm = strtolower(trim(preg_replace('/[^a-z0-9]/', '', $a)));

                    $existing = $db->prepare("SELECT id, league FROM match_results WHERE home_team = ? AND away_team = ? AND match_date = ?");
                    $existing->execute([$h, $a, $date]);
                    $exRow = $existing->fetch();

                    if ($exRow) {
                        if (!$exRow['league'] && !$dryRun) {
                            $db->prepare("UPDATE match_results SET league = ? WHERE id = ?")->execute([$fullLeague, $exRow['id']]);
                            $apiMatched++;
                        }
                    } else {
                        if ($hs !== null && !$dryRun) {
                            $check = $db->prepare("SELECT id FROM match_results WHERE home_team = ? AND away_team = ? AND match_date = ?");
                            $check->execute([$h, $a, $date]);
                            if (!$check->fetch()) {
                                $leagueInsert = $fullLeague;
                                $stmt = $db->prepare("INSERT IGNORE INTO match_results (home_team, away_team, home_score, away_score, match_date, league) VALUES (?, ?, ?, ?, ?, ?)");
                                $stmt->execute([$h, $a, $hs, $as, $date, $leagueInsert]);
                                $apiMatched++;
                            }
                        }
                    }
                }
            }
        }
        echo "  API calls made: $apiCalls\n";
        echo "  Rows updated/inserted via API: $apiMatched\n";
    }

    $stillMissing = $db->query("SELECT COUNT(*) FROM match_results WHERE (league IS NULL OR league = '')")->fetchColumn();
    echo "  Still missing league after backfill: $stillMissing\n";
}

/* ───────── Phase 2: Fetch historical H2H via API ───────── */
if ($doH2H && $apiKey) {
    echo "\n--- Phase 2: Historical H2H fetch via API-Football ---\n";

    $teamPairs = $db->query("
        SELECT home_team, away_team, COUNT(*) as meetings
        FROM match_results
        WHERE home_score IS NOT NULL AND match_date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
        GROUP BY home_team, away_team
        HAVING meetings < 3
        ORDER BY meetings ASC
        LIMIT 50
    ")->fetchAll();

    echo "  Team pairs with <3 recent meetings: " . count($teamPairs) . "\n";

    $apiCalls = 0;
    $inserted = 0;
    foreach ($teamPairs as $pair) {
        if ($apiCalls >= $apiLimit) break;
        $home = urlencode($pair['home_team']);
        $away = urlencode($pair['away_team']);

        $url = "https://api-football-v1.p.rapidapi.com/v3/fixtures?h2h=$home-$away&last=10";
        $resp = apiFootballGet($url, $apiKey);
        $apiCalls++;

        if (!$resp || empty($resp['response'])) continue;

        foreach ($resp['response'] as $fix) {
            $h = strtolower(trim($fix['teams']['home']['name'] ?? ''));
            $a = strtolower(trim($fix['teams']['away']['name'] ?? ''));
            $hs = $fix['goals']['home'] ?? null;
            $as = $fix['goals']['away'] ?? null;
            $date = substr($fix['fixture']['date'] ?? '', 0, 10);
            $leagueName = $fix['league']['name'] ?? '';
            $country = $fix['league']['country'] ?? '';
            $fullLeague = $country ? "$country - $leagueName" : $leagueName;
            if (!$h || !$a || $hs === null || !$date) continue;

            $check = $db->prepare("SELECT id FROM match_results WHERE home_team = ? AND away_team = ? AND match_date = ?");
            $check->execute([$h, $a, $date]);
            if ($check->fetch()) continue;

            if (!$dryRun) {
                $stmt = $db->prepare("INSERT IGNORE INTO match_results (home_team, away_team, home_score, away_score, match_date, league) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$h, $a, $hs, $as, $date, $fullLeague]);
                $inserted++;
            }
        }
    }
    echo "  API calls made: $apiCalls\n";
    echo "  New H2H rows inserted: $inserted\n";
}

echo "\n=== Done ===\n";

/* ───────── Helpers ───────── */
function apiFootballGet($url, $key) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-RapidAPI-Key: ' . $key,
        'X-RapidAPI-Host: api-football-v1.p.rapidapi.com',
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) return null;
    return json_decode($resp, true);
}
