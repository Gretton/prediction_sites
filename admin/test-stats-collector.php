<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

$action = $_GET['action'] ?? 'view';
$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));

$stats = null;
$recentDates = [];
$totalRows = 0;
$leagues = [];

try {
    $totalRows = $db->query("SELECT COUNT(*) FROM match_statistics")->fetchColumn();
    $recentDates = $db->query("SELECT match_date, COUNT(*) as cnt FROM match_statistics GROUP BY match_date ORDER BY match_date DESC LIMIT 14")->fetchAll();
    $leagues = $db->query("SELECT league_name, COUNT(*) as cnt FROM match_statistics GROUP BY league_name ORDER BY cnt DESC LIMIT 20")->fetchAll();
    if ($action === 'view') {
        $stmt = $db->prepare("SELECT * FROM match_statistics WHERE match_date = ? ORDER BY league_name, home_team_api");
        $stmt->execute([$date]);
        $stats = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$logContent = null;
$logFile = __DIR__ . '/../logs/stats_collector_' . $date . '.log';
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Match Stats Collector - Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #111318 0%, #1c2130 100%); min-height: 100vh; color: #e2e8f0; }
        .card { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(6,182,212,0.06)); border: 1px solid rgba(139,92,246,0.25); }
        .stat-card { background: rgba(22,27,34,0.7); border: 1px solid rgba(139,92,246,0.2); border-radius: 12px; padding: 16px; }
        .stat-big { font-size: 1.8rem; font-weight: 700; }
        .stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .badge-league { background: rgba(139,92,246,0.3); border: 1px solid rgba(139,92,246,0.4); }
        table { font-size: 0.85rem; }
        th { background: rgba(139,92,246,0.15); position: sticky; top: 0; }
        td { vertical-align: middle; }
        .log-box { background: #0d1117; border: 1px solid rgba(139,92,246,0.2); border-radius: 8px; padding: 12px; font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.78rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; color: #94a3b8; }
        .form-control, .form-select { background: rgba(22,27,34,0.7); border-color: rgba(139,92,246,0.3); color: #e2e8f0; }
        .form-control:focus, .form-select:focus { border-color: rgba(139,92,246,0.6); box-shadow: 0 0 0 0.2rem rgba(139,92,246,0.15); }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Match Stats Collector</h4>
            <small class="text-muted">Daily match statistics from API-Football</small>
        </div>
        <a href="../dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Dashboard</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-big text-purple"><?= $totalRows ?></div>
                <div class="stat-label">Total Matches Collected</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-big text-info"><?= count($recentDates) > 0 ? $recentDates[0]['match_date'] : 'N/A' ?></div>
                <div class="stat-label">Last Collection Date</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-big text-success"><?= count($recentDates) > 0 ? $recentDates[0]['cnt'] : 0 ?></div>
                <div class="stat-label">Matches on Last Date</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-big text-warning"><?= count($leagues) ?></div>
                <div class="stat-label">Leagues Covered</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <div class="card p-3">
                <h6 class="mb-3"><i class="fas fa-calendar-alt me-2"></i>Collections by Date</h6>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($recentDates as $rd): ?>
                        <a href="?action=view&date=<?= $rd['match_date'] ?>" class="btn btn-sm <?= $rd['match_date'] === $date ? 'btn-purple' : 'btn-outline-secondary' ?>" style="<?= $rd['match_date'] === $date ? 'background:rgba(139,92,246,0.4);border-color:rgba(139,92,246,0.6);' : '' ?>">
                            <?= $rd['match_date'] ?> <span class="badge bg-dark ms-1"><?= $rd['cnt'] ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($recentDates)): ?>
                        <span class="text-muted">No data collected yet. Run the collector first.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3">
                <h6 class="mb-3"><i class="fas fa-trophy me-2"></i>Top Leagues</h6>
                <?php foreach (array_slice($leagues, 0, 8) as $l): ?>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small><?= htmlspecialchars($l['league_name']) ?></small>
                        <span class="badge badge-league"><?= $l['cnt'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card p-3 mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <h6 class="mb-0"><i class="fas fa-table me-2"></i>Match Statistics for: </h6>
            <form class="d-flex gap-2" method="get">
                <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="form-control form-control-sm" style="width:160px">
                <button type="submit" class="btn btn-sm btn-purple" style="background:rgba(139,92,246,0.4);border-color:rgba(139,92,246,0.6);">View</button>
            </form>
            <span class="badge bg-info ms-2"><?= count($stats) ?> matches</span>
        </div>

        <?php if (empty($stats)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-inbox fa-2x mb-2"></i><br>
                No statistics for this date. Run: <code>php cron/collect_match_stats.php --date <?= $date ?> --test</code>
            </div>
        <?php else: ?>
            <div class="table-responsive" style="max-height:600px; overflow-y:auto;">
                <table class="table table-dark table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>League</th>
                            <th>Home</th>
                            <th>Score</th>
                            <th>Away</th>
                            <th title="Shots On Target"><i class="fas fa-bullseye"></i> SOT</th>
                            <th title="Total Shots"><i class="fas fa-futbol"></i> Shots</th>
                            <th title="Corners"><i class="fas fa-flag"></i> Corners</th>
                            <th title="Fouls"><i class="fas fa-hand-fist"></i> Fouls</th>
                            <th title="Yellow Cards"><i class="fas fa-square" style="color:#fbbf24"></i> YC</th>
                            <th title="Red Cards"><i class="fas fa-square" style="color:#ef4444"></i> RC</th>
                            <th title="Ball Possession"><i class="fas fa-circle-half-stroke"></i> Poss</th>
                            <th title="Passes Accurate"><i class="fas fa-check"></i> Pass</th>
                            <th title="xG"><i class="fas fa-brain"></i> xG</th>
                            <th>Referee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats as $s): ?>
                            <tr>
                                <td><small class="text-muted"><?= htmlspecialchars($s['league_name']) ?></small></td>
                                <td><strong><?= htmlspecialchars($s['home_team_api']) ?></strong></td>
                                <td class="text-center"><strong><?= $s['home_score'] ?>-<?= $s['away_score'] ?></strong></td>
                                <td><strong><?= htmlspecialchars($s['away_team_api']) ?></strong></td>
                                <td class="text-center"><?= $s['home_shots_on_goal'] ?? '-' ?> / <?= $s['away_shots_on_goal'] ?? '-' ?></td>
                                <td class="text-center"><?= $s['home_total_shots'] ?? '-' ?> / <?= $s['away_total_shots'] ?? '-' ?></td>
                                <td class="text-center"><?= $s['home_corner_kicks'] ?? '-' ?> / <?= $s['away_corner_kicks'] ?? '-' ?></td>
                                <td class="text-center"><?= $s['home_fouls'] ?? '-' ?> / <?= $s['away_fouls'] ?? '-' ?></td>
                                <td class="text-center"><?= $s['home_yellow_cards'] ?? '-' ?> / <?= $s['away_yellow_cards'] ?? '-' ?></td>
                                <td class="text-center"><?= ($s['home_red_cards'] ?? 0) > 0 || ($s['away_red_cards'] ?? 0) > 0 ? ($s['home_red_cards'] ?? '0') . ' / ' . ($s['away_red_cards'] ?? '0') : '-' ?></td>
                                <td class="text-center"><small><?= $s['home_ball_possession'] ?? '-' ?> / <?= $s['away_ball_possession'] ?? '-' ?></small></td>
                                <td class="text-center"><small><?= $s['home_passes_accurate'] ?? '-' ?> / <?= $s['away_passes_accurate'] ?? '-' ?></small></td>
                                <td class="text-center"><small><?= $s['home_expected_goals'] ?? '-' ?> / <?= $s['away_expected_goals'] ?? '-' ?></small></td>
                                <td><small class="text-muted"><?= htmlspecialchars($s['referee'] ?? '-') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($logContent): ?>
    <div class="card p-3 mb-4">
        <h6 class="mb-3"><i class="fas fa-terminal me-2"></i>Collector Log (<?= $date ?>)</h6>
        <div class="log-box"><?= htmlspecialchars($logContent) ?></div>
    </div>
    <?php endif; ?>

    <div class="card p-3">
        <h6 class="mb-3"><i class="fas fa-code me-2"></i>How to Run</h6>
        <div class="log-box">php cron/collect_match_stats.php --date <?= $date ?> --test    <span class="text-muted"># Test mode (5 matches, short sleep)</span>
php cron/collect_match_stats.php --date <?= $date ?>             <span class="text-muted"># Full collection (all matches, rate-limited)</span>
php cron/collect_match_stats.php                         <span class="text-muted"># Yesterday's matches</span></div>
    </div>
</div>
</body>
</html>
