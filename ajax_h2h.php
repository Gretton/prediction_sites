<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
$db = getDB();
if (!$db) { die(json_encode(['error' => 'DB unavailable'])); }

$matchName = trim($_GET['match_name'] ?? '');
if (!$matchName) { die(json_encode(['error' => 'match_name required'])); }

// Parse "HomeTeam vs AwayTeam"
$parts = preg_split('/\s+vs\.?\s+/i', $matchName, 2);
if (count($parts) < 2) { die(json_encode(['error' => 'Cannot parse teams'])); }
$home = trim($parts[0]);
$away = trim($parts[1]);

$result = [
    'home_team' => $home,
    'away_team' => $away,
    'h2h' => [],
    'home_recent' => [],
    'away_recent' => [],
];

// H2H between the two teams
$h2h = $db->prepare("
    SELECT home_team, away_team, home_score, away_score, match_date, league
    FROM match_results
    WHERE ((home_team = ? AND away_team = ?) OR (home_team = ? AND away_team = ?))
      AND home_score IS NOT NULL AND away_score IS NOT NULL
    ORDER BY match_date DESC
    LIMIT 10
");
$h2h->execute([$home, $away, $away, $home]);
$result['h2h'] = $h2h->fetchAll();

// Recent matches for home team
$homeRecent = $db->prepare("
    SELECT home_team, away_team, home_score, away_score, match_date, league
    FROM match_results
    WHERE (home_team = ? OR away_team = ?)
      AND home_score IS NOT NULL AND away_score IS NOT NULL
    ORDER BY match_date DESC
    LIMIT 10
");
$homeRecent->execute([$home, $home]);
$result['home_recent'] = $homeRecent->fetchAll();

// Recent matches for away team
$awayRecent = $db->prepare("
    SELECT home_team, away_team, home_score, away_score, match_date, league
    FROM match_results
    WHERE (home_team = ? OR away_team = ?)
      AND home_score IS NOT NULL AND away_score IS NOT NULL
    ORDER BY match_date DESC
    LIMIT 10
");
$awayRecent->execute([$away, $away]);
$result['away_recent'] = $awayRecent->fetchAll();

echo json_encode($result);
