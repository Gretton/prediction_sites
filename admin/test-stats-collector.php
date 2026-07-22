<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));

$recentDates = [];
$totalRows = 0;
$leagues = [];
$stats = [];

try {
    $totalRows = $db->query("SELECT COUNT(*) FROM match_statistics")->fetchColumn();
    $recentDates = $db->query("SELECT match_date, COUNT(*) as cnt FROM match_statistics GROUP BY match_date ORDER BY match_date DESC LIMIT 30")->fetchAll();
    $leagues = $db->query("SELECT league_name, COUNT(*) as cnt FROM match_statistics GROUP BY league_name ORDER BY cnt DESC")->fetchAll();
    $stmt = $db->prepare("SELECT * FROM match_statistics WHERE match_date = ? ORDER BY league_name, home_team_api");
    $stmt->execute([$date]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $e->getMessage();
}

$logContent = null;
$logFile = __DIR__ . '/../logs/stats_collector_' . $date . '.log';
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
}

$teamNames = [];
if ($stats) {
    foreach ($stats as $s) {
        $teamNames[$s['home_team_api']] = true;
        $teamNames[$s['away_team_api']] = true;
    }
    $teamNames = array_values(array_keys($teamNames));
    sort($teamNames, SORT_STRING | SORT_FLAG_CASE);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Match Stats Collector</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        body { background: linear-gradient(135deg, #111318 0%, #1c2130 100%); min-height: 100vh; color: #e2e8f0; }
        .card { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(6,182,212,0.06)); border: 1px solid rgba(139,92,246,0.25); }
        .stat-card { background: rgba(22,27,34,0.7); border: 1px solid rgba(139,92,246,0.2); border-radius: 12px; padding: 16px; }
        .stat-big { font-size: 1.8rem; font-weight: 700; }
        .stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; }
        .form-control, .form-select { background: rgba(22,27,34,0.8); border-color: rgba(139,92,246,0.3); color: #e2e8f0; }
        .form-control:focus, .form-select:focus { border-color: rgba(139,92,246,0.6); box-shadow: 0 0 0 0.2rem rgba(139,92,246,0.15); color: #e2e8f0; }
        .form-control::placeholder { color: #64748b; }
        .log-box { background: #0d1117; border: 1px solid rgba(139,92,246,0.2); border-radius: 8px; padding: 12px; font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.78rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; color: #94a3b8; }
        .league-group { border: 1px solid rgba(139,92,246,0.15); border-radius: 10px; margin-bottom: 12px; overflow: hidden; }
        .league-header { background: rgba(139,92,246,0.15); padding: 10px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none; transition: background 0.15s; }
        .league-header:hover { background: rgba(139,92,246,0.25); }
        .league-header .league-name { font-weight: 600; font-size: 0.9rem; }
        .league-header .match-count { font-size: 0.75rem; background: rgba(139,92,246,0.3); padding: 2px 10px; border-radius: 12px; }
        .league-header .chevron { transition: transform 0.2s; }
        .league-header.collapsed .chevron { transform: rotate(-90deg); }
        .league-body { display: block; }
        .league-body.collapsed { display: none; }
        .match-table { width: 100%; font-size: 0.82rem; table-layout: fixed; }
        .match-table th { background: rgba(22,27,34,0.9); position: sticky; top: 0; z-index: 1; padding: 8px 8px; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; color: #94a3b8; border-bottom: 1px solid rgba(139,92,246,0.2); cursor: pointer; white-space: nowrap; }
        .match-table th:hover { color: #c4b5fd; }
        .match-table th.no-sort { cursor: default; }
        .match-table th .sort-icon { margin-left: 3px; opacity: 0.4; }
        .match-table th.sorted-asc .sort-icon, .match-table th.sorted-desc .sort-icon { opacity: 1; color: #a78bfa; }
        .match-table td { padding: 6px 8px; border-bottom: 1px solid rgba(139,92,246,0.08); vertical-align: middle; }
        .match-table tr:hover td { background: rgba(139,92,246,0.08); }
        .match-table .match-row { cursor: pointer; }
        .match-table .match-row:hover td { background: rgba(139,92,246,0.12); }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 6px 16px; padding: 10px 16px; background: rgba(139,92,246,0.06); border-top: 1px solid rgba(139,92,246,0.15); }
        .detail-item { font-size: 0.78rem; padding: 3px 0; }
        .detail-label { color: #64748b; margin-right: 6px; font-size: 0.72rem; text-transform: uppercase; }
        .match-table .team-name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .match-table .score { font-weight: 700; font-size: 0.95rem; color: #e2e8f0; text-align: center; }
        .match-table .stat-cell { text-align: center; color: #94a3b8; font-size: 0.78rem; white-space: nowrap; }
        .match-table .home-val { color: #a78bfa; }
        .match-table .away-val { color: #22d3ee; }
        .no-results { padding: 40px; text-align: center; color: #64748b; }
        .no-results i { font-size: 2rem; margin-bottom: 12px; display: block; }
        .search-highlight { background: rgba(250,204,21,0.25); border-radius: 2px; padding: 0 2px; }
        .date-pills { display: flex; flex-wrap: wrap; gap: 6px; max-height: 80px; overflow-y: auto; }
        .date-pill { padding: 4px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 500; text-decoration: none; border: 1px solid rgba(139,92,246,0.2); color: #94a3b8; background: rgba(22,27,34,0.5); transition: all 0.15s; white-space: nowrap; }
        .date-pill:hover { background: rgba(139,92,246,0.2); color: #e2e8f0; border-color: rgba(139,92,246,0.4); }
        .date-pill.active { background: rgba(139,92,246,0.4); color: #fff; border-color: rgba(139,92,246,0.6); }
        .date-pill .cnt { opacity: 0.7; margin-left: 4px; }
        .results-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .search-wrap { position: relative; }
        .search-wrap .form-control { padding-left: 30px; }
        .search-wrap .fa-search { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 0.8rem; pointer-events: none; }
        .autocomplete-list { position: absolute; top: 100%; left: 0; right: 0; z-index: 100; max-height: 240px; overflow-y: auto; background: #1a1f2e; border: 1px solid rgba(139,92,246,0.3); border-top: none; border-radius: 0 0 8px 8px; display: none; }
        .autocomplete-list.show { display: block; }
        .autocomplete-item { padding: 6px 12px; font-size: 0.8rem; cursor: pointer; color: #cbd5e1; border-bottom: 1px solid rgba(139,92,246,0.08); }
        .autocomplete-item:hover, .autocomplete-item.active { background: rgba(139,92,246,0.2); color: #fff; }
        .autocomplete-item .league-tag { font-size: 0.65rem; color: #64748b; margin-left: 6px; }
        .pagination-bar { display: flex; justify-content: center; align-items: center; gap: 4px; padding: 8px 0; }
        .pagination-bar button { background: rgba(139,92,246,0.15); border: 1px solid rgba(139,92,246,0.2); color: #94a3b8; padding: 3px 10px; border-radius: 6px; font-size: 0.75rem; cursor: pointer; transition: all 0.15s; }
        .pagination-bar button:hover:not(:disabled) { background: rgba(139,92,246,0.3); color: #e2e8f0; }
        .pagination-bar button:disabled { opacity: 0.3; cursor: not-allowed; }
        .pagination-bar .page-info { font-size: 0.75rem; color: #64748b; padding: 0 8px; }
        @media(max-width:768px) { .match-table { font-size: 0.72rem; } .stat-big { font-size: 1.3rem; } }
    </style>
</head>
<body>
<div class="container-fluid px-3 py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-chart-bar me-2" style="color:#a78bfa;"></i>Match Stats Collector</h4>
            <small class="text-muted">Match statistics from API-Football</small>
        </div>
        <a href="../admin.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Admin</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card text-center"><div class="stat-big" style="color:#a78bfa;"><?= number_format($totalRows) ?></div><div class="stat-label">Total Matches</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center"><div class="stat-big" style="color:#22d3ee;"><?= count($recentDates) > 0 ? $recentDates[0]['match_date'] : 'N/A' ?></div><div class="stat-label">Latest Date</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center"><div class="stat-big" style="color:#34d399;"><?= count($recentDates) > 0 ? number_format($recentDates[0]['cnt']) : 0 ?></div><div class="stat-label">Matches (Latest)</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card text-center"><div class="stat-big" style="color:#fbbf24;"><?= count($leagues) ?></div><div class="stat-label">Leagues Covered</div></div>
        </div>
    </div>

    <div class="card p-3 mb-4">
        <h6 class="mb-3"><i class="fas fa-calendar-alt me-2"></i>Collections by Date</h6>
        <div class="date-pills" id="datePills">
            <?php foreach ($recentDates as $rd): ?>
                <a href="?date=<?= $rd['match_date'] ?>" class="date-pill <?= $rd['match_date'] === $date ? 'active' : '' ?>"><?= $rd['match_date'] ?><span class="cnt"><?= $rd['cnt'] ?></span></a>
            <?php endforeach; ?>
            <?php if (empty($recentDates)): ?><span class="text-muted">No data collected yet.</span><?php endif; ?>
        </div>
    </div>

    <div class="card p-3 mb-4">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <h6 class="mb-0"><i class="fas fa-table me-2"></i><?= htmlspecialchars($date) ?></h6>
            <form class="d-flex gap-2" method="get">
                <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="form-control form-control-sm" style="width:155px">
                <button type="submit" class="btn btn-sm" style="background:rgba(139,92,246,0.4);border-color:rgba(139,92,246,0.6);color:#fff;">View</button>
            </form>
        </div>

        <div class="results-bar mb-3">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search team name..." style="width:240px;" autocomplete="off">
                    <div class="autocomplete-list" id="autocompleteList"></div>
                </div>
                <select id="leagueFilter" class="form-select form-select-sm" style="width:200px;">
                    <option value="">All Leagues</option>
                </select>
                <span id="resultCount" class="text-muted" style="font-size:0.8rem;"></span>
            </div>
            <div class="d-flex gap-2">
                <button onclick="expandAll()" class="btn btn-sm btn-outline-secondary" style="font-size:0.75rem;"><i class="fas fa-expand-alt me-1"></i>Expand All</button>
                <button onclick="collapseAll()" class="btn btn-sm btn-outline-secondary" style="font-size:0.75rem;"><i class="fas fa-compress-alt me-1"></i>Collapse All</button>
            </div>
        </div>

        <div id="leagueContainer"></div>
        <div id="noResults" class="no-results" style="display:none;"><i class="fas fa-inbox"></i><div>No matches found.</div></div>
    </div>

    <?php if ($logContent): ?>
    <div class="card p-3 mb-4">
        <h6 class="mb-3"><i class="fas fa-terminal me-2"></i>Collector Log (<?= $date ?>)</h6>
        <div class="log-box"><?= htmlspecialchars($logContent) ?></div>
    </div>
    <?php endif; ?>
</div>

<script>
const MATCH_DATA = <?= json_encode($stats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const TEAM_NAMES = <?= json_encode($teamNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PER_PAGE = 25;

let filteredByLeague = null;
let searchTerm = '';
let sortState = {};
let leaguePages = {};
let autocompleteIdx = -1;

const searchInput = document.getElementById('searchInput');
const autocompleteList = document.getElementById('autocompleteList');
const leagueFilter = document.getElementById('leagueFilter');
const resultCount = document.getElementById('resultCount');
const leagueContainer = document.getElementById('leagueContainer');
const noResults = document.getElementById('noResults');

function init() {
    const leagueMap = {};
    MATCH_DATA.forEach(m => {
        if (!leagueMap[m.league_name]) leagueMap[m.league_name] = 0;
        leagueMap[m.league_name]++;
    });
    const sorted = Object.entries(leagueMap).sort((a,b) => b[1] - a[1]);
    sorted.forEach(([name, cnt]) => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name + ' (' + cnt + ')';
        leagueFilter.appendChild(opt);
    });
    render();
}

function getFiltered() {
    let data = MATCH_DATA;
    if (filteredByLeague) data = data.filter(m => m.league_name === filteredByLeague);
    if (searchTerm) {
        const q = searchTerm.toLowerCase();
        data = data.filter(m => m.home_team_api.toLowerCase().includes(q) || m.away_team_api.toLowerCase().includes(q));
    }
    return data;
}

function render() {
    const data = getFiltered();
    resultCount.textContent = data.length + ' match' + (data.length !== 1 ? 'es' : '');

    const groups = {};
    data.forEach(m => {
        if (!groups[m.league_name]) groups[m.league_name] = [];
        groups[m.league_name].push(m);
    });

    const leagueNames = Object.keys(groups).sort((a,b) => {
        const sa = sortState.leagueAsc;
        const cmp = groups[b].length - groups[a].length;
        return sa ? -cmp : cmp;
    });

    if (leagueNames.length === 0) {
        leagueContainer.innerHTML = '';
        noResults.style.display = '';
        return;
    }
    noResults.style.display = 'none';

    let html = '';
    leagueNames.forEach(lname => {
        const matches = groups[lname];
        const sortKey = sortState[lname + '_key'];
        const sortAsc = sortState[lname + '_asc'];
        if (sortKey) {
            matches.sort((a, b) => {
                let va, vb;
                if (['sot','shots','corners','fouls','yc','poss','xg'].includes(sortKey)) {
                    va = parseFloat((a['home_' + sortKey] || 0)) + parseFloat((a['away_' + sortKey] || 0));
                    vb = parseFloat((b['home_' + sortKey] || 0)) + parseFloat((b['away_' + sortKey] || 0));
                } else if (sortKey === 'home') {
                    va = a.home_team_api.toLowerCase(); vb = b.home_team_api.toLowerCase();
                } else if (sortKey === 'away') {
                    va = a.away_team_api.toLowerCase(); vb = b.away_team_api.toLowerCase();
                } else if (sortKey === 'score') {
                    va = parseInt(a.home_score) + parseInt(a.away_score);
                    vb = parseInt(b.home_score) + parseInt(b.away_score);
                } else { return 0; }
                if (va < vb) return sortAsc ? -1 : 1;
                if (va > vb) return sortAsc ? 1 : -1;
                return 0;
            });
        }

        if (!leaguePages[lname]) leaguePages[lname] = 0;
        const totalPages = Math.ceil(matches.length / PER_PAGE);
        if (leaguePages[lname] >= totalPages) leaguePages[lname] = Math.max(0, totalPages - 1);
        const page = leaguePages[lname];
        const start = page * PER_PAGE;
        const pageMatches = matches.slice(start, start + PER_PAGE);

        const isCollapsed = sortState['collapsed_' + lname] ? 'collapsed' : '';

        html += '<div class="league-group" data-league="' + esc(lname) + '">';
        html += '<div class="league-header ' + isCollapsed + '" onclick="toggleLeague(\'' + escJS(lname) + '\')">';
        html += '<div class="d-flex align-items-center gap-2"><i class="fas fa-chevron-down chevron" style="font-size:0.7rem;"></i>';
        html += '<span class="league-name">' + esc(lname) + '</span></div>';
        html += '<span class="match-count">' + matches.length + ' matches</span></div>';
        html += '<div class="league-body' + (isCollapsed ? ' collapsed' : '') + '">';

        const activeSortKey = sortState[lname + '_key'];
        const activeSortAsc = sortState[lname + '_asc'];
        html += '<div style="max-height:500px;overflow-y:auto;">';
        html += '<table class="match-table"><thead><tr>';
        const cols = [
            {k:'home',l:'Home',w:'18%'},
            {k:'score',l:'Score',w:'7%',n:true},
            {k:'away',l:'Away',w:'18%'},
            {k:'sot',l:'SOT',w:'8%'},
            {k:'shots',l:'Shots',w:'9%'},
            {k:'corners',l:'Corners',w:'9%'},
            {k:'fouls',l:'Fouls',w:'8%'},
            {k:'yc',l:'YC',w:'6%'},
            {k:'poss',l:'Poss',w:'7%'},
            {k:'xg',l:'xG',w:'8%'},
            {k:'ref',l:'Referee',w:'9%',n:true},
            {k:'expand',l:'',w:'3%',n:true},
        ];
        cols.forEach(c => {
            const sorted = activeSortKey === c.k;
            const cls = sorted ? (activeSortAsc ? 'sorted-asc' : 'sorted-desc') : '';
            const icon = sorted ? (activeSortAsc ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
            const clickable = c.n ? '' : ' onclick="sortLeague(\'' + escJS(lname) + '\',\'' + c.k + '\')"';
            html += '<th' + clickable + ' class="' + cls + '" style="width:' + c.w + (c.n ? ';cursor:default' : '') + '">';
            html += c.l + ' <i class="fas ' + icon + ' sort-icon"></i></th>';
        });
        html += '</tr></thead><tbody>';

        pageMatches.forEach(m => {
            const q = searchTerm.toLowerCase();

            html += '<tr class="match-row" onclick="toggleDetail(this)" style="cursor:pointer;">';
            html += '<td class="team-name" title="' + esc(m.home_team_api) + '">' + hl(m.home_team_api, q) + '</td>';
            html += '<td class="score">' + m.home_score + '-' + m.away_score + '</td>';
            html += '<td class="team-name" title="' + esc(m.away_team_api) + '">' + hl(m.away_team_api, q) + '</td>';
            html += '<td class="stat-cell"><span class="home-val">' + nv(m.home_shots_on_goal) + '</span> / <span class="away-val">' + nv(m.away_shots_on_goal) + '</span></td>';
            html += '<td class="stat-cell"><span class="home-val">' + nv(m.home_total_shots) + '</span> / <span class="away-val">' + nv(m.away_total_shots) + '</span></td>';
            html += '<td class="stat-cell"><span class="home-val">' + nv(m.home_corner_kicks) + '</span> / <span class="away-val">' + nv(m.away_corner_kicks) + '</span></td>';
            html += '<td class="stat-cell"><span class="home-val">' + nv(m.home_fouls) + '</span> / <span class="away-val">' + nv(m.away_fouls) + '</span></td>';
            html += '<td class="stat-cell">' + ((m.home_yellow_cards||0)*1 + (m.away_yellow_cards||0)*1 > 0 ? '<span class="home-val">' + nv(m.home_yellow_cards) + '</span> / <span class="away-val">' + nv(m.away_yellow_cards) + '</span>' : '-') + '</td>';
            html += '<td class="stat-cell"><small><span class="home-val">' + (m.home_ball_possession || '-') + '</span> / <span class="away-val">' + (m.away_ball_possession || '-') + '</span></small></td>';
            html += '<td class="stat-cell"><small>' + (m.home_expected_goals != null ? '<span class="home-val">' + parseFloat(m.home_expected_goals).toFixed(2) + '</span> / <span class="away-val">' + parseFloat(m.away_expected_goals).toFixed(2) + '</span>' : '-') + '</small></td>';
            html += '<td><small class="text-muted">' + esc(m.referee || '-') + '</small></td>';
            html += '<td class="text-center" style="width:30px;"><i class="fas fa-chevron-down" style="font-size:0.6rem;color:#64748b;"></i></td>';
            html += '</tr>';

            html += '<tr class="detail-row" style="display:none;"><td colspan="12" style="padding:0;">';
            html += '<div class="detail-grid">';
            const detailRows = [
                ['Shots Off Target', nv(m.home_shots_off_goal), nv(m.away_shots_off_goal)],
                ['Blocked Shots', nv(m.home_blocked_shots), nv(m.away_blocked_shots)],
                ['Shots Inside Box', nv(m.home_shots_inside_box), nv(m.away_shots_inside_box)],
                ['Shots Outside Box', nv(m.home_shots_outside_box), nv(m.away_shots_outside_box)],
                ['Offsides', nv(m.home_offsides), nv(m.away_offsides)],
                ['Free Kicks', nv(m.home_free_kicks), nv(m.away_free_kicks)],
                ['Red Cards', ((m.home_red_cards||0)*1 + (m.away_red_cards||0)*1 > 0 ? nv(m.home_red_cards) + ' / ' + nv(m.away_red_cards) : '-')],
                ['GK Saves', nv(m.home_goalkeeper_saves), nv(m.away_goalkeeper_saves)],
                ['Total Passes', nv(m.home_total_passes), nv(m.away_total_passes)],
                ['Passes Accurate', nv(m.home_passes_accurate), nv(m.away_passes_accurate)],
                ['Pass Accuracy', nv(m.home_pass_accuracy), nv(m.away_pass_accuracy)],
                ['Goals Prevented', m.home_goals_prevented != null ? parseFloat(m.home_goals_prevented).toFixed(2) : '-', m.away_goals_prevented != null ? parseFloat(m.away_goals_prevented).toFixed(2) : '-'],
                ['Venue', esc(m.venue || '-'), ''],
            ];
            detailRows.forEach(dr => {
                if (dr.length === 3 && dr[1] === '-' && dr[2] === '-') return;
                html += '<div class="detail-item"><span class="detail-label">' + dr[0] + '</span>';
                if (dr.length === 3 && dr[2] !== '') {
                    html += '<span class="home-val">' + dr[1] + '</span> / <span class="away-val">' + dr[2] + '</span>';
                } else {
                    html += '<span>' + dr[1] + '</span>';
                }
                html += '</div>';
            });
            html += '</div></td></tr>';
        });
        html += '</tbody></table></div>';

        if (totalPages > 1) {
            html += '<div class="pagination-bar">';
            html += '<button onclick="goPage(\'' + escJS(lname) + '\',0)"' + (page===0?' disabled':'') + '><i class="fas fa-angle-double-left"></i></button>';
            html += '<button onclick="goPage(\'' + escJS(lname) + '\',' + (page-1) + ')"' + (page===0?' disabled':'') + '><i class="fas fa-angle-left"></i></button>';
            html += '<span class="page-info">Page ' + (page+1) + ' of ' + totalPages + '</span>';
            html += '<button onclick="goPage(\'' + escJS(lname) + '\',' + (page+1) + ')"' + (page>=totalPages-1?' disabled':'') + '><i class="fas fa-angle-right"></i></button>';
            html += '<button onclick="goPage(\'' + escJS(lname) + '\',' + (totalPages-1) + ')"' + (page>=totalPages-1?' disabled':'') + '><i class="fas fa-angle-double-right"></i></button>';
            html += '</div>';
        }

        html += '</div></div>';
    });

    leagueContainer.innerHTML = html;

    document.querySelectorAll('.league-header').forEach(h => {
        if (sortState['collapsed_' + h.closest('.league-group').dataset.league]) {
            h.classList.add('collapsed');
            h.nextElementSibling.classList.add('collapsed');
        }
    });
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escJS(s) { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function nv(v) { return v != null ? v : '-'; }
function hl(text, q) {
    if (!q) return esc(text);
    const safe = esc(text);
    const regex = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return safe.replace(regex, '<span class="search-highlight">$1</span>');
}

function toggleLeague(name) {
    sortState['collapsed_' + name] = !sortState['collapsed_' + name];
    render();
}
function expandAll() {
    Object.keys(leaguePages).forEach(k => sortState['collapsed_' + k] = false);
    render();
}
function collapseAll() {
    Object.keys(leaguePages).forEach(k => sortState['collapsed_' + k] = true);
    render();
}
function goPage(name, page) {
    leaguePages[name] = Math.max(0, page);
    render();
}
function sortLeague(name, key) {
    if (sortState[name + '_key'] === key) {
        sortState[name + '_asc'] = !sortState[name + '_asc'];
    } else {
        sortState[name + '_key'] = key;
        sortState[name + '_asc'] = true;
    }
    render();
}

function toggleDetail(row) {
    const detail = row.nextElementSibling;
    if (detail && detail.classList.contains('detail-row')) {
        detail.style.display = detail.style.display === 'none' ? '' : 'none';
    }
}

searchInput.addEventListener('input', function() {
    searchTerm = this.value.trim();
    leaguePages = {};
    render();
    updateAutocomplete();
});
searchInput.addEventListener('keydown', function(e) {
    const items = autocompleteList.querySelectorAll('.autocomplete-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); autocompleteIdx = Math.min(autocompleteIdx + 1, items.length - 1); setACActive(items); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); autocompleteIdx = Math.max(autocompleteIdx - 1, 0); setACActive(items); }
    else if (e.key === 'Enter' && autocompleteIdx >= 0) { e.preventDefault(); selectACItem(items[autocompleteIdx]); }
    else if (e.key === 'Escape') { autocompleteList.classList.remove('show'); autocompleteIdx = -1; }
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('.search-wrap')) autocompleteList.classList.remove('show');
});

function updateAutocomplete() {
    const q = searchTerm.toLowerCase();
    autocompleteIdx = -1;
    if (!q || q.length < 1) { autocompleteList.classList.remove('show'); return; }
    const teamLeagueMap = {};
    MATCH_DATA.forEach(m => {
        if (m.home_team_api.toLowerCase().includes(q)) teamLeagueMap[m.home_team_api] = m.league_name;
        if (m.away_team_api.toLowerCase().includes(q)) teamLeagueMap[m.away_team_api] = m.league_name;
    });
    const matches = Object.entries(teamLeagueMap).sort((a,b) => a[0].localeCompare(b[0])).slice(0, 15);
    if (!matches.length) { autocompleteList.classList.remove('show'); return; }
    let html = '';
    matches.forEach(([name, league]) => {
        html += '<div class="autocomplete-item" data-team="' + esc(name) + '" onclick="selectACItem(this)">' + hl(name, q) + '<span class="league-tag">' + esc(league) + '</span></div>';
    });
    autocompleteList.innerHTML = html;
    autocompleteList.classList.add('show');
}
function setACActive(items) {
    items.forEach((it,i) => it.classList.toggle('active', i === autocompleteIdx));
    if (items[autocompleteIdx]) items[autocompleteIdx].scrollIntoView({ block: 'nearest' });
}
function selectACItem(el) {
    searchInput.value = el.dataset.team;
    searchTerm = el.dataset.team;
    autocompleteList.classList.remove('show');
    leaguePages = {};
    render();
}

leagueFilter.addEventListener('change', function() {
    filteredByLeague = this.value || null;
    leaguePages = {};
    render();
});

init();
</script>
</body>
</html>
