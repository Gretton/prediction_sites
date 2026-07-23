<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/BayesianModel.php';

$key = $_GET['key'] ?? '';
if ($key !== STATS_SECRET_KEY) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

header('Content-Type: text/plain');

echo "=== 1. Table check ===\n";
$db = getDB();
try {
    $r = $db->query("SHOW TABLES LIKE 'match_statistics'");
    if ($r->fetch()) {
        echo "match_statistics: EXISTS\n";
        $count = $db->query("SELECT COUNT(*) FROM match_statistics")->fetchColumn();
        echo "Rows: $count\n";
    } else {
        echo "match_statistics: MISSING — run the CREATE TABLE SQL!\n";
        exit;
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit;
}

echo "\n=== 2. API key test ===\n";
$ch = curl_init('https://v3.football.api-sports.io/fixtures?date=' . date('Y-m-d', strtotime('-1 day')));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['x-apisports-key: ' . STATS_API_KEY],
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP $code\n";
$data = json_decode($resp, true);
echo "Fixtures found: " . count($data['response'] ?? []) . "\n";
if (isset($data['errors'])) echo "API errors: " . json_encode($data['errors']) . "\n";

echo "\n=== 3. Collect 1 match test ===\n";
if (!empty($data['response'])) {
    $fx = $data['response'][0];
    $fxId = $fx['fixture']['id'];
    $home = $fx['teams']['home']['name'];
    $away = $fx['teams']['away']['name'];
    echo "Testing: $home vs $away (fixture $fxId)\n";
    
    $ch2 = curl_init("https://v3.football.api-sports.io/fixtures/statistics?fixture=$fxId");
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-apisports-key: ' . STATS_API_KEY],
        CURLOPT_TIMEOUT => 15,
    ]);
    $statsResp = curl_exec($ch2);
    $statsCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    echo "Stats HTTP: $statsCode\n";
    $statsData = json_decode($statsResp, true);
    $homeStats = $statsData['response'][0]['statistics'] ?? [];
    echo "Home stat rows: " . count($homeStats) . "\n";
    foreach ($homeStats as $s) {
        echo "  {$s['type']}: {$s['value']}\n";
    }
} else {
    echo "No fixtures to test\n";
}
