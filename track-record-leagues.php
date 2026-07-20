<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
require_once __DIR__ . '/includes/signals_engine.php';
logPageVisit('track-record-leagues');

$db = getDB();
if (!$db) { echo "DB unavailable"; exit; }

$leagues = $db->query("
    SELECT wp.league,
           SUM(CASE WHEN ps.result='won' THEN 1 ELSE 0 END) as won,
           SUM(CASE WHEN ps.result='lost' THEN 1 ELSE 0 END) as lost
    FROM (
        SELECT p2.* FROM pick_settlements p2
        INNER JOIN (
            SELECT web_pick_id, MAX(id) AS max_id FROM pick_settlements
            WHERE result IN ('won','lost')
            GROUP BY web_pick_id
        ) latest ON p2.id = latest.max_id
    ) ps
    INNER JOIN web_picks wp ON ps.web_pick_id = wp.id
    WHERE wp.pick_type IN ('rollover','parlay','over_15')
    GROUP BY wp.league
    ORDER BY COUNT(*) DESC
")->fetchAll();

$user = getCurrentUser();
$pageTitle = 'All Leagues Performance — PREDIXA';
$pageDesc = 'Full league-by-league breakdown of all premium picks performance.';
$canonical = (defined('SITE_URL') ? SITE_URL : 'https://predixa.co.tz') . '/track-record-leagues';
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
<title><?= htmlspecialchars($pageTitle) ?></title>
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
.pick-type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
.pick-type-item { background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; }
.pick-type-item .label { font-weight: 600; font-size: 0.82rem; }
.pick-type-item .sub { font-size: 0.72rem; color: var(--text-muted); }
footer { border-top: 1px solid var(--border-color); padding: 40px 0; margin-top: 60px; background: rgba(15, 17, 21, 0.5); color: var(--text-muted); font-size: 0.85rem; }
footer h5, footer h6 { color: var(--text-light); font-weight: 700; }
footer a { color: var(--text-muted); text-decoration: none; transition: all 0.3s; }
footer a:hover { color: var(--primary); }
@media (max-width: 768px) { .page-header { padding: 90px 0 20px; } .page-header h1 { font-size: 1.5rem; } }
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
                    <a class="btn btn-outline-premium btn-sm" href="login?redirect=track-record-leagues" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Login</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <a href="track-record" style="display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);font-size:0.85rem;margin-bottom:10px;text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Track Record</a>
        <h1><i class="fas fa-trophy me-2" style="color:#FBBF24;"></i>All Leagues Performance</h1>
        <p style="color:var(--text-muted);font-size:1rem;max-width:700px;">Full league-by-league breakdown of all premium picks. <?= count($leagues) ?> leagues tracked.</p>
    </div>
</div>

<div class="content-area">
<div class="container pb-4 pt-4">

    <?php if (empty($leagues)): ?>
    <div class="text-center" style="padding: 60px 20px; color: var(--text-muted);">
        <i class="fas fa-trophy" style="font-size:3rem;color:var(--primary);margin-bottom:15px;"></i>
        <h5>No League Data Yet</h5>
        <p class="mb-0">League breakdown will populate once picks are settled.</p>
    </div>
    <?php else: ?>

    <div style="background:linear-gradient(135deg,rgba(139,92,246,0.12) 0%,rgba(6,182,212,0.06) 100%);border:1px solid rgba(139,92,246,0.2);border-radius:12px;padding:18px;">
        <h5 style="font-weight:700;font-size:0.95rem;margin-bottom:12px;"><i class="fas fa-trophy me-1" style="color:var(--accent);"></i>All Leagues <small style="font-weight:400;color:var(--text-muted);font-size:0.75rem;">&mdash; sorted by number of picks</small></h5>
        <div class="pick-type-grid">
        <?php foreach ($leagues as $t):
            $tSettled = (int)$t['won'] + (int)$t['lost'];
            $tRate = $tSettled > 0 ? round((int)$t['won'] / $tSettled * 100, 1) : null;
            $leagueShort = strlen($t['league']) > 40 ? substr($t['league'], 0, 40) . '...' : $t['league'];
        ?>
            <div class="pick-type-item">
                <div class="label" style="font-size:0.82rem;"><?= htmlspecialchars($leagueShort) ?></div>
                <div class="sub" style="line-height:1.6;"><?= $tSettled ?> picks<br><?= $tRate !== null ? '<strong style="color:var(--accent);font-size:0.7rem;">' . $tRate . '%</strong> win rate' : '<span style="color:var(--text-muted);">no settled picks</span>' ?></div>
                <div style="font-size:0.7rem;margin-top:2px;"><span style="color:#22C55E;font-weight:600;"><?= $t['won'] ?>W</span> <span style="color:#EF4444;font-weight:600;margin-left:4px;"><?= $t['lost'] ?>L</span></div>
            </div>
        <?php endforeach; ?>
        </div>
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
                    <li class="mb-2"><?php if (isset($_SESSION['user_id'])): ?><a href="dashboard"><i class="fas fa-gauge-high me-1" style="color:var(--accent);"></i> Dashboard</a><?php else: ?><a href="login?redirect=track-record-leagues"><i class="fas fa-right-to-bracket me-1" style="color:var(--primary);"></i> Login</a><?php endif; ?></li>
                    <?php if (!isset($_SESSION['user_id'])): ?><li class="mb-2"><a href="signup"><i class="fas fa-user-plus me-1" style="color:#22C55E;"></i> Sign Up</a></li><?php endif; ?>
                    <li class="mb-2"><a href="./#codes-faq"><i class="fas fa-circle-question me-1" style="color:#FBBF24;"></i> FAQ</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="mb-3" style="font-weight:700;color:var(--text-light);">Free Tools</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="signals"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i> Smart Picks</a></li>
                    <li class="mb-2"><a href="track-record"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i> Performance</a></li>
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
</body>
</html>
