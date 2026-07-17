<?php
require_once __DIR__ . '/../config.php';
$db = getDB();
$today = date('Y-m-d');

echo "=== Pick Settlements for today ===\n";
$stmt = $db->prepare("SELECT * FROM pick_settlements WHERE settlement_date = ? AND web_pick_id IN (SELECT id FROM web_picks WHERE match_name LIKE '%Saints%Sabah%')");
$stmt->execute([$today]);
foreach ($stmt->fetchAll() as $r) {
    echo "  id={$r['id']} wp={$r['web_pick_id']} score={$r['home_score']}-{$r['away_score']} result={$r['result']} date={$r['settlement_date']}\n";
}

echo "\n=== Match Results for this team ===\n";
$stmt = $db->prepare("SELECT * FROM match_results WHERE (home_team LIKE '%saint%' OR away_team LIKE '%saint%' OR home_team LIKE '%sabah%' OR away_team LIKE '%sabah%') AND match_date >= ? ORDER BY id");
$stmt->execute([date('Y-m-d', strtotime('-7 days'))]);
foreach ($stmt->fetchAll() as $r) {
    echo "  id={$r['id']} '{$r['home_team']}' vs '{$r['away_team']}' {$r['home_score']}-{$r['away_score']} date={$r['match_date']}\n";
}

echo "\n=== All pick_settlements for web_pick_id 4123 ===\n";
$stmt = $db->prepare("SELECT * FROM pick_settlements WHERE web_pick_id = 4123 ORDER BY id");
$stmt->execute();
foreach ($stmt->fetchAll() as $r) {
    echo "  id={$r['id']} wp={$r['web_pick_id']} score={$r['home_score']}-{$r['away_score']} result={$r['result']} date={$r['settlement_date']}\n";
}

echo "\n=== Web pick 4123 ===\n";
$stmt = $db->prepare("SELECT id, match_name, pick_type, pick_value, detected_at FROM web_picks WHERE id = 4123");
$stmt->execute();
foreach ($stmt->fetchAll() as $r) {
    echo "  id={$r['id']} match='{$r['match_name']}' type={$r['pick_type']} val={$r['pick_value']} detected={$r['detected_at']}\n";
}
