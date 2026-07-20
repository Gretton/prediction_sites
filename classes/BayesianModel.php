<?php
require_once __DIR__ . '/../config.php';

class BayesianModel {
    private $db;
    private $priorStrength = 20;
    private $recencyHalfLife = 90;
    private $leaguePriors = [];
    private static $teamAliases = [];

    public function __construct() {
        $this->db = getDB();
        $this->ensureTable();
        $this->loadTeamAliases();
        $this->computeLeaguePriors();
    }

    private function ensureTable() {
        if (!$this->db) return;
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `bayesian_predictions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `home_team` VARCHAR(255) NOT NULL,
                `away_team` VARCHAR(255) NOT NULL,
                `match_name` VARCHAR(255) NOT NULL,
                `league` VARCHAR(255) DEFAULT NULL,
                `match_date` DATE NOT NULL,
                `prob_1` DECIMAL(5,2) DEFAULT NULL,
                `prob_x` DECIMAL(5,2) DEFAULT NULL,
                `prob_2` DECIMAL(5,2) DEFAULT NULL,
                `prob_1x` DECIMAL(5,2) DEFAULT NULL,
                `prob_x2` DECIMAL(5,2) DEFAULT NULL,
                `prob_12` DECIMAL(5,2) DEFAULT NULL,
                `over_25` DECIMAL(5,2) DEFAULT NULL,
                `under_25` DECIMAL(5,2) DEFAULT NULL,
                `btts_yes` DECIMAL(5,2) DEFAULT NULL,
                `btts_no` DECIMAL(5,2) DEFAULT NULL,
                `expected_goals` DECIMAL(4,2) DEFAULT NULL,
                `confidence` DECIMAL(5,2) DEFAULT NULL,
                `recommended_pick` VARCHAR(100) DEFAULT NULL,
                `value_edge_1` DECIMAL(5,2) DEFAULT NULL,
                `value_edge_x` DECIMAL(5,2) DEFAULT NULL,
                `value_edge_2` DECIMAL(5,2) DEFAULT NULL,
                `value_pick` VARCHAR(100) DEFAULT NULL,
                `market_odds_1` DECIMAL(8,2) DEFAULT NULL,
                `market_odds_x` DECIMAL(8,2) DEFAULT NULL,
                `market_odds_2` DECIMAL(8,2) DEFAULT NULL,
                `home_score` INT DEFAULT NULL,
                `away_score` INT DEFAULT NULL,
                `result` ENUM('pending','correct','incorrect','void') NOT NULL DEFAULT 'pending',
                `settled_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_match_date` (`match_date`),
                INDEX `idx_result` (`result`),
                INDEX `idx_league` (`league`),
                INDEX `idx_match_name` (`match_name`),
                UNIQUE KEY `uq_bayesian_match` (`match_name`(100), `match_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `value_edge_1` DECIMAL(5,2) DEFAULT NULL AFTER `recommended_pick`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `value_edge_x` DECIMAL(5,2) DEFAULT NULL AFTER `value_edge_1`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `value_edge_2` DECIMAL(5,2) DEFAULT NULL AFTER `value_edge_x`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `value_pick` VARCHAR(100) DEFAULT NULL AFTER `value_edge_2`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_1` DECIMAL(8,2) DEFAULT NULL AFTER `value_pick`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_x` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_1`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_2` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_x`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `prob_over_15` DECIMAL(5,2) DEFAULT NULL AFTER `under_25`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `prob_under_15` DECIMAL(5,2) DEFAULT NULL AFTER `prob_over_15`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `prob_over_35` DECIMAL(5,2) DEFAULT NULL AFTER `prob_under_15`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `prob_under_35` DECIMAL(5,2) DEFAULT NULL AFTER `prob_over_35`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_over15` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_2`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_under25` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_over15`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_under35` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_under25`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_btts_yes` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_under35`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_btts_no` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_btts_yes`"); } catch (Exception $e) {}
            try { $this->db->exec("ALTER TABLE bayesian_predictions ADD COLUMN `match_time` VARCHAR(50) DEFAULT NULL AFTER `match_date`"); } catch (Exception $e) {}
        } catch (Exception $e) {
            error_log("BayesianModel::ensureTable: " . $e->getMessage());
        }
    }

    private function loadTeamAliases() {
        if (!empty(self::$teamAliases)) return;
        if (!$this->db) return;
        try {
            self::$teamAliases = [];
            // Load from teams table first (preferred)
            $stmt = $this->db->query("SELECT id, name, normalized_name, aliases FROM teams");
            foreach ($stmt->fetchAll() as $r) {
                $key = $r['normalized_name'];
                $names = [$r['name']];
                if ($r['aliases']) {
                    $extra = json_decode($r['aliases'], true);
                    if (is_array($extra)) $names = array_merge($names, $extra);
                }
                self::$teamAliases[$key] = ['id' => (int)$r['id'], 'names' => $names];
            }
            // If teams table is empty, fall back to match_results
            if (empty(self::$teamAliases)) {
                $stmt = $this->db->query("SELECT DISTINCT home_team FROM match_results UNION SELECT DISTINCT away_team FROM match_results");
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $key = $this->normalizeName($name);
                    if (!isset(self::$teamAliases[$key])) self::$teamAliases[$key] = ['id' => null, 'names' => []];
                    self::$teamAliases[$key]['names'][] = $name;
                }
            }
        } catch (Exception $e) {}
    }

    private function getTeamId($name) {
        $key = $this->normalizeName($name);
        if (isset(self::$teamAliases[$key]) && self::$teamAliases[$key]['id']) {
            return self::$teamAliases[$key]['id'];
        }
        return null;
    }

    public function resolveTeamName($scrapedName) {
        $key = $this->normalizeName($scrapedName);
        if (isset(self::$teamAliases[$key]) && !empty(self::$teamAliases[$key]['names'])) {
            return self::$teamAliases[$key]['names'][0];
        }
        $best = null; $bestScore = 0;
        foreach (self::$teamAliases as $dbKey => $entry) {
            $score = $this->bigramSimilarity($key, $dbKey);
            if ($score > $bestScore && $score > 0.55) {
                $bestScore = $score;
                $best = $entry['names'][0] ?? null;
            }
        }
        return $best ?: $scrapedName;
    }

    public function resolveTeamId($scrapedName) {
        $key = $this->normalizeName($scrapedName);
        if (isset(self::$teamAliases[$key]) && !empty(self::$teamAliases[$key]['id'])) {
            return self::$teamAliases[$key]['id'];
        }
        return null;
    }

    private function normalizeName($name) {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9\s\-\']/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $subs = [
            'man utd' => 'manchester united', 'man united' => 'manchester united',
            'man city' => 'manchester city', 'manchester cty' => 'manchester city',
            'tot' => 'tottenham', 'spurs' => 'tottenham',
            'ars' => 'arsenal', 'che' => 'chelsea',
            'liv' => 'liverpool', 'new' => 'newcastle',
            'inter' => 'inter milan', 'milan' => 'ac milan',
            'juve' => 'juventus', 'nap' => 'napoli',
            'barca' => 'barcelona', 'realmadrid' => 'real madrid',
            'psg' => 'paris saint germain',
            'bayern' => 'bayern munich', 'bvb' => 'borussia dortmund',
            'fc ' => '', 'cf ' => '', 'ac ' => '', 'sc ' => '',
            'rc ' => '', 'ss ' => '', 'cd ' => '', 'as ' => '',
            'sk ' => '', 'fk ' => '', 'nk ' => '', 'ud ' => '',
            'ca ' => '', 'cr ' => '', 'ec ' => '', 'aa ' => '',
            'ae ' => '', 'ssc ' => '', 'if ' => '', 'bk ' => '',
        ];
        foreach ($subs as $a => $c) {
            $name = str_replace($a, $c, $name);
        }
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    private function bigramSimilarity($a, $b) {
        if ($a === $b) return 1.0;
        $bgA = $this->bigrams($a);
        $bgB = $this->bigrams($b);
        if (empty($bgA) || empty($bgB)) return 0;
        $intersect = array_intersect($bgA, $bgB);
        return 2 * count($intersect) / (count($bgA) + count($bgB));
    }

    private function bigrams($s) {
        $r = [];
        for ($i = 0; $i < mb_strlen($s) - 1; $i++) {
            $r[] = mb_substr($s, $i, 2);
        }
        return $r;
    }

    public function resolveMatchTeams($scrapedMatchName) {
        $parts = preg_split('/\s+vs\.?\s+/i', trim($scrapedMatchName), 2);
        if (count($parts) !== 2) return null;
        return [
            'original' => $scrapedMatchName,
            'home' => $parts[0],
            'away' => $parts[1],
            'resolved_home' => $this->resolveTeamName($parts[0]),
            'resolved_away' => $this->resolveTeamName($parts[1]),
        ];
    }

    private function computeLeaguePriors() {
        if (!$this->db) return;
        $cacheKey = 'bayesian_league_priors';
        $cached = isset($GLOBALS[$cacheKey]) ? $GLOBALS[$cacheKey] : null;
        if ($cached !== null) { $this->leaguePriors = $cached; return; }

        try {
            $stmt = $this->db->query("
                SELECT
                    league,
                    COUNT(*) as total,
                    SUM(CASE WHEN home_score > away_score THEN 1 ELSE 0 END) as home_wins,
                    SUM(CASE WHEN home_score = away_score THEN 1 ELSE 0 END) as draws,
                    SUM(CASE WHEN home_score < away_score THEN 1 ELSE 0 END) as away_wins,
                    AVG(home_score + away_score) as avg_goals,
                    SUM(CASE WHEN home_score > 0 AND away_score > 0 THEN 1 ELSE 0 END) as btts
                FROM match_results
                WHERE match_date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
                  AND home_score IS NOT NULL AND away_score IS NOT NULL
                GROUP BY league
                HAVING total >= 10
            ");
            $priors = [];
            foreach ($stmt->fetchAll() as $r) {
                $t = (int)$r['total'];
                $hw = (int)$r['home_wins'];
                $d = (int)$r['draws'];
                $aw = (int)$r['away_wins'];
                $priors[$r['league']] = [
                    'total' => $t,
                    'home_win_rate' => $hw / $t,
                    'draw_rate' => $d / $t,
                    'away_win_rate' => $aw / $t,
                    'avg_goals' => (float)$r['avg_goals'],
                    'btts_rate' => $t > 0 ? (int)$r['btts'] / $t : 0,
                ];
            }
            $priors['__global__'] = $this->computeGlobalPrior($priors);
            $this->leaguePriors = $priors;
            $GLOBALS[$cacheKey] = $priors;
        } catch (Exception $e) {
            error_log("BayesianModel::computeLeaguePriors: " . $e->getMessage());
        }
    }

    private function computeGlobalPrior($priors) {
        $total = 0; $hw = 0; $d = 0; $aw = 0; $goals = 0; $btts = 0; $count = 0;
        foreach ($priors as $l => $p) {
            $total += $p['total'];
            $hw += $p['home_win_rate'] * $p['total'];
            $d += $p['draw_rate'] * $p['total'];
            $aw += $p['away_win_rate'] * $p['total'];
            $goals += $p['avg_goals'] * $p['total'];
            $btts += $p['btts_rate'] * $p['total'];
            $count += $p['total'];
        }
        return $count > 0 ? [
            'total' => $total,
            'home_win_rate' => $hw / $count,
            'draw_rate' => $d / $count,
            'away_win_rate' => $aw / $count,
            'avg_goals' => $goals / $count,
            'btts_rate' => $btts / $count,
        ] : [
            'total' => 0, 'home_win_rate' => 0.45, 'draw_rate' => 0.25, 'away_win_rate' => 0.30,
            'avg_goals' => 2.5, 'btts_rate' => 0.50,
        ];
    }

    private function getLeaguePrior($league) {
        if (!$league || !isset($this->leaguePriors[$league])) {
            return $this->leaguePriors['__global__'] ?? [
                'home_win_rate' => 0.45, 'draw_rate' => 0.25, 'away_win_rate' => 0.30,
                'avg_goals' => 2.5, 'btts_rate' => 0.50,
            ];
        }
        return $this->leaguePriors[$league];
    }

    public function predict($homeTeam, $awayTeam, $league = null, $lookbackDays = 365) {
        $prior = $this->getLeaguePrior($league);
        $homeForm = $this->getTeamHistory($homeTeam, $league, true, $lookbackDays);
        $awayForm = $this->getTeamHistory($awayTeam, $league, false, $lookbackDays);
        $h2h = $this->getHeadToHead($homeTeam, $awayTeam, $lookbackDays);

        $homeMatches = $homeForm['matches'];
        $awayMatchCount = $awayForm['matches'];
        $k = $this->priorStrength;

        $alphaHome = $prior['home_win_rate'] * $k + $homeForm['wins'];
        $betaHome = (1 - $prior['home_win_rate']) * $k + ($homeMatches - $homeForm['wins']);
        $postHome = $alphaHome / ($alphaHome + $betaHome);

        $alphaDraw = $prior['draw_rate'] * $k + $homeForm['draws'];
        $betaDraw = (1 - $prior['draw_rate']) * $k + ($homeMatches - $homeForm['draws']);
        $alphaDraw += $awayForm['draws'];
        $betaDraw += ($awayMatchCount - $awayForm['draws']);
        $postDraw = $alphaDraw / ($alphaDraw + $betaDraw);

        $alphaAway = $prior['away_win_rate'] * $k + $awayForm['wins'];
        $betaAway = (1 - $prior['away_win_rate']) * $k + ($awayMatchCount - $awayForm['wins']);
        $postAway = $alphaAway / ($alphaAway + $betaAway);

        $total = $postHome + $postDraw + $postAway;
        if ($total > 0) { $postHome /= $total; $postDraw /= $total; $postAway /= $total; }

        if ($h2h['matches'] >= 3) {
            $h2hHome = $h2h['home_wins'] / max(1, $h2h['matches']);
            $postHome = $postHome * 0.8 + $h2hHome * 0.2;
            $h2hDraw = $h2h['draws'] / max(1, $h2h['matches']);
            $postDraw = $postDraw * 0.8 + $h2hDraw * 0.2;
            $h2hAway = $h2h['away_wins'] / max(1, $h2h['matches']);
            $postAway = $postAway * 0.8 + $h2hAway * 0.2;
            $t2 = $postHome + $postDraw + $postAway;
            if ($t2 > 0) { $postHome /= $t2; $postDraw /= $t2; $postAway /= $t2; }
        }

        $homeStanding = $this->getTeamStanding($homeTeam, $league);
        $awayStanding = $this->getTeamStanding($awayTeam, $league);
        if ($homeStanding && $awayStanding) {
            $totalTeams = max(20, $homeStanding['position'] + $awayStanding['position']);
            $homePosNorm = 1 - (($homeStanding['position'] - 1) / ($totalTeams - 1));
            $awayPosNorm = 1 - (($awayStanding['position'] - 1) / ($totalTeams - 1));
            $homeAdj = ($homePosNorm - $awayPosNorm) * 0.06;
            $awayAdj = ($awayPosNorm - $homePosNorm) * 0.06;
            $postHome = max(0.01, $postHome + $homeAdj);
            $postAway = max(0.01, $postAway + $awayAdj);
            $postDraw = max(0.01, 1 - $postHome - $postAway);
            $t3 = $postHome + $postDraw + $postAway;
            if ($t3 > 0) { $postHome /= $t3; $postDraw /= $t3; $postAway /= $t3; }
        }

        $ou = $this->predictOverUnder($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays);
        $btts = $this->predictBTTS($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays);

        $dcHomeDraw = $postHome + $postDraw;
        $dcAwayDraw = $postAway + $postDraw;
        $dcHomeAway = $postHome + $postAway;

        $uniform = 1/3;
        $confidence = round((abs($postHome - $uniform) + abs($postDraw - $uniform) + abs($postAway - $uniform)) / (2 * (1 - $uniform)) * 100, 1);

        return [
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'league' => $league,
            'prior' => $prior,
            'home_form' => $homeForm,
            'away_form' => $awayForm,
            'h2h' => $h2h,
            'probs' => [
                '1' => round($postHome * 100, 1),
                'X' => round($postDraw * 100, 1),
                '2' => round($postAway * 100, 1),
                '1X' => round($dcHomeDraw * 100, 1),
                'X2' => round($dcAwayDraw * 100, 1),
                '12' => round($dcHomeAway * 100, 1),
            ],
            'over_under' => $ou,
            'btts' => $btts,
            'confidence' => $confidence,
            'recommended_pick' => $this->getRecommendedPick($postHome, $postDraw, $postAway, $ou, $btts),
            'data_quality' => $homeForm['matches'] + $awayForm['matches'] + $h2h['matches'],
        ];
    }

    public function storePrediction($homeTeam, $awayTeam, $league, $pred, $matchTime = null) {
        if (!$this->db) return false;
        $matchName = $homeTeam . ' vs ' . $awayTeam;
        $recPick = '';
        if (!empty($pred['recommended_pick'])) {
            $parts = [];
            foreach ($pred['recommended_pick'] as $rp) {
                $parts[] = $rp['type'] . ':' . $rp['prob'];
            }
            $recPick = implode(', ', $parts);
        }
        try {
            $stmt = $this->db->prepare("
                INSERT INTO bayesian_predictions
                    (home_team, away_team, match_name, league, match_date, match_time,
                     prob_1, prob_x, prob_2, prob_1x, prob_x2, prob_12,
                     over_25, under_25, prob_over_15, prob_under_15, prob_over_35, prob_under_35,
                     btts_yes, btts_no, expected_goals,
                     confidence, recommended_pick)
                VALUES (?, ?, ?, ?, CURDATE(), ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?)
                ON DUPLICATE KEY UPDATE
                    prob_1 = VALUES(prob_1), prob_x = VALUES(prob_x), prob_2 = VALUES(prob_2),
                    prob_1x = VALUES(prob_1x), prob_x2 = VALUES(prob_x2), prob_12 = VALUES(prob_12),
                    over_25 = VALUES(over_25), under_25 = VALUES(under_25),
                    prob_over_15 = VALUES(prob_over_15), prob_under_15 = VALUES(prob_under_15),
                    prob_over_35 = VALUES(prob_over_35), prob_under_35 = VALUES(prob_under_35),
                    btts_yes = VALUES(btts_yes), btts_no = VALUES(btts_no),
                    expected_goals = VALUES(expected_goals), confidence = VALUES(confidence),
                    recommended_pick = VALUES(recommended_pick)
            ");
            return $stmt->execute([
                $homeTeam, $awayTeam, $matchName, $league, $matchTime,
                $pred['probs']['1'], $pred['probs']['X'], $pred['probs']['2'],
                $pred['probs']['1X'], $pred['probs']['X2'], $pred['probs']['12'],
                $pred['over_under']['over_25'], $pred['over_under']['under_25'],
                $pred['over_under']['over_15'], $pred['over_under']['under_15'],
                $pred['over_under']['over_35'], $pred['over_under']['under_35'],
                $pred['btts']['yes'], $pred['btts']['no'],
                $pred['over_under']['expected_total_goals'],
                $pred['confidence'], $recPick,
            ]);
        } catch (Exception $e) {
            error_log("BayesianModel::storePrediction: " . $e->getMessage());
            return false;
        }
    }

    public function runBatchPredictions($lookbackDays = 30) {
        if (!$this->db) return ['stored' => 0, 'skipped' => 0, 'errors' => 0];
        $stored = 0; $skipped = 0; $errors = 0;

        // Get distinct matches from scraper_results + web_picks for today
        $stmt = $this->db->query("
            SELECT match_name, league, match_time FROM (
                SELECT CONVERT(match_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS match_name, CONVERT(league USING utf8mb4) COLLATE utf8mb4_unicode_ci AS league, match_time FROM scraper_results WHERE DATE(detected_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                UNION
                SELECT CONVERT(match_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(league USING utf8mb4) COLLATE utf8mb4_unicode_ci, match_time FROM web_picks WHERE DATE(detected_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                UNION
                SELECT CONVERT(match_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(league USING utf8mb4) COLLATE utf8mb4_unicode_ci, NULL as match_time FROM admin_featured_picks WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ) t LIMIT 100
        ");

        foreach ($stmt->fetchAll() as $m) {
            $resolved = $this->resolveMatchTeams($m['match_name']);
            if (!$resolved) { $skipped++; continue; }
            try {
                $pred = $this->predict($resolved['resolved_home'], $resolved['resolved_away'], $m['league']);
                $this->storePrediction($resolved['resolved_home'], $resolved['resolved_away'], $m['league'], $pred, $m['match_time']);
                $stored++;
            } catch (Exception $e) {
                $errors++;
                error_log("BayesianModel::runBatchPredictions error for {$m['match_name']}: " . $e->getMessage());
            }
        }
        return ['stored' => $stored, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function settlePredictions() {
        if (!$this->db) return ['settled' => 0, 'matched' => 0, 'unmatched' => 0];
        $settled = 0; $matched = 0;

        try {
            // Match pending predictions against pick_settlements by score
            $stmt = $this->db->query("
                SELECT bp.id, bp.home_team, bp.away_team, bp.match_name, bp.match_date,
                       bp.prob_1, bp.prob_x, bp.prob_2, bp.over_25, bp.under_25,
                       bp.btts_yes, bp.btts_no, bp.recommended_pick,
                       ps.home_score, ps.away_score, ps.result as settlement_result
                FROM bayesian_predictions bp
                INNER JOIN (
                    SELECT p2.* FROM pick_settlements p2
                    INNER JOIN (
                        SELECT match_name, MAX(id) AS max_id FROM pick_settlements
                        WHERE result IN ('won','lost') AND home_score IS NOT NULL
                        GROUP BY match_name
                    ) latest ON p2.id = latest.max_id
                ) ps ON bp.match_name = ps.match_name
                WHERE bp.result = 'pending'
                LIMIT 200
            ");

            $updateCorrect = $this->db->prepare("UPDATE bayesian_predictions SET result = 'correct', home_score = ?, away_score = ?, settled_at = NOW() WHERE id = ?");
            $updateIncorrect = $this->db->prepare("UPDATE bayesian_predictions SET result = 'incorrect', home_score = ?, away_score = ?, settled_at = NOW() WHERE id = ?");

            foreach ($stmt->fetchAll() as $bp) {
                $hs = (int)$bp['home_score'];
                $as = (int)$bp['away_score'];
                $actualWinner = $hs > $as ? '1' : ($hs === $as ? 'X' : '2');
                $totalG = $hs + $as;
                $bttsActual = ($hs > 0 && $as > 0);

                $correct = true;
                $recPick = $bp['recommended_pick'] ?? '';

                // Check recommended picks vs actual
                if (str_contains($recPick, '1:') && !str_contains($recPick, '1X:') && !str_contains($recPick, '12:')) {
                    if ($actualWinner !== '1') $correct = false;
                } elseif (str_contains($recPick, 'X:')) {
                    if ($actualWinner !== 'X') $correct = false;
                } elseif (str_contains($recPick, '2:')) {
                    if ($actualWinner !== '2') $correct = false;
                } elseif (str_contains($recPick, '1X:')) {
                    if ($actualWinner === '2') $correct = false;
                } elseif (str_contains($recPick, 'X2:')) {
                    if ($actualWinner === '1') $correct = false;
                } elseif (str_contains($recPick, '12:')) {
                    if ($actualWinner === 'X') $correct = false;
                }
                // Check Over/Under
                if (str_contains($recPick, 'Over 2.5') && $totalG <= 2.5) $correct = false;
                if (str_contains($recPick, 'Under 2.5') && $totalG > 2.5) $correct = false;
                if (str_contains($recPick, 'GG') && !$bttsActual) $correct = false;
                if (str_contains($recPick, 'NG') && $bttsActual) $correct = false;

                if ($correct) {
                    $updateCorrect->execute([$hs, $as, $bp['id']]);
                } else {
                    $updateIncorrect->execute([$hs, $as, $bp['id']]);
                }
                $settled++;
            }
        } catch (Exception $e) {
            error_log("BayesianModel::settlePredictions: " . $e->getMessage());
        }
        return ['settled' => $settled, 'matched' => $matched];
    }

    public function getAccuracyStats() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) as correct,
                    SUM(CASE WHEN result = 'incorrect' THEN 1 ELSE 0 END) as incorrect,
                    SUM(CASE WHEN result = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN result = 'void' THEN 1 ELSE 0 END) as voided,
                    ROUND(AVG(CASE WHEN result = 'correct' THEN confidence ELSE NULL END), 1) as avg_conf_correct,
                    ROUND(AVG(CASE WHEN result = 'incorrect' THEN confidence ELSE NULL END), 1) as avg_conf_incorrect,
                    MIN(match_date) as from_date, MAX(match_date) as to_date
                FROM bayesian_predictions
            ")->fetch();
        } catch (Exception $e) { return []; }
    }

    public function getTodayPredictions() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT id, home_team, away_team, match_name, league,
                       prob_1, prob_x, prob_2, confidence, recommended_pick
                FROM bayesian_predictions
                WHERE match_date = CURDATE()
                ORDER BY confidence DESC
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function updateValueEdge($id, $edge1, $edgeX, $edge2, $valuePick, $odds1, $oddsX, $odds2, $oddsOver15 = null, $oddsUnder25 = null, $oddsBttsYes = null, $oddsBttsNo = null) {
        if (!$this->db) return false;
        try {
            $stmt = $this->db->prepare("
                UPDATE bayesian_predictions SET
                    value_edge_1 = ?, value_edge_x = ?, value_edge_2 = ?,
                    value_pick = ?,
                    market_odds_1 = ?, market_odds_x = ?, market_odds_2 = ?,
                    market_odds_over15 = ?, market_odds_under25 = ?,
                    market_odds_btts_yes = ?, market_odds_btts_no = ?
                WHERE id = ?
            ");
            return $stmt->execute([$edge1, $edgeX, $edge2, $valuePick, $odds1, $oddsX, $odds2, $oddsOver15, $oddsUnder25, $oddsBttsYes, $oddsBttsNo, $id]);
        } catch (Exception $e) {
            error_log("BayesianModel::updateValueEdge: " . $e->getMessage());
            return false;
        }
    }

    public function getAccuracyByLeague() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT league, COUNT(*) as total,
                       SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) as correct,
                       SUM(CASE WHEN result = 'incorrect' THEN 1 ELSE 0 END) as incorrect,
                       ROUND(SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as accuracy
                FROM bayesian_predictions
                WHERE result IN ('correct','incorrect')
                GROUP BY league
                HAVING total >= 5
                ORDER BY accuracy DESC
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function getAgreementScore($matchName, $pickValue) {
        // How much does Bayesian agree with a given pick?
        if (!$this->db) return null;
        try {
            $stmt = $this->db->prepare("
                SELECT prob_1, prob_x, prob_2, prob_1x, prob_x2, prob_12,
                       over_25, under_25, btts_yes, btts_no, confidence
                FROM bayesian_predictions
                WHERE match_name = ? AND match_date = CURDATE()
                LIMIT 1
            ");
            $stmt->execute([$matchName]);
            $bp = $stmt->fetch();
            if (!$bp) return null;

            $pv = strtoupper(trim($pickValue));
            $bayesProb = null;

            switch ($pv) {
                case '1': $bayesProb = (float)$bp['prob_1']; break;
                case 'X': case 'DRAW': $bayesProb = (float)$bp['prob_x']; break;
                case '2': $bayesProb = (float)$bp['prob_2']; break;
                case '1X': $bayesProb = (float)$bp['prob_1x']; break;
                case 'X2': $bayesProb = (float)$bp['prob_x2']; break;
                case '12': $bayesProb = (float)$bp['prob_12']; break;
            }
            if (preg_match('/^OVER\s+(\d+\.?\d*)\s*GOALS?$/i', $pv, $m)) {
                $thresh = (float)$m[1];
                if ($thresh == 2.5) $bayesProb = (float)$bp['over_25'];
                elseif ($thresh == 1.5) $bayesProb = (float)$bp['over_25'] > 50 ? (float)$bp['over_25'] : 100 - (float)$bp['under_25'];
            }
            if (preg_match('/^UNDER\s+(\d+\.?\d*)\s*GOALS?$/i', $pv, $m)) {
                $thresh = (float)$m[1];
                if ($thresh == 2.5) $bayesProb = (float)$bp['under_25'];
            }
            if (in_array($pv, ['GG', 'BTS', 'BTTS'])) $bayesProb = (float)$bp['btts_yes'];
            if (in_array($pv, ['NG', 'NBTS'])) $bayesProb = (float)$bp['btts_no'];

            if ($bayesProb === null) return null;
            // Agreement: how much Bayesian probability supports this pick
            // If bayesProb > 50%, it agrees with the pick
            // Score = (bayesProb - 50) * 2 → ranges from 0 to 100
            $agreement = round(($bayesProb - 50) * 2, 1);
            return [
                'probability' => $bayesProb,
                'agreement' => max(0, min(100, $agreement)),
                'confidence' => (float)$bp['confidence'],
                'strongly_agrees' => $agreement > 30,
                'disagrees' => $agreement < -10,
            ];
        } catch (Exception $e) { return null; }
    }

    private function getTeamHistory($team, $league, $isHome, $lookbackDays) {
        $default = ['matches' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'form' => []];
        if (!$this->db) return $default;
        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $teamId = $this->getTeamId($team);
            if ($teamId) {
                $stmt = $this->db->prepare("
                    SELECT home_score, away_score, match_date
                    FROM match_results
                    WHERE (home_team_id = ? OR away_team_id = ?)
                      AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC
                    LIMIT 30
                ");
                $stmt->execute([$teamId, $teamId, $lookback]);
            } else {
                $col = $isHome ? 'home_team' : 'away_team';
                $stmt = $this->db->prepare("
                    SELECT home_score, away_score, match_date
                    FROM match_results
                    WHERE $col = ? AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC
                    LIMIT 30
                ");
                $stmt->execute([$team, $lookback]);
            }
            $rows = $stmt->fetchAll();
            if (empty($rows)) return $default;

            $nowTs = time();
            $wins = 0; $draws = 0; $losses = 0; $gf = 0; $ga = 0; $totalWeight = 0; $form = [];
            foreach ($rows as $r) {
                $daysAgo = ($nowTs - strtotime($r['match_date'])) / 86400;
                $weight = exp(-$daysAgo / $this->recencyHalfLife);
                $totalWeight += $weight;
                $h = (int)$r['home_score'];
                $a = (int)$r['away_score'];
                if ($isHome) { $gf += $h * $weight; $ga += $a * $weight; $r_ = $h > $a ? 'W' : ($h === $a ? 'D' : 'L'); }
                else { $gf += $a * $weight; $ga += $h * $weight; $r_ = $a > $h ? 'W' : ($a === $h ? 'D' : 'L'); }
                if ($r_ === 'W') $wins += $weight; elseif ($r_ === 'D') $draws += $weight; else $losses += $weight;
                $form[] = $r_;
            }
            $effectiveMatches = max(1, $totalWeight);
            return [
                'matches' => round($effectiveMatches, 1), 'wins' => round($wins, 1), 'draws' => round($draws, 1), 'losses' => round($losses, 1),
                'gf' => round($gf, 1), 'ga' => round($ga, 1),
                'form' => implode('', array_slice($form, 0, 5)),
                'form_rating' => round(($wins * 3 + $draws) / ($effectiveMatches * 3) * 10, 1),
            ];
        } catch (Exception $e) {
            error_log("BayesianModel::getTeamHistory: " . $e->getMessage());
            return $default;
        }
    }

    private function getHeadToHead($homeTeam, $awayTeam, $lookbackDays) {
        $default = ['matches' => 0, 'home_wins' => 0, 'draws' => 0, 'away_wins' => 0, 'avg_goals' => 0, 'btts_rate' => 0];
        if (!$this->db) return $default;
        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $homeId = $this->getTeamId($homeTeam);
            $awayId = $this->getTeamId($awayTeam);
            if ($homeId && $awayId) {
                $stmt = $this->db->prepare("
                    SELECT mr.home_team, mr.home_score, mr.away_score, mr.match_date
                    FROM match_results mr
                    WHERE ((mr.home_team_id = ? AND mr.away_team_id = ?) OR (mr.home_team_id = ? AND mr.away_team_id = ?))
                      AND mr.match_date >= ? AND mr.match_date <= CURDATE()
                      AND mr.home_score IS NOT NULL AND mr.away_score IS NOT NULL
                    ORDER BY mr.match_date DESC LIMIT 10
                ");
                $stmt->execute([$homeId, $awayId, $awayId, $homeId, $lookback]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT home_team, home_score, away_score, match_date
                    FROM match_results
                    WHERE ((home_team = ? AND away_team = ?) OR (home_team = ? AND away_team = ?))
                      AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC LIMIT 10
                ");
                $stmt->execute([$homeTeam, $awayTeam, $awayTeam, $homeTeam, $lookback]);
            }
            $rows = $stmt->fetchAll();
            if (empty($rows)) return $default;

            $nowTs = time();
            $hw = 0; $d = 0; $aw = 0; $totalG = 0; $btts = 0; $totalWeight = 0;
            foreach ($rows as $r) {
                $daysAgo = ($nowTs - strtotime($r['match_date'])) / 86400;
                $weight = exp(-$daysAgo / $this->recencyHalfLife);
                $totalWeight += $weight;
                $h = (int)$r['home_score']; $a = (int)$r['away_score'];
                $isHomeActual = $r['home_team'] === $homeTeam;
                if ($isHomeActual) { if ($h > $a) $hw += $weight; elseif ($h === $a) $d += $weight; else $aw += $weight; }
                else { if ($a > $h) $hw += $weight; elseif ($a === $h) $d += $weight; else $aw += $weight; }
                $totalG += ($h + $a) * $weight;
                if ($h > 0 && $a > 0) $btts += $weight;
            }
            $n = max(1, $totalWeight);
            return [
                'matches' => round($totalWeight, 1), 'home_wins' => round($hw, 1), 'draws' => round($d, 1), 'away_wins' => round($aw, 1),
                'avg_goals' => round($totalG / $n, 2), 'btts_rate' => round($btts / $n * 100, 1),
            ];
        } catch (Exception $e) {
            error_log("BayesianModel::getHeadToHead: " . $e->getMessage());
            return $default;
        }
    }

    private function getTeamStanding($team, $league) {
        if (!$this->db || !$league) return null;
        try {
            $teamId = $this->getTeamId($team);
            if ($teamId) {
                $stmt = $this->db->prepare("
                    SELECT position, played, points, goal_diff
                    FROM league_standings
                    WHERE team_id = ? AND updated_at = CURDATE()
                    LIMIT 1
                ");
                $stmt->execute([$teamId]);
                $r = $stmt->fetch();
                if ($r) return $r;
            }
            $stmt = $this->db->prepare("
                SELECT position, played, points, goal_diff
                FROM league_standings
                WHERE team = ? AND (league = ? OR league LIKE ? OR ? LIKE CONCAT('%', league, '%'))
                  AND updated_at = CURDATE()
                LIMIT 1
            ");
            $stmt->execute([$team, $league, '%' . $league . '%', $league]);
            $r = $stmt->fetch();
            if ($r) return $r;
            $norm = $this->normalizeName($team);
            $stmt2 = $this->db->prepare("
                SELECT position, played, points, goal_diff
                FROM league_standings
                WHERE (league = ? OR league LIKE ? OR ? LIKE CONCAT('%', league, '%'))
                  AND updated_at = CURDATE()
                ORDER BY ABS(position) ASC
            ");
            $stmt2->execute([$league, '%' . $league . '%', $league]);
            foreach ($stmt2->fetchAll() as $s) {
                if ($this->bigramSimilarity($norm, $this->normalizeName($s['team'])) > 0.6) {
                    return $s;
                }
            }
            return null;
        } catch (Exception $e) {
            error_log("BayesianModel::getTeamStanding: " . $e->getMessage());
            return null;
        }
    }

    private function predictOverUnder($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays) {
        $avgGoals = $prior['avg_goals'];
        $homeGFperGame = $homeForm['matches'] > 0 ? $homeForm['gf'] / $homeForm['matches'] : $avgGoals / 2;
        $homeGAperGame = $homeForm['matches'] > 0 ? $homeForm['ga'] / $homeForm['matches'] : $avgGoals / 2;
        $awayGFperGame = $awayForm['matches'] > 0 ? $awayForm['gf'] / $awayForm['matches'] : $avgGoals / 2;
        $awayGAperGame = $awayForm['matches'] > 0 ? $awayForm['ga'] / $awayForm['matches'] : $avgGoals / 2;

        $homeExpected = ($homeGFperGame + $awayGAperGame) / 2;
        $awayExpected = ($awayGFperGame + $homeGAperGame) / 2;

        if ($h2h['matches'] >= 2) {
            $h2hAvg = $h2h['avg_goals'];
            $homeExpected = ($homeExpected * 3 + $h2hAvg / 2) / 4;
            $awayExpected = ($awayExpected * 3 + $h2hAvg / 2) / 4;
        }

        $expectedTotal = $homeExpected + $awayExpected;
        $over15 = 1 - $this->poissonCdf($expectedTotal, 1);
        $over25 = 1 - $this->poissonCdf($expectedTotal, 2);
        $over35 = 1 - $this->poissonCdf($expectedTotal, 3);

        return [
            'expected_total_goals' => round($expectedTotal, 2),
            'home_expected' => round($homeExpected, 2),
            'away_expected' => round($awayExpected, 2),
            'over_15' => round($over15 * 100, 1),
            'over_25' => round($over25 * 100, 1),
            'over_35' => round($over35 * 100, 1),
            'under_15' => round((1 - $over15) * 100, 1),
            'under_25' => round((1 - $over25) * 100, 1),
            'under_35' => round((1 - $over35) * 100, 1),
        ];
    }

    private function predictBTTS($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays) {
        $bttsPrior = $prior['btts_rate'];
        if ($homeForm['matches'] > 0) {
            $homeScored = $homeForm['gf'] > 0 ? min(1, ($homeForm['wins'] + $homeForm['draws'] / 2) / $homeForm['matches']) : 0;
            $homeConceded = $homeForm['ga'] > 0 ? min(1, ($homeForm['losses'] + $homeForm['draws'] / 2) / $homeForm['matches']) : 0;
        } else { $homeScored = $bttsPrior; $homeConceded = $bttsPrior; }

        if ($awayForm['matches'] > 0) {
            $awayScored = $awayForm['gf'] > 0 ? min(1, ($awayForm['wins'] + $awayForm['draws'] / 2) / $awayForm['matches']) : 0;
            $awayConceded = $awayForm['ga'] > 0 ? min(1, ($awayForm['losses'] + $awayForm['draws'] / 2) / $awayForm['matches']) : 0;
        } else { $awayScored = $bttsPrior; $awayConceded = $bttsPrior; }

        $k = $this->priorStrength;
        $homeBTTS = $homeScored * $awayConceded;
        $awayBTTS = $awayScored * $homeConceded;
        $bttsPost = ($bttsPrior * $k + $h2h['btts_rate'] / 100 * $h2h['matches'] + $homeBTTS + $awayBTTS) / ($k + $h2h['matches'] + 2);
        $bttsPost = min(0.95, max(0.05, $bttsPost));

        return ['yes' => round($bttsPost * 100, 1), 'no' => round((1 - $bttsPost) * 100, 1)];
    }

    private function poissonCdf($lambda, $k) {
        $sum = 0;
        for ($i = 0; $i <= $k; $i++) {
            $sum += exp(-$lambda) * pow($lambda, $i) / $this->factorial($i);
        }
        return $sum;
    }

    private function factorial($n) {
        if ($n <= 1) return 1;
        $r = 1;
        for ($i = 2; $i <= $n; $i++) $r *= $i;
        return $r;
    }

    private function getRecommendedPick($ph, $pd, $pa, $ou, $btts) {
        $picks = [];
        $bestOdds = max($ph, $pd, $pa);
        $bestLabel = $ph === $bestOdds ? '1' : ($pd === $bestOdds ? 'X' : '2');
        if ($bestOdds > 0.40) $picks[] = ['type' => $bestLabel, 'prob' => round($bestOdds * 100, 1)];

        foreach ([['1X', $ph + $pd], ['X2', $pd + $pa], ['12', $ph + $pa]] as $c) {
            if ($c[1] > 0.75) $picks[] = ['type' => $c[0], 'prob' => round($c[1] * 100, 1)];
        }
        if ($ou['over_25'] > 55) $picks[] = ['type' => 'Over 2.5', 'prob' => $ou['over_25']];
        elseif ($ou['under_25'] > 55) $picks[] = ['type' => 'Under 2.5', 'prob' => $ou['under_25']];
        if ($ou['over_15'] > 70) $picks[] = ['type' => 'Over 1.5', 'prob' => $ou['over_15']];
        elseif ($ou['under_35'] > 60) $picks[] = ['type' => 'Under 3.5', 'prob' => $ou['under_35']];
        if ($btts['yes'] > 58) $picks[] = ['type' => 'GG', 'prob' => $btts['yes']];
        elseif ($btts['no'] > 58) $picks[] = ['type' => 'NG', 'prob' => $btts['no']];

        return $picks;
    }

    public function tunePriorStrength($kMin = 5, $kMax = 100, $step = 5) {
        $bestK = $this->priorStrength;
        $bestErr = PHP_FLOAT_MAX;
        $results = [];
        if (!$this->db) return $bestK;

        $histStmt = $this->db->query("
            SELECT bp.prob_1, bp.prob_x, bp.prob_2, bp.over_25, bp.under_25, bp.btts_yes, bp.btts_no,
                   bp.home_team, bp.away_team, bp.league,
                   mr.home_score, mr.away_score
            FROM bayesian_predictions bp
            JOIN match_results mr ON bp.match_name = CONCAT(mr.home_team, ' vs ', mr.away_team)
                AND bp.match_date = mr.match_date
            WHERE bp.result = 'pending'
              AND mr.home_score IS NOT NULL
              AND bp.match_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              AND bp.match_date <= CURDATE()
            LIMIT 500
        ");
        $testCases = $histStmt->fetchAll();
        if (count($testCases) < 20) return $this->priorStrength;

        for ($k = $kMin; $k <= $kMax; $k += $step) {
            $this->priorStrength = $k;
            $total = 0; $correct = 0;
            foreach ($testCases as $tc) {
                $total++;
                $pred = $this->predict($tc['home_team'], $tc['away_team'], $tc['league'], 365);
                $pick = $pred['recommended_pick'][0]['type'] ?? null;
                if (!$pick) continue;
                $hs = (int)$tc['home_score'];
                $as = (int)$tc['away_score'];
                $correctPick = false;
                switch ($pick) {
                    case '1': $correctPick = $hs > $as; break;
                    case 'X': $correctPick = $hs === $as; break;
                    case '2': $correctPick = $hs < $as; break;
                    case '1X': $correctPick = $hs >= $as; break;
                    case 'X2': $correctPick = $hs <= $as; break;
                    case '12': $correctPick = $hs !== $as; break;
                    case 'Over 2.5': $correctPick = ($hs + $as) > 2.5; break;
                    case 'Under 2.5': $correctPick = ($hs + $as) < 2.5; break;
                    case 'Over 1.5': $correctPick = ($hs + $as) > 1.5; break;
                    case 'Under 3.5': $correctPick = ($hs + $as) < 3.5; break;
                    case 'GG': $correctPick = $hs > 0 && $as > 0; break;
                    case 'NG': $correctPick = $hs === 0 || $as === 0; break;
                }
                if ($correctPick) $correct++;
            }
            $err = $total > 0 ? 1 - ($correct / $total) : 1;
            $results[$k] = ['err' => round($err, 4), 'correct' => $correct, 'total' => $total];
            if ($err < $bestErr) { $bestErr = $err; $bestK = $k; }
        }
        $this->priorStrength = $bestK;
        return ['best_k' => $bestK, 'best_err' => round($bestErr, 4), 'sweep' => $results];
    }

    public function setPriorStrength($k) {
        $this->priorStrength = max(1, min(500, (int)$k));
    }

    public function getPriorStrength() {
        return $this->priorStrength;
    }

    public function setRecencyHalfLife($days) {
        $this->recencyHalfLife = max(14, min(730, (int)$days));
    }

    public function getRecencyHalfLife() {
        return $this->recencyHalfLife;
    }

    public function getAccuracyTrend($days = 30) {
        if (!$this->db) return [];
        try {
            $start = date('Y-m-d', strtotime("-{$days} days"));
            return $this->db->query("
                SELECT
                    DATE(match_date) as day,
                    COUNT(*) as total,
                    SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) as correct,
                    ROUND(SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as accuracy
                FROM bayesian_predictions
                WHERE result IN ('correct','incorrect')
                  AND match_date >= '$start'
                GROUP BY DATE(match_date)
                ORDER BY day ASC
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function getDataQualitySummary($league = null) {
        if (!$this->db) return [];
        try {
            $where = $league ? "WHERE league = ?" : "";
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_matches, COUNT(DISTINCT league) as leagues,
                       COUNT(DISTINCT home_team) as teams,
                       MIN(match_date) as earliest, MAX(match_date) as latest
                FROM match_results $where
            ");
            $stmt->execute($league ? [$league] : []);
            return $stmt->fetch();
        } catch (Exception $e) { return []; }
    }

    public function getAvailableLeagues() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT league, COUNT(*) as matches,
                       MIN(match_date) as from_date, MAX(match_date) as to_date
                FROM match_results GROUP BY league ORDER BY matches DESC
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function getRecentPredictions($limit = 20) {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT bp.*,
                       CASE WHEN bp.result = 'correct' THEN 1 ELSE 0 END as is_correct
                FROM bayesian_predictions bp
                ORDER BY bp.id DESC LIMIT $limit
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }
}
