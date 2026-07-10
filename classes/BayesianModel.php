<?php
require_once __DIR__ . '/../config.php';

class BayesianModel {
    private $db;
    private $priorStrength = 20;

    private $leaguePriors = [];

    public function __construct() {
        $this->db = getDB();
        $this->computeLeaguePriors();
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

        $homeWins = $homeForm['wins'];
        $homeMatches = $homeForm['matches'];
        $awayWins = $awayForm['wins'];
        $awayLosses = $awayForm['losses'];
        $drawMatches = $homeForm['draws'];

        $k = $this->priorStrength;

        // Posterior for home win: Beta(prior_hw*k + home_wins_as_home, (1-prior_hw)*k + home_matches - home_wins)
        $alphaHome = $prior['home_win_rate'] * $k + $homeWins;
        $betaHome = (1 - $prior['home_win_rate']) * $k + ($homeMatches - $homeWins);
        $postHome = $alphaHome / ($alphaHome + $betaHome);

        // Posterior for draw: Beta(prior_draw*k + draws_home, (1-prior_draw)*k + home_matches - draws_home)
        $alphaDraw = $prior['draw_rate'] * $k + $drawMatches;
        $betaDraw = (1 - $prior['draw_rate']) * $k + ($homeMatches - $drawMatches);
        // Also incorporate away team's draws when away
        $awayDrawMatches = $awayForm['draws'];
        $awayMatchCount = $awayForm['matches'];
        $alphaDraw += $awayDrawMatches;
        $betaDraw += ($awayMatchCount - $awayDrawMatches);
        $postDraw = $alphaDraw / ($alphaDraw + $betaDraw);

        // Posterior for away win: Beta(prior_aw*k + away_wins_as_away, (1-prior_aw)*k + away_matches - away_wins)
        $alphaAway = $prior['away_win_rate'] * $k + $awayWins;
        $betaAway = (1 - $prior['away_win_rate']) * $k + ($awayMatchCount - $awayWins);
        $postAway = $alphaAway / ($alphaAway + $betaAway);

        // Normalize so they sum to 1
        $total = $postHome + $postDraw + $postAway;
        if ($total > 0) {
            $postHome /= $total;
            $postDraw /= $total;
            $postAway /= $total;
        }

        // H2H adjustment (weighted 20% if enough data)
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

        // Over/Under prediction using Poisson-gamma conjugate
        $ou = $this->predictOverUnder($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays);

        // BTTS prediction
        $btts = $this->predictBTTS($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays);

        // DC probabilities
        $dcHomeDraw = $postHome + $postDraw;
        $dcAwayDraw = $postAway + $postDraw;
        $dcHomeAway = $postHome + $postAway;

        // Confidence: how much the posterior differs from uniform
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

    private function getTeamHistory($team, $league, $isHome, $lookbackDays) {
        $default = ['matches' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'form' => []];
        if (!$this->db) return $default;

        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            if ($isHome) {
                $stmt = $this->db->prepare("
                    SELECT home_score, away_score, match_date
                    FROM match_results
                    WHERE home_team = ? AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC
                    LIMIT 30
                ");
                $stmt->execute([$team, $lookback]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT home_score, away_score, match_date
                    FROM match_results
                    WHERE away_team = ? AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC
                    LIMIT 30
                ");
                $stmt->execute([$team, $lookback]);
            }
            $rows = $stmt->fetchAll();
            if (empty($rows)) return $default;

            $wins = 0; $draws = 0; $losses = 0; $gf = 0; $ga = 0; $form = [];
            foreach ($rows as $r) {
                $h = (int)$r['home_score'];
                $a = (int)$r['away_score'];
                if ($isHome) {
                    $gf += $h; $ga += $a;
                    if ($h > $a) { $wins++; $form[] = 'W'; }
                    elseif ($h === $a) { $draws++; $form[] = 'D'; }
                    else { $losses++; $form[] = 'L'; }
                } else {
                    $gf += $a; $ga += $h;
                    if ($a > $h) { $wins++; $form[] = 'W'; }
                    elseif ($a === $h) { $draws++; $form[] = 'D'; }
                    else { $losses++; $form[] = 'L'; }
                }
            }

            return [
                'matches' => count($rows),
                'wins' => $wins, 'draws' => $draws, 'losses' => $losses,
                'gf' => $gf, 'ga' => $ga,
                'form' => implode('', array_slice($form, 0, 5)),
                'form_rating' => count($rows) > 0 ? round(($wins * 3 + $draws) / (count($rows) * 3) * 10, 1) : 5.0,
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
            $stmt = $this->db->prepare("
                SELECT home_team, home_score, away_score
                FROM match_results
                WHERE ((home_team = ? AND away_team = ?) OR (home_team = ? AND away_team = ?))
                  AND match_date >= ? AND match_date <= CURDATE()
                  AND home_score IS NOT NULL AND away_score IS NOT NULL
                ORDER BY match_date DESC
                LIMIT 10
            ");
            $stmt->execute([$homeTeam, $awayTeam, $awayTeam, $homeTeam, $lookback]);
            $rows = $stmt->fetchAll();
            if (empty($rows)) return $default;

            $hw = 0; $d = 0; $aw = 0; $totalG = 0; $btts = 0;
            foreach ($rows as $r) {
                $h = (int)$r['home_score'];
                $a = (int)$r['away_score'];
                $isHomeActual = $r['home_team'] === $homeTeam;
                if ($isHomeActual) {
                    if ($h > $a) $hw++; elseif ($h === $a) $d++; else $aw++;
                } else {
                    if ($a > $h) $hw++; elseif ($a === $h) $d++; else $aw++;
                }
                $totalG += $h + $a;
                if ($h > 0 && $a > 0) $btts++;
            }
            $n = count($rows);
            return [
                'matches' => $n,
                'home_wins' => $hw, 'draws' => $d, 'away_wins' => $aw,
                'avg_goals' => round($totalG / $n, 2),
                'btts_rate' => round($btts / $n * 100, 1),
            ];
        } catch (Exception $e) {
            error_log("BayesianModel::getHeadToHead: " . $e->getMessage());
            return $default;
        }
    }

    private function predictOverUnder($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays) {
        $avgGoals = $prior['avg_goals'];

        // Team-specific scoring rates
        $homeGFperGame = $homeForm['matches'] > 0 ? $homeForm['gf'] / $homeForm['matches'] : $avgGoals / 2;
        $homeGAperGame = $homeForm['matches'] > 0 ? $homeForm['ga'] / $homeForm['matches'] : $avgGoals / 2;
        $awayGFperGame = $awayForm['matches'] > 0 ? $awayForm['gf'] / $awayForm['matches'] : $avgGoals / 2;
        $awayGAperGame = $awayForm['matches'] > 0 ? $awayForm['ga'] / $awayForm['matches'] : $avgGoals / 2;

        // Expected goals (Poisson rates)
        $homeExpected = ($homeGFperGame + $awayGAperGame) / 2;
        $awayExpected = ($awayGFperGame + $homeGAperGame) / 2;

        // H2H adjustment
        if ($h2h['matches'] >= 2) {
            $h2hAvg = $h2h['avg_goals'];
            $homeExpected = ($homeExpected * 3 + $h2hAvg / 2) / 4;
            $awayExpected = ($awayExpected * 3 + $h2hAvg / 2) / 4;
        }

        $expectedTotal = $homeExpected + $awayExpected;

        // Poisson probability for over X goals
        // P(goals > threshold) = 1 - sum_{k=0}^{floor(threshold)} (λ^k * e^{-λ}) / k!
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
        // BTTS probability based on scoring/ conceding rates
        $bttsPrior = $prior['btts_rate'];

        if ($homeForm['matches'] > 0) {
            $homeScored = $homeForm['gf'] > 0 ? min(1, ($homeForm['wins'] + $homeForm['draws'] / 2) / $homeForm['matches']) : 0;
            $homeConceded = $homeForm['ga'] > 0 ? min(1, ($homeForm['losses'] + $homeForm['draws'] / 2) / $homeForm['matches']) : 0;
        } else { $homeScored = $bttsPrior; $homeConceded = $bttsPrior; }

        if ($awayForm['matches'] > 0) {
            $awayScored = $awayForm['gf'] > 0 ? min(1, ($awayForm['wins'] + $awayForm['draws'] / 2) / $awayForm['matches']) : 0;
            $awayConceded = $awayForm['ga'] > 0 ? min(1, ($awayForm['losses'] + $awayForm['draws'] / 2) / $awayForm['matches']) : 0;
        } else { $awayScored = $bttsPrior; $awayConceded = $bttsPrior; }

        $homeBTTS = $homeScored * $awayConceded;
        $awayBTTS = $awayScored * $homeConceded;

        $k = $this->priorStrength;
        $bttsPost = ($bttsPrior * $k + $h2h['btts_rate'] / 100 * $h2h['matches'] + $homeBTTS + $awayBTTS) / ($k + $h2h['matches'] + 2);
        $bttsPost = min(0.95, max(0.05, $bttsPost));

        return [
            'yes' => round($bttsPost * 100, 1),
            'no' => round((1 - $bttsPost) * 100, 1),
        ];
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

        // Best 1X2
        $bestOdds = max($ph, $pd, $pa);
        $bestLabel = $ph === $bestOdds ? '1' : ($pd === $bestOdds ? 'X' : '2');
        if ($bestOdds > 0.40) {
            $picks[] = ['type' => $bestLabel, 'prob' => round($bestOdds * 100, 1)];
        }

        // DC if combined > 75%
        $combo = [
            ['1X', $ph + $pd], ['X2', $pd + $pa], ['12', $ph + $pa],
        ];
        foreach ($combo as $c) {
            if ($c[1] > 0.75) {
                $picks[] = ['type' => $c[0], 'prob' => round($c[1] * 100, 1)];
            }
        }

        // Over/Under
        if ($ou['over_25'] > 55) $picks[] = ['type' => 'Over 2.5', 'prob' => $ou['over_25']];
        elseif ($ou['under_25'] > 55) $picks[] = ['type' => 'Under 2.5', 'prob' => $ou['under_25']];
        if ($ou['over_15'] > 70) $picks[] = ['type' => 'Over 1.5', 'prob' => $ou['over_15']];
        elseif ($ou['under_35'] > 60) $picks[] = ['type' => 'Under 3.5', 'prob' => $ou['under_35']];

        // BTTS
        if ($btts['yes'] > 58) $picks[] = ['type' => 'GG', 'prob' => $btts['yes']];
        elseif ($btts['no'] > 58) $picks[] = ['type' => 'NG', 'prob' => $btts['no']];

        return $picks;
    }

    public function getDataQualitySummary($league = null) {
        if (!$this->db) return [];
        try {
            $where = $league ? "WHERE league = ?" : "";
            $params = $league ? [$league] : [];
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_matches,
                       COUNT(DISTINCT league) as leagues,
                       COUNT(DISTINCT home_team) as teams,
                       MIN(match_date) as earliest,
                       MAX(match_date) as latest
                FROM match_results
                $where
            ");
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (Exception $e) {
            return [];
        }
    }

    public function getAvailableLeagues() {
        if (!$this->db) return [];
        try {
            $stmt = $this->db->query("
                SELECT league, COUNT(*) as matches,
                       MIN(match_date) as from_date, MAX(match_date) as to_date
                FROM match_results
                GROUP BY league
                ORDER BY matches DESC
            ");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
}
