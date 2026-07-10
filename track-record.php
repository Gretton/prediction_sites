<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
require_once __DIR__ . '/includes/signals_engine.php';
logPageVisit('track-record');

$db = getDB();
if (!$db) { echo "DB unavailable"; exit; }

$db->exec("CREATE TABLE IF NOT EXISTS `pick_settlements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `web_pick_id` INT NOT NULL,
    `match_name` VARCHAR(255) NOT NULL,
    `pick_value` VARCHAR(100) NOT NULL,
    `odds` DECIMAL(6,2) DEFAULT NULL,
    `home_score` INT DEFAULT NULL,
    `away_score` INT DEFAULT NULL,
    `result` ENUM('won','lost','pending','void') NOT NULL DEFAULT 'pending',
    `settlement_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_pick_match` (`web_pick_id`, `settlement_date`),
    INDEX `idx_result` (`result`),
    INDEX `idx_date` (`settlement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$today = date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Overall stats
$totalStmt = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN result='won' THEN 1 ELSE 0 END) as won, SUM(CASE WHEN result='lost' THEN 1 ELSE 0 END) as lost, SUM(CASE WHEN result='pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN result='void' THEN 1 ELSE 0 END) as voided FROM pick_settlements");
$overall = $totalStmt->fetch();
$total = (int)$overall['total'];
$won = (int)$overall['won'];
$lost = (int)$overall['lost'];
$pending = (int)$overall['pending'];
$voided = (int)$overall['voided'];
$settled = $won + $lost;
$winRate = $settled > 0 ? round($won / $settled * 100, 1) : 0;

// Stats by pick type
$byType = $db->query("SELECT ps.pick_value, COUNT(*) as total, SUM(CASE WHEN result='won' THEN 1 ELSE 0 END) as won, SUM(CASE WHEN result='lost' THEN 1 ELSE 0 END) as lost FROM pick_settlements ps GROUP BY ps.pick_value ORDER BY total DESC")->fetchAll();

// Recent settlements (paginated)
$totalRecent = $db->query("SELECT COUNT(*) FROM pick_settlements")->fetchColumn();
$recent = $db->query("SELECT ps.*, wp.league, wp.detected_at FROM pick_settlements ps LEFT JOIN web_picks wp ON ps.web_pick_id = wp.id ORDER BY ps.id DESC LIMIT $perPage OFFSET $offset")->fetchAll();
$totalPages = max(1, ceil($totalRecent / $perPage));

// ROI
$roiData = $db->query("SELECT odds, result FROM pick_settlements WHERE result IN ('won','lost')")->fetchAll();
$totalStake = count($roiData);
$totalReturn = 0;
foreach ($roiData as $r) {
    if ($r['result'] === 'won') $totalReturn += (float)($r['odds'] ?? 1.0);
}
$roi = $totalStake > 0 ? round(($totalReturn - $totalStake) / $totalStake * 100, 1) : 0;
$profit = $totalReturn - $totalStake;

// Win streak
$streak = $db->query("SELECT result FROM pick_settlements WHERE result IN ('won','lost') ORDER BY id DESC LIMIT 20")->fetchAll();
$winStreak = 0;
foreach ($streak as $s) {
    if ($s['result'] === 'won') $winStreak++;
    else break;
}

// Recent results for sparkline (last 10)
$recent10 = $db->query("SELECT result FROM pick_settlements WHERE result IN ('won','lost') ORDER BY id DESC LIMIT 10")->fetchAll();
$sparkline = '';
foreach (array_reverse($recent10) as $r) {
    $sparkline .= $r['result'] === 'won' ? '<span style="color:#22C55E;font-weight:800;">&#9679;</span>' : '<span style="color:#EF4444;font-weight:800;">&#9679;</span>';
}

function getResultBadge($result) {
    return match($result) {
        'won' => '<span class="badge" style="background:rgba(34,197,94,0.15);color:#22C55E;padding:4px 12px;border-radius:20px;font-weight:700;font-size:0.75rem;">WON</span>',
        'lost' => '<span class="badge" style="background:rgba(239,68,68,0.15);color:#EF4444;padding:4px 12px;border-radius:20px;font-weight:700;font-size:0.75rem;">LOST</span>',
        'pending' => '<span class="badge" style="background:rgba(251,191,36,0.15);color:#FBBF24;padding:4px 12px;border-radius:20px;font-weight:700;font-size:0.75rem;">PENDING</span>',
        'void' => '<span class="badge" style="background:rgba(148,163,184,0.12);color:#94a3b8;padding:4px 12px;border-radius:20px;font-weight:700;font-size:0.75rem;">VOID</span>',
        default => '<span class="badge bg-secondary">?</span>',
    };
}

$user = getCurrentUser();
$premium = $user ? getPremiumStatus() : null;
$pageTitle = 'Recent Results — Verified Performance | PREDIXA';
$pageDesc = 'Verified win/loss performance of all premium picks. Track our system\'s accuracy across 1X2, Double Chance, Over/Under, and BTTS markets.';
$canonical = (defined('SITE_URL') ? SITE_URL : 'https://predixa.co.tz') . '/track-record';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
<meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PREDIXA">
<link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
<title>Recent Results — Verified Performance | PREDIXA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --primary: #8B5CF6; --primary-dark: #7C3AED; --primary-light: #A78BFA; --accent: #06B6D4; --accent-dark: #0891B2; --secondary: #161b22; --text-light: #e0e0e0; --text-muted: #8b949e; --border-color: #2a2e35; }
body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #111318 0%, #1c2130 100%); color: var(--text-light); min-height: 100vh; }
.content-area { background: rgba(6, 182, 212, 0.06); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding-bottom: 40px; }
a { color: var(--primary-light); text-decoration: none; transition: all 0.3s; }
a:hover { color: var(--accent); }
.navbar { background: rgba(15, 17, 21, 0.95); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border-color); padding: 12px 0; }
.navbar-brand { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.nav-link { color: var(--text-muted) !important; font-weight: 600; padding: 8px 16px; border-radius: 6px; transition: all 0.3s; }
.nav-link:hover { color: var(--primary) !important; }
.page-header { padding: 110px 0 30px; }
.page-header h1 { font-weight: 800; font-size: 2rem; }
.btn-outline-premium { background: transparent; color: var(--primary); border: 2px solid var(--primary); padding: 12px 30px; font-weight: 600; border-radius: 8px; transition: all 0.3s; text-decoration: none; display: inline-block; }
.btn-outline-premium:hover { background: var(--primary); color: white; transform: translateY(-2px); text-decoration: none; }
.btn-premium { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; border: none; font-weight: 700; padding: 10px 24px; border-radius: 8px; cursor: pointer; transition: all 0.3s; font-size: 0.85rem; text-decoration: none; display: inline-block; }
.btn-premium:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--accent-dark) 100%); color: white; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(139,92,246,0.4); text-decoration: none; }
.stats-badge { background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 12px; padding: 16px; text-align: center; flex: 1; min-width: 120px; transition: all .2s; }
.stats-badge:hover { border-color: var(--primary); transform: translateY(-1px); }
.stats-badge .num { font-size: 1.8rem; font-weight: 800; line-height: 1; }
.stats-badge .lbl { font-size: 0.72rem; color: var(--text-muted); margin-top: 4px; font-weight: 500; }
.result-card { background: linear-gradient(135deg, rgba(139,92,246,0.15) 0%, rgba(6,182,212,0.08) 100%); border: 1px solid rgba(139,92,246,0.2); border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; transition: all .2s; }
.result-card:hover { border-color: var(--primary); transform: translateY(-1px); }
.result-card .match-name { font-weight: 700; font-size: 0.9rem; }
.result-card .pick-info { color: var(--text-muted); font-size: 0.8rem; }
.result-card .score { font-family: 'Courier New', monospace; font-weight: 700; font-size: 1.1rem; color: var(--text-light); }
.type-chip { display: inline-flex; align-items: center; gap: 4px; background: rgba(255,255,255,0.05); padding: 2px 10px; border-radius: 999px; font-size: 0.72rem; color: var(--text-muted); border: 1px solid var(--border-color); }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state i { font-size: 3rem; margin-bottom: 15px; color: var(--primary); }
.pagination-container { display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
.pagination-container a, .pagination-container span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; transition: all .2s; }
.pagination-container a { background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); color: var(--text-light); text-decoration: none; }
.pagination-container a:hover { border-color: var(--primary); color: var(--primary); }
.pagination-container .active { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; border: none; }
.pagination-container .disabled { opacity: 0.3; pointer-events: none; }
.pick-type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
.pick-type-item { background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; }
.pick-type-item .label { font-weight: 600; font-size: 0.82rem; }
.pick-type-item .sub { font-size: 0.72rem; color: var(--text-muted); }
.hero-section { padding: 3rem 0 1rem; text-align: center; }
.hero-section h1 { font-size: 2.2rem; font-weight: 800; background: linear-gradient(135deg, #fbbf24, #f59e0b); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
footer { border-top: 1px solid var(--border-color); padding: 40px 0; margin-top: 60px; background: rgba(15, 17, 21, 0.5); color: var(--text-muted); font-size: 0.85rem; }
footer h5, footer h6 { color: var(--text-light); font-weight: 700; }
footer a { color: var(--text-muted); text-decoration: none; transition: all 0.3s; }
footer a:hover { color: var(--primary); }
@media (max-width: 768px) { .page-header { padding: 90px 0 20px; } .page-header h1 { font-size: 1.5rem; } .stats-badge { min-width: 90px; } .stats-badge .num { font-size: 1.3rem; } }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container">
        <a class="navbar-brand" href="./"><i class="fas fa-futbol me-2"></i>PREDIXA</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="./">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="signals"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i>Smart Picks</a></li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="javascript:void(0)" role="button" data-bs-toggle="dropdown">Free Tools</a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="dropping-odds"><i class="fas fa-arrow-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                        <li><a class="dropdown-item" href="betting-school"><i class="fas fa-book-open me-1" style="color:#fff;"></i> Betting School</a></li>
                        <li><a class="dropdown-item" href="pikka"><i class="fas fa-futbol me-1" style="color:#6366F1;"></i> Pikka</a></li>
                    </ul>
                </li>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                    <?php if ($user): ?>
                    <a class="btn btn-outline-premium btn-sm" href="dashboard" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Dashboard</a>
                    <a class="btn btn-sm" href="logout" style="border:1px solid var(--border-color);color:var(--text-muted);padding:10px 16px;border-radius:6px;text-decoration:none;font-size:0.8rem;margin-left:6px;">Logout</a>
                    <?php else: ?>
                    <a class="btn btn-outline-premium btn-sm" href="login" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Login</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1><i class="fas fa-trophy me-2" style="color:#FBBF24;"></i>Recent Results</h1>
        <p style="color:var(--text-muted);font-size:1rem;max-width:700px;">Verified performance of our system picks — every win, loss, and void is recorded automatically from live match results. Below are the <strong style="color:#22C55E;">recent picks</strong> generated by our prediction engine.</p>
    </div>
</div>

<div class="content-area">
<div class="container pb-4 pt-4">

    <?php if ($total === 0): ?>
    <div class="empty-state">
        <i class="fas fa-trophy"></i>
        <h5>No Picks Recorded Yet</h5>
        <p class="mb-0">Track record will populate automatically once picks are settled. Results update after each match cycle via our automated settlement pipeline.</p>
    </div>
    <?php else: ?>

    <!-- Stats Badges -->
    <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
        <div class="stats-badge"><div class="num" style="color:var(--accent);"><?= $total ?></div><div class="lbl">Total Picks</div></div>
        <div class="stats-badge"><div class="num" style="color:#22C55E;"><?= $winRate ?>%</div><div class="lbl">Win Rate</div></div>
        <div class="stats-badge"><div class="num" style="color:<?= $profit >= 0 ? '#22C55E' : '#EF4444' ?>;"><?= ($profit >= 0 ? '+' : '') . number_format($profit, 1) ?>u</div><div class="lbl">Profit (ROI <?= ($roi >= 0 ? '+' : '') . $roi ?>%)</div></div>
        <div class="stats-badge"><div class="num" style="color:#FBBF24;"><?= $pending ?></div><div class="lbl">Pending</div></div>
        <?php if ($winStreak > 0): ?>
        <div class="stats-badge" style="border-color:rgba(34,197,94,0.4);background:linear-gradient(135deg,rgba(34,197,94,0.15) 0%,rgba(6,182,212,0.08) 100%);">
            <div class="num" style="color:#22C55E;font-size:1.4rem;"><i class="fas fa-fire me-1"></i><?= $winStreak ?></div>
            <div class="lbl">Win Streak</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Win/Loss/Void breakdown -->
    <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
        <div class="stats-badge" style="min-width:80px;padding:10px;"><div class="num" style="color:#22C55E;font-size:1.3rem;"><?= $won ?></div><div class="lbl">Won</div></div>
        <div class="stats-badge" style="min-width:80px;padding:10px;"><div class="num" style="color:#EF4444;font-size:1.3rem;"><?= $lost ?></div><div class="lbl">Lost</div></div>
        <div class="stats-badge" style="min-width:80px;padding:10px;"><div class="num" style="color:#94a3b8;font-size:1.3rem;"><?= $voided ?></div><div class="lbl">Void</div></div>
        <?php if ($sparkline): ?>
        <div class="stats-badge" style="min-width:80px;padding:10px;display:flex;align-items:center;justify-content:center;">
            <div style="letter-spacing:3px;font-size:0.9rem;"><?= $sparkline ?></div>
            <div class="lbl" style="margin-top:0;margin-left:6px;">last 10</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Pick Type Breakdown -->
    <?php if (!empty($byType)): ?>
    <div class="mb-4" style="background:linear-gradient(135deg,rgba(139,92,246,0.12) 0%,rgba(6,182,212,0.06) 100%);border:1px solid rgba(139,92,246,0.2);border-radius:12px;padding:18px;">
        <h5 style="font-weight:700;font-size:0.95rem;margin-bottom:12px;"><i class="fas fa-chart-pie me-1" style="color:var(--accent);"></i>Performance by Pick Type</h5>
        <div class="pick-type-grid">
        <?php foreach ($byType as $t):
            $tSettled = (int)$t['won'] + (int)$t['lost'];
            $tRate = $tSettled > 0 ? round((int)$t['won'] / $tSettled * 100, 1) : 0;
        ?>
            <div class="pick-type-item">
                <div class="label"><?= htmlspecialchars($t['pick_value']) ?></div>
                <div class="sub"><?= $t['total'] ?> picks &middot; <?= $tRate ?>% win rate</div>
                <div style="font-size:0.7rem;margin-top:4px;"><span style="color:#22C55E;"><?= $t['won'] ?>W</span> <span style="color:#EF4444;margin-left:4px;"><?= $t['lost'] ?>L</span></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Picks -->
    <div style="background:linear-gradient(135deg,rgba(139,92,246,0.12) 0%,rgba(6,182,212,0.06) 100%);border:1px solid rgba(139,92,246,0.2);border-radius:12px;padding:18px;">
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <h5 style="font-weight:700;font-size:0.95rem;margin-bottom:0;">
                <i class="fas fa-clock-rotate me-1" style="color:var(--accent);"></i>Recent Picks
                <small style="font-weight:400;color:var(--text-muted);font-size:0.75rem;"> &mdash; system's recent winning picks</small>
            </h5>
            <input type="text" id="searchTrackRecord" class="form-control form-control-sm" placeholder="Search by team..." style="max-width:220px;font-size:0.82rem;background:rgba(22,27,34,0.7);border:1.5px solid rgba(139,92,246,0.45);color:var(--text-light);margin-left:auto;">
            <span style="font-size:0.75rem;color:var(--text-muted);" id="pickCount"><?= count($recent) ?> picks</span>
        </div>
        <?php if (empty($recent)): ?>
        <p class="text-muted mb-0">No picks settled yet.</p>
        <?php else: ?>
        <?php foreach ($recent as $r): ?>
        <div class="result-card d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div style="flex:1;min-width:140px;">
                <div class="match-name"><?= htmlspecialchars($r['match_name']) ?></div>
                <div class="pick-info">
                    <?= htmlspecialchars($r['pick_value']) ?>
                    <?php if ($r['odds']): ?><span class="type-chip ms-1"><i class="fas fa-arrow-trend-up me-1" style="font-size:0.6rem;"></i>@ <?= number_format($r['odds'], 2) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if ($r['home_score'] !== null): ?>
                <span class="score"><?= (int)$r['home_score'] ?>-<?= (int)$r['away_score'] ?></span>
                <?php endif; ?>
                <?= getResultBadge($r['result']) ?>
                <small class="text-muted" style="font-size:0.7rem;white-space:nowrap;"><?= date('j M', strtotime($r['settlement_date'] ?? $r['detected_at'])) ?></small>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-container">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
            <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            if ($startPage > 1) echo '<a href="?page=1">1</a>' . ($startPage > 2 ? '<span class="disabled">...</span>' : '');
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
            <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($endPage < $totalPages) echo ($endPage < $totalPages - 1 ? '<span class="disabled">...</span>' : '') . '<a href="?page=' . $totalPages . '">' . $totalPages . '</a>';
            ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
            <span class="disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>
</div>

<footer>
    <div class="container">
        <div class="row">
            <div class="col-md-4">
                <h5 class="mb-3" style="font-weight:700;color:var(--text-light);"><i class="fas fa-futbol me-1" style="color:var(--accent);"></i>PREDIXA</h5>
                <p style="font-size:0.85rem;color:var(--text-muted);">AI-powered football analytics, daily picks, and a tipster marketplace. Subscribe, bet, publish codes, and earn.</p>
            </div>
            <div class="col-md-2">
                <h6 class="mb-3" style="font-weight:700;color:var(--text-light);">Quick Links</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="./#pricing"><i class="fas fa-tag me-1" style="color:var(--accent);"></i> Plans</a></li>
                    <li class="mb-2"><?php if (isset($_SESSION['user_id'])): ?><a href="dashboard"><i class="fas fa-gauge-high me-1" style="color:var(--accent);"></i> Dashboard</a><?php else: ?><a href="login"><i class="fas fa-right-to-bracket me-1" style="color:var(--primary);"></i> Login</a><?php endif; ?></li>
                    <?php if (!isset($_SESSION['user_id'])): ?><li class="mb-2"><a href="signup"><i class="fas fa-user-plus me-1" style="color:#22C55E;"></i> Sign Up</a></li><?php endif; ?>
                    <li class="mb-2"><a href="./#codes-faq"><i class="fas fa-circle-question me-1" style="color:#FBBF24;"></i> FAQ</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="mb-3" style="font-weight:700;color:var(--text-light);">Free Tools</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="signals"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i> Smart Picks</a></li>
                    <li class="mb-2"><a href="track-record"><i class="fas fa-trophy me-1" style="color:#FBBF24;"></i> Recent Results</a></li>
                    <li class="mb-2"><a href="dropping-odds"><i class="fas fa-arrow-trend-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                    <li class="mb-2"><a href="betting-school"><i class="fas fa-book me-1" style="color:#8B5CF6;"></i> Betting School</a></li>
                    <li class="mb-2"><a href="pikka"><i class="fas fa-futbol me-1" style="color:#6366F1;"></i> Pikka</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="mb-3" style="font-weight:700;color:var(--text-light);">Support</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="https://wa.me/255713348298" target="_blank" style="color:#25D366;"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a></li>
                    <li class="mb-2"><a href="mailto:support@predixa.co.tz"><i class="fas fa-envelope me-1" style="color:var(--primary);"></i> Email Us</a></li>
                    <li class="mb-2" style="color:var(--text-muted);"><i class="fas fa-clock me-1" style="color:var(--accent);"></i> 24/7 Support</li>
                    <li class="mb-2"><a href="terms"><i class="fas fa-file-lines me-1" style="color:var(--text-muted);"></i> Terms of Service</a></li>
                </ul>
            </div>
        </div>
        <div class="border-top border-secondary mt-4 pt-4 text-center" style="color:var(--text-muted);font-size:0.8rem;">
            <small>&copy; <?= date('Y') ?> Predixa. All rights reserved. | 18+ | Bet Responsibly</small>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchTrackRecord');
    if (!searchInput) return;
    var cards = document.querySelectorAll('.result-card');
    var countEl = document.getElementById('pickCount');
    searchInput.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        var visible = 0;
        cards.forEach(function(c) {
            var text = c.textContent.toLowerCase();
            var match = !q || text.includes(q);
            c.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (countEl) countEl.textContent = visible + ' picks';
    });
});
</script>
</body>
</html>
