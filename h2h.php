<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/BayesianModel.php';

$db = getDB();
$model = new BayesianModel();

$teamA = trim($_GET['team_a'] ?? $_POST['team_a'] ?? '');
$teamB = trim($_GET['team_b'] ?? $_POST['team_b'] ?? '');

$teams = [];
if ($db) {
    $stmt = $db->query("SELECT name FROM teams ORDER BY name");
    $teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>H2H Head to Head | Predixa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root { --primary: #06B6D4; --primary-dark: #0891B2; --primary-light: #22D3EE; --secondary: #0EA5E9; --accent: #8B5CF6; --bg-soft: #FAFAFA; --bg-white: #FFFFFF; --text-dark: #1F2937; --text-muted: #6B7280; --border-color: #E5E7EB; --shadow: 0 1px 3px rgba(0,0,0,0.1); --shadow-lg: 0 10px 25px rgba(0,0,0,0.1); }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: var(--bg-soft); color: var(--text-dark); min-height: 100vh; }
.header { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; padding: 1rem 0; box-shadow: var(--shadow); }
.header-content { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; display: flex; justify-content: space-between; align-items: center; }
.brand { font-size: 1.5rem; font-weight: 800; text-decoration: none; color: white; display: flex; align-items: center; gap: 0.5rem; }
.brand:hover { color: white; text-decoration: none; }
.header-links { display: flex; gap: 1.5rem; align-items: center; }
.header-links a { color: rgba(255,255,255,0.85); text-decoration: none; font-weight: 500; font-size: 0.9rem; }
.header-links a:hover { color: white; }
.main-content { max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; }
.search-section { background: var(--bg-white); border-radius: 16px; padding: 2rem; box-shadow: var(--shadow-lg); margin-bottom: 2rem; }
.search-row { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; }
.search-group { flex: 1; min-width: 200px; }
.search-group label { display: block; font-weight: 600; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem; }
.search-group input { width: 100%; padding: 0.75rem 1rem; border: 2px solid var(--border-color); border-radius: 10px; font-size: 1rem; font-family: 'Inter', sans-serif; transition: border-color 0.3s; }
.search-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(6,182,212,0.15); }
.btn-search { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border: none; padding: 0.75rem 2rem; border-radius: 10px; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.3s; white-space: nowrap; }
.btn-search:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); }

.vs-badge { background: var(--accent); color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; flex-shrink: 0; align-self: flex-end; margin-bottom: 0.5rem; }

.h2h-hero { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-radius: 16px; padding: 2rem; margin-bottom: 1.5rem; color: white; box-shadow: var(--shadow-lg); }
.hero-teams { display: flex; align-items: center; justify-content: center; gap: 1.5rem; margin-bottom: 1.5rem; }
.hero-team { text-align: center; flex: 1; }
.hero-team-name { font-size: 1.3rem; font-weight: 700; }
.hero-vs { font-size: 2rem; font-weight: 800; color: var(--accent); }
.record-bar { display: flex; gap: 4px; height: 40px; border-radius: 8px; overflow: hidden; margin-bottom: 1rem; }
.record-bar .bar-a { background: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; color: white; }
.record-bar .bar-d { background: #6B7280; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; color: white; }
.record-bar .bar-b { background: var(--accent); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; color: white; }
.record-labels { display: flex; justify-content: space-between; font-size: 0.8rem; color: rgba(255,255,255,0.7); }
.record-labels span { font-weight: 600; }

.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.stat-card { background: var(--bg-white); border: 1px solid var(--border-color); border-radius: 12px; padding: 1rem; text-align: center; box-shadow: var(--shadow); }
.stat-card .stat-value { font-size: 1.5rem; font-weight: 800; color: var(--primary-dark); }
.stat-card .stat-label { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; }

.section-title { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
.section-title i { color: var(--primary); }

.meeting-card { background: var(--bg-white); border: 1px solid var(--border-color); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 0.75rem; transition: all 0.2s; cursor: pointer; }
.meeting-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-1px); }
.meeting-card.has-stats { border-left: 3px solid var(--primary); }
.meeting-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.meeting-date { font-size: 0.8rem; color: var(--text-muted); font-weight: 500; }
.meeting-league { font-size: 0.75rem; color: var(--primary-dark); background: rgba(6,182,212,0.1); padding: 0.15rem 0.5rem; border-radius: 4px; font-weight: 600; }
.meeting-teams { display: flex; align-items: center; gap: 0.75rem; }
.meeting-team { font-weight: 600; font-size: 0.95rem; }
.meeting-team.home { flex: 1; }
.meeting-team.away { flex: 1; text-align: right; }
.meeting-score { background: var(--text-dark); color: white; padding: 0.35rem 0.75rem; border-radius: 6px; font-weight: 800; font-size: 1rem; min-width: 60px; text-align: center; }
.meeting-score.a-win { background: var(--primary-dark); }
.meeting-score.b-win { background: var(--accent); }
.meeting-score.draw { background: #6B7280; }
.meeting-stats { display: none; padding-top: 0.75rem; margin-top: 0.75rem; border-top: 1px solid var(--border-color); }
.meeting-stats.active { display: block; }
.stats-row { display: flex; justify-content: space-between; align-items: center; padding: 0.35rem 0; font-size: 0.85rem; }
.stats-row .label { color: var(--text-muted); font-weight: 500; }
.stats-row .val-a, .stats-row .val-b { font-weight: 700; width: 60px; }
.stats-row .val-a { text-align: left; }
.stats-row .val-b { text-align: right; }
.stats-row .bar-cell { flex: 1; padding: 0 0.75rem; }
.mini-bar { height: 6px; border-radius: 3px; display: flex; overflow: hidden; }
.mini-bar .fill-a { background: var(--primary); height: 100%; }
.mini-bar .fill-b { background: var(--accent); height: 100%; }
.stats-xg { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; text-align: center; }

.form-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
.form-panel { flex: 1; background: var(--bg-white); border: 1px solid var(--border-color); border-radius: 12px; padding: 1rem 1.25rem; box-shadow: var(--shadow); }
.form-team-name { font-weight: 700; font-size: 0.95rem; margin-bottom: 0.75rem; }
.form-badges { display: flex; gap: 4px; margin-bottom: 0.75rem; flex-wrap: wrap; }
.form-badge { width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; color: white; }
.form-badge.W { background: #22C55E; }
.form-badge.D { background: #6B7280; }
.form-badge.L { background: #EF4444; }
.form-list { list-style: none; padding: 0; margin: 0; }
.form-list li { display: flex; justify-content: space-between; align-items: center; padding: 0.35rem 0; border-bottom: 1px solid var(--border-color); font-size: 0.85rem; }
.form-list li:last-child { border-bottom: none; }
.form-list .fl-date { color: var(--text-muted); font-size: 0.8rem; }
.form-list .fl-result { font-weight: 700; width: 20px; }
.form-list .fl-result.W { color: #22C55E; }
.form-list .fl-result.D { color: #6B7280; }
.form-list .fl-result.L { color: #EF4444; }
.form-list .fl-score { font-weight: 600; }
.form-list .fl-opp { color: var(--text-muted); flex: 1; margin: 0 0.5rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.form-list .fl-league { font-size: 0.75rem; color: var(--primary-dark); }

.split-section { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; }
.split-card { background: var(--bg-white); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; box-shadow: var(--shadow); }
.split-card h6 { font-weight: 700; margin-bottom: 0.75rem; font-size: 0.9rem; color: var(--text-muted); }
.split-record { display: flex; gap: 0.5rem; align-items: center; }
.split-record .split-num { font-size: 1.5rem; font-weight: 800; }
.split-record .split-label { font-size: 0.75rem; color: var(--text-muted); }

.no-data { text-align: center; padding: 4rem 2rem; color: var(--text-muted); }
.no-data i { font-size: 3rem; margin-bottom: 1rem; display: block; color: var(--border-color); }
.no-data p { font-size: 1.1rem; font-weight: 500; }

.loading { text-align: center; padding: 3rem; }
.loading .spinner { border: 3px solid var(--border-color); border-top-color: var(--primary); width: 40px; height: 40px; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0 auto 1rem; }
@keyframes spin { to { transform: rotate(360deg); } }

.footer { text-align: center; padding: 2rem; color: var(--text-muted); font-size: 0.8rem; border-top: 1px solid var(--border-color); margin-top: 2rem; }

@media (max-width: 768px) {
    .hero-teams { flex-direction: column; gap: 0.5rem; }
    .hero-team-name { font-size: 1.1rem; }
    .form-row { flex-direction: column; }
    .split-section { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
    .meeting-team { font-size: 0.85rem; }
}
</style>
</head>
<body>

<div class="header">
    <div class="header-content">
        <a href="/" class="brand"><i class="fas fa-chart-line"></i> Predixa</a>
        <div class="header-links">
            <a href="dashboard"><i class="fas fa-home me-1"></i> Home</a>
            <a href="h2h"><i class="fas fa-exchange-alt me-1"></i> H2H</a>
            <a href="signals"><i class="fas fa-signal me-1"></i> Signals</a>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="search-section">
        <form id="h2hForm" method="GET" action="">
            <div class="search-row">
                <div class="search-group">
                    <label><i class="fas fa-shield-halved me-1"></i> Team A (Home)</label>
                    <input type="text" id="teamA" name="team_a" value="<?= htmlspecialchars($teamA) ?>" placeholder="e.g. Arsenal" list="teamsList" autocomplete="off">
                </div>
                <div class="vs-badge">VS</div>
                <div class="search-group">
                    <label><i class="fas fa-shield-halved me-1"></i> Team B (Away)</label>
                    <input type="text" id="teamB" name="team_b" value="<?= htmlspecialchars($teamB) ?>" placeholder="e.g. Chelsea" list="teamsList" autocomplete="off">
                </div>
                <button type="submit" class="btn-search"><i class="fas fa-search me-1"></i> Compare</button>
            </div>
            <datalist id="teamsList">
                <?php foreach ($teams as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>">
                <?php endforeach; ?>
            </datalist>
        </form>
    </div>

    <div id="results">
        <?php if ($teamA && $teamB): ?>
        <div class="loading" id="loadingState">
            <div class="spinner"></div>
            <p>Loading H2H data...</p>
        </div>
        <?php else: ?>
        <div class="no-data">
            <i class="fas fa-exchange-alt"></i>
            <p>Select two teams to compare their head-to-head record</p>
            <p style="font-size:0.85rem; margin-top:0.5rem;">Data auto-updates as new match statistics are collected</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="footer">
    Predixa Football Analytics &copy; <?= date('Y') ?> &middot; Data updates automatically with match stats collection
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const teamA = params.get('team_a');
    const teamB = params.get('team_b');
    if (teamA && teamB) loadH2H(teamA, teamB);
});

function loadH2H(a, b) {
    const results = document.getElementById('results');
    results.innerHTML = '<div class="loading"><div class="spinner"></div><p>Loading H2H data...</p></div>';

    fetch('api/h2h.php?team_a=' + encodeURIComponent(a) + '&team_b=' + encodeURIComponent(b))
        .then(r => r.json())
        .then(data => {
            if (data.error) { results.innerHTML = '<div class="no-data"><i class="fas fa-exclamation-triangle"></i><p>' + data.error + '</p></div>'; return; }
            renderResults(data);
        })
        .catch(err => { results.innerHTML = '<div class="no-data"><i class="fas fa-exclamation-triangle"></i><p>Failed to load data</p></div>'; });
}

function renderResults(d) {
    const s = d.summary;
    const totalPct = Math.max(s.total, 1);
    const wPctA = (s.teamA_wins / totalPct * 100).toFixed(0);
    const dPct = (s.draws / totalPct * 100).toFixed(0);
    const wPctB = (s.teamB_wins / totalPct * 100).toFixed(0);

    let html = '';

    // Hero section
    html += '<div class="h2h-hero">';
    html += '<div class="hero-teams">';
    html += '<div class="hero-team"><div class="hero-team-name">' + esc(d.team_a.name) + '</div></div>';
    html += '<div class="hero-vs">VS</div>';
    html += '<div class="hero-team"><div class="hero-team-name">' + esc(d.team_b.name) + '</div></div>';
    html += '</div>';

    html += '<div class="record-bar">';
    if (s.teamA_wins > 0) html += '<div class="bar-a" style="flex:' + s.teamA_wins + '">' + s.teamA_wins + 'W</div>';
    if (s.draws > 0) html += '<div class="bar-d" style="flex:' + s.draws + '">' + s.draws + 'D</div>';
    if (s.teamB_wins > 0) html += '<div class="bar-b" style="flex:' + s.teamB_wins + '">' + s.teamB_wins + 'W</div>';
    html += '</div>';

    html += '<div class="record-labels">';
    html += '<span>' + esc(d.team_a.name) + ': ' + s.teamA_wins + ' wins (' + wPctA + '%)</span>';
    html += '<span>Draws: ' + s.draws + ' (' + dPct + '%)</span>';
    html += '<span>' + esc(d.team_b.name) + ': ' + s.teamB_wins + ' wins (' + wPctB + '%)</span>';
    html += '</div>';
    html += '</div>';

    // Stats grid
    html += '<div class="stat-grid">';
    html += statCard(s.total, 'Total Meetings');
    html += statCard(s.avg_goals, 'Avg Goals');
    html += statCard(s.btts_rate + '%', 'BTTS Rate');
    html += statCard(s.over_25 + '%', 'Over 2.5');
    html += statCard(s.over_15 + '%', 'Over 1.5');
    html += statCard(s.under_35 + '%', 'Under 3.5');
    if (d.avg_xg) {
        html += statCard(d.avg_xg.teamA, 'Avg xG ' + esc(d.team_a.name));
        html += statCard(d.avg_xg.teamB, 'Avg xG ' + esc(d.team_b.name));
    }
    html += '</div>';

    // Home/Away Split
    if (d.home_split.total > 0 || d.away_split.total > 0) {
        html += '<div class="section-title"><i class="fas fa-map-marker-alt"></i> Home/Away Split</div>';
        html += '<div class="split-section">';
        html += splitCard('At ' + esc(d.team_a.name) + ' home', d.home_split, d.team_a.name, d.team_b.name);
        html += splitCard('At ' + esc(d.team_b.name) + ' home', d.away_split, d.team_a.name, d.team_b.name);
        html += '</div>';
    }

    // Form guide
    html += '<div class="section-title"><i class="fas fa-fire"></i> Recent Form</div>';
    html += '<div class="form-row">';
    html += formPanel(d.team_a.name, d.form_a);
    html += formPanel(d.team_b.name, d.form_b);
    html += '</div>';

    // Meetings list
    if (d.meetings.length > 0) {
        html += '<div class="section-title"><i class="fas fa-history"></i> Match History (' + d.meetings.length + ' matches)</div>';
        d.meetings.forEach((m, i) => { html += meetingCard(m, i, d.team_a.name); });
    }

    document.getElementById('results').innerHTML = html;

    document.querySelectorAll('.meeting-card.has-stats').forEach(card => {
        card.addEventListener('click', function() {
            const stats = this.querySelector('.meeting-stats');
            if (stats) stats.classList.toggle('active');
        });
    });
}

function statCard(val, label) {
    return '<div class="stat-card"><div class="stat-value">' + val + '</div><div class="stat-label">' + label + '</div></div>';
}

function splitCard(title, sp, nameA, nameB) {
    const t = Math.max(sp.total, 1);
    return '<div class="split-card"><h6>' + title + '</h6>' +
        '<div class="split-record">' +
        '<div><div class="split-num" style="color:var(--primary)">' + sp.teamA_wins + '</div><div class="split-label">' + esc(nameA) + '</div></div>' +
        '<div style="color:var(--text-muted);font-size:1.2rem;font-weight:700">-</div>' +
        '<div><div class="split-num" style="color:#6B7280">' + sp.draws + '</div><div class="split-label">Draws</div></div>' +
        '<div style="color:var(--text-muted);font-size:1.2rem;font-weight:700">-</div>' +
        '<div><div class="split-num" style="color:var(--accent)">' + sp.teamB_wins + '</div><div class="split-label">' + esc(nameB) + '</div></div>' +
        '</div><div style="font-size:0.8rem;color:var(--text-muted);margin-top:0.5rem">' + sp.total + ' matches played here</div></div>';
}

function formPanel(name, form) {
    let html = '<div class="form-panel"><div class="form-team-name">' + esc(name) + '</div>';
    if (form.length === 0) { html += '<p style="color:var(--text-muted);font-size:0.85rem">No recent matches</p></div>'; return html; }
    html += '<div class="form-badges">';
    form.forEach(f => { html += '<div class="form-badge ' + f.result + '">' + f.result + '</div>'; });
    html += '</div>';
    html += '<ul class="form-list">';
    form.forEach(f => {
        html += '<li><span class="fl-date">' + f.date + '</span><span class="fl-result ' + f.result + '">' + f.result + '</span><span class="fl-score">' + f.score + '</span><span class="fl-opp">' + (f.is_home ? 'vs ' : '@ ') + esc(f.opponent) + '</span><span class="fl-league">' + esc(f.league) + '</span></li>';
    });
    html += '</ul></div>';
    return html;
}

function meetingCard(m, idx, nameA) {
    const resultClass = m.result === 'A' ? 'a-win' : (m.result === 'B' ? 'b-win' : 'draw');
    const isHomeA = m.is_home_a;
    const teamsHtml = '<div class="meeting-team home">' + esc(m.home_team) + '</div>' +
        '<div class="meeting-score ' + resultClass + '">' + m.home_score + ' - ' + m.away_score + '</div>' +
        '<div class="meeting-team away">' + esc(m.away_team) + '</div>';

    let statsHtml = '';
    if (m.has_stats && m.stats) {
        const st = m.stats;
        statsHtml = '<div class="meeting-stats" id="stats-' + idx + '">';
        statsHtml += statsRow('xG', fmtDec(st.xg[0]), fmtDec(st.xg[1]), parseFloat(st.xg[0]) || 0, parseFloat(st.xg[1]) || 0, 3);
        statsHtml += statsRow('Shots on Target', st.shots_on[0], st.shots_on[1], st.shots_on[0] || 0, st.shots_on[1] || 0, 12);
        statsHtml += statsRow('Total Shots', st.shots_total[0], st.shots_total[1], st.shots_total[0] || 0, st.shots_total[1] || 0, 25);
        statsHtml += statsRow('Possession', st.possession[0], st.possession[1], parseFloat(st.possession[0]) || 50, parseFloat(st.possession[1]) || 50, 100);
        statsHtml += statsRow('Corners', st.corners[0], st.corners[1], st.corners[0] || 0, st.corners[1] || 0, 12);
        statsHtml += statsRow('Fouls', st.fouls[0], st.fouls[1], st.fouls[0] || 0, st.fouls[1] || 0, 20);
        statsHtml += statsRow('Yellow Cards', st.cards[0], st.cards[1], st.cards[0] || 0, st.cards[1] || 0, 8);
        statsHtml += statsRow('GK Saves', st.saves[0], st.saves[1], st.saves[0] || 0, st.saves[1] || 0, 10);
        statsHtml += statsRow('Passes (Accurate)', st.passes_accurate[0], st.passes_accurate[1], st.passes_accurate[0] || 0, st.passes_accurate[1] || 0, 500);
        if (st.referee) statsHtml += '<div style="font-size:0.8rem;color:var(--text-muted);text-align:center;margin-top:0.5rem"><i class="fas fa-user-shield me-1"></i>' + esc(st.referee) + (st.venue ? ' &middot; ' + esc(st.venue) : '') + '</div>';
        statsHtml += '</div>';
    }

    return '<div class="meeting-card' + (m.has_stats ? ' has-stats' : '') + '" data-idx="' + idx + '">' +
        '<div class="meeting-header"><span class="meeting-date">' + m.date + '</span><span class="meeting-league">' + esc(m.league) + '</span>' +
        (m.has_stats ? '<span style="font-size:0.7rem;color:var(--primary);font-weight:600"><i class="fas fa-chart-bar me-1"></i>Stats</span>' : '') +
        '</div>' +
        '<div class="meeting-teams">' + teamsHtml + '</div>' + statsHtml + '</div>';
}

function statsRow(label, valA, valB, numA, numB, maxVal) {
    const total = numA + numB || 1;
    const pctA = (numA / total * 100).toFixed(0);
    const pctB = (numB / total * 100).toFixed(0);
    return '<div class="stats-row"><span class="val-a">' + valA + '</span><div class="bar-cell"><div class="mini-bar"><div class="fill-a" style="width:' + pctA + '%"></div><div class="fill-b" style="width:' + pctB + '%"></div></div></div><span class="val-b">' + valB + '</span><span class="label" style="width:120px;text-align:center">' + label + '</span></div>';
}

function fmtDec(v) { return v !== null && v !== undefined ? parseFloat(v).toFixed(1) : '-'; }
function esc(s) { const el = document.createElement('span'); el.textContent = s || ''; return el.innerHTML; }
</script>
</body>
</html>
