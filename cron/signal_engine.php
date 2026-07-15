<?php
/**
 * Cron job for automated signal engine analysis.
 * Run daily via GitHub Actions: curl -s "https://predixa.co.tz/cron/signal_engine.php?key=YOUR_SECRET"
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/signals_engine.php';

$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

$db = getDB();
if (!$db) die(json_encode(['status' => 'error', 'message' => 'DB failed']));

$today = date('Y-m-d');
$log = [];

try {
    // Clear today's featured picks
    $db->prepare("DELETE FROM admin_featured_picks WHERE DATE(created_at) = ?")->execute([$today]);
    
    // Run signal engine analysis
    $analyzer = new OddsAnalyzer();
    $tips = $analyzer->getTips();
    
    if (empty($tips)) {
        echo json_encode(['status' => 'ok', 'message' => 'No tips generated', 'stored' => 0]);
        exit;
    }
    
    $insStmt = $db->prepare("INSERT INTO admin_featured_picks (match_name, pick_value, odds, risk_tier, win_rate_low, win_rate_high, league, match_time, details, safety_notes, pattern_badge, is_home_fav, fav_delta, opp_delta, draw_delta, home_odds, draw_odds, away_odds, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $stored = 0;
    foreach ($tips as $tip) {
        // Same categorization logic as admin.php
        $pickType = $tip['pick_type'] ?? '';
        $isOverType = ($pickType === 'over' || $pickType === 'gg');
        $favDelta = (float)($tip['fav_delta'] ?? 0);
        $oppDelta = (float)($tip['opp_delta'] ?? 0);
        $dx = (float)($tip['draw_delta'] ?? 0);
        $favOdds = (float)($tip['actual_odds'] ?? 0);
        $riskTier = strtoupper($tip['risk_tier'] ?? '');
        $notesStr = implode(' ', $tip['safety_notes'] ?? []);
        $pickText = $tip['pick'] ?? '';
        
        // Skip if not qualified for featured
        if ($isOverType) {
            $isBalanced = abs($favDelta - $dx) <= 1.0 && abs($dx - $oppDelta) <= 1.0 && abs($favDelta - $oppDelta) <= 1.0;
            if ($isBalanced) continue;
            $isPrimaryCond = $favDelta >= 0 && $favDelta <= 3.2 && $dx >= -2.5 && $dx <= 0.5 && $oppDelta < 0 && $dx >= $oppDelta;
            $isAltCond = $favDelta < -3.0 && $oppDelta >= 0 && $oppDelta <= 2.5 && $dx >= 0 && $dx <= 2.5;
            $isGoalFestCond = (abs($favDelta) > 6.0 || abs($oppDelta) > 6.0) && $dx >= 0 && $dx <= 1.5;
            if (!($dx >= -2.5 && $dx <= 0.5) && !$isAltCond && !$isGoalFestCond) continue;
            if (!($isPrimaryCond || $isAltCond || $isGoalFestCond)) continue;
            if ($favOdds < 1.15 || $favOdds > 2.15) continue;
        }
        
        // Corner hunting condition
        $cnLeague = trim($tip['league'] ?? '');
        $isCorner = false;
        if ($favOdds < 1.29 && $favDelta < -1 && ($oppDelta < 0 || $dx < 0) && !$tip['is_cup'] && isOver15League($cnLeague) && !isLeagueBlocked($cnLeague) && !isOver15Blocked($cnLeague)) {
            $cnScore = 0;
            if ($favOdds < 1.10) $cnScore += 25;
            elseif ($favOdds < 1.15) $cnScore += 20;
            elseif ($favOdds < 1.20) $cnScore += 15;
            else $cnScore += 10;
            $drop = abs($favDelta);
            if ($drop >= 5) $cnScore += 20;
            elseif ($drop >= 3) $cnScore += 15;
            elseif ($drop >= 1.5) $cnScore += 10;
            else $cnScore += 5;
            if ($cnScore >= 15) {
                $isCorner = true;
                $pickText = 'Most Corners ' . ($tip['fav_team'] ?? '');
                $pickType = 'corners';
            }
        }
        
        // Rollover/Parlay logic
        $pickTypeFinal = $pickType;
        if (!$isCorner && !$isOverType) {
            $pickTypeFinal = 'rollover';
        }
        
        // Skip if match already has a result (already played)
        $matchName = $tip['match'] ?? '';
        if ($matchName && preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $matchName, $m)) {
            $playedCheck = $db->prepare("SELECT 1 FROM match_results WHERE home_team = ? AND away_team = ? AND match_date < CURDATE() LIMIT 1");
            $playedCheck->execute([trim($m[1]), trim($m[2])]);
            if ($playedCheck->fetchColumn()) continue;
            $playedCheck->execute([trim($m[2]), trim($m[1])]);
            if ($playedCheck->fetchColumn()) continue;
        }

        $details = $notesStr;
        $badge = '';
        if (str_contains($notesStr, 'WIN 1UP')) $badge = 'WIN 1UP';
        elseif (str_contains($notesStr, 'Corner Hunt')) $badge = 'Corner Hunt';
        elseif (str_contains($notesStr, 'FALLING ODDS')) $badge = 'FALLING ODDS';
        elseif (str_contains($notesStr, 'RISING ODDS')) $badge = 'RISING ODDS';
        
        $insStmt->execute([
            $pickTypeFinal,
            $tip['match'] ?? '',
            $pickText,
            $tip['actual_odds'] ?? 0,
            $riskTier,
            (int)($tip['win_rate_low'] ?? 0),
            (int)($tip['win_rate_high'] ?? 0),
            $tip['league'] ?? '',
            $tip['match_time'] ?? '',
            $details,
            $notesStr,
            $badge,
            $tip['is_home_fav'] ?? 0,
            round($favDelta, 2),
            round($oppDelta, 2),
            round($dx, 2),
            $tip['home_odds'] ?? 0,
            $tip['draw_odds'] ?? 0,
            $tip['away_odds'] ?? 0,
        ]);
        $stored++;
    }
    
    echo json_encode(['status' => 'ok', 'stored' => $stored, 'date' => $today]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}