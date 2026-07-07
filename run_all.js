const fs = require('fs');
const path = require('path');
const { normalizeTeam, normalizePick } = require('./normalizer');

// Scrapers that work with simple HTTP fetch
const SCRAPERS = [
  require('./scrape_allnigeriafootball'),
  require('./scrape_adibet'),
  require('./scrape_zulubet'),
];

const MIN_AGREEMENT = 2;
const OUTPUT_DIR = path.join(__dirname, 'output');
const OUTPUT_FILE = path.join(OUTPUT_DIR, 'picks.json');

function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

async function runScraper(scraperModule, retries = 1) {
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const t0 = Date.now();
      const picks = await scraperModule.scrape();
      return { source: scraperModule.SOURCE, status: 'ok', picks, elapsed: ((Date.now() - t0) / 1000).toFixed(1) };
    } catch (e) {
      console.error(`[${scraperModule.SOURCE}] Attempt ${attempt + 1}/${retries + 1}: ${e.message}`);
      if (attempt < retries) await new Promise(r => setTimeout(r, 3000));
    }
  }
  return { source: scraperModule.SOURCE, status: 'error', picks: [], elapsed: '0' };
}

function teamsOverlap(n1, n2) {
  if (n1 === n2) return true;
  if (n1.includes(n2) || n2.includes(n1)) return true;
  const w1 = n1.split(' ');
  const w2 = n2.split(' ');
  const common = w1.filter(w => w2.includes(w));
  const maxLen = Math.max(w1.length, w2.length);
  return maxLen > 0 && common.length / maxLen >= 0.6;
}

function computeIntersection(allResults) {
  const matches = {};

  for (const result of allResults) {
    if (result.status !== 'ok') continue;
    for (const p of result.picks) {
      const home = p.home || '';
      const away = p.away || '';
      const pick = p.pick || '';
      if (!home || !away || !pick) continue;

      const nHome = normalizeTeam(home);
      const nAway = normalizeTeam(away);
      const teams = [nHome, nAway].sort();
      const matchKey = teams.join('||');

      if (!matches[matchKey]) {
        matches[matchKey] = { display: `${home} vs ${away}`, home, away, nHome, nAway, picks: {}, sources: {} };
      }

      if (!matches[matchKey].picks[pick]) matches[matchKey].picks[pick] = [];
      if (!matches[matchKey].picks[pick].includes(result.source)) {
        matches[matchKey].picks[pick].push(result.source);
      }
      matches[matchKey].sources[result.source] = { pick, odds: p.odds || '', league: p.league || '', time: p.time || '' };
    }
  }

  // Fuzzy merge
  const keys = Object.keys(matches);
  for (let i = 0; i < keys.length; i++) {
    if (!matches[keys[i]]) continue;
    for (let j = i + 1; j < keys.length; j++) {
      if (!matches[keys[j]]) continue;
      const a = matches[keys[i]], b = matches[keys[j]];
      const matchAB = teamsOverlap(a.nHome, b.nHome) && teamsOverlap(a.nAway, b.nAway);
      const matchBA = teamsOverlap(a.nHome, b.nAway) && teamsOverlap(a.nAway, b.nHome);
      if (matchAB || matchBA) {
        for (const [pick, srcs] of Object.entries(b.picks)) {
          if (!a.picks[pick]) a.picks[pick] = [];
          for (const src of srcs) if (!a.picks[pick].includes(src)) a.picks[pick].push(src);
        }
        for (const [src, info] of Object.entries(b.sources)) {
          if (!a.sources[src]) a.sources[src] = info;
        }
        if (matchBA && !a.sources[b.home]) {
          a.display = `${b.home} vs ${b.away}`;
          a.home = b.home; a.away = b.away;
        }
        delete matches[keys[j]];
      }
    }
  }

  const intersections = [];
  for (const m of Object.values(matches)) {
    for (const [pick, srcs] of Object.entries(m.picks)) {
      if (srcs.length >= MIN_AGREEMENT) {
        intersections.push({
          match: m.display, home: m.home, away: m.away, pick,
          sources: srcs, source_count: srcs.length,
          odds: '', league: '', time: '',
        });
      }
    }
  }

  intersections.sort((a, b) => b.source_count - a.source_count);
  return intersections;
}

async function main() {
  ensureDir(OUTPUT_DIR);
  const startedAt = new Date().toISOString();
  console.log(`[${new Date().toISOString()}] Starting ${SCRAPERS.length} scrapers...\n`);

  const results = [];
  for (const scraper of SCRAPERS) {
    const result = await runScraper(scraper);
    console.log(`  ${result.source}: ${result.picks.length} picks in ${result.elapsed}s (${result.status})`);
    results.push(result);
  }

  const totalPicks = results.reduce((s, r) => s + r.picks.length, 0);
  console.log(`\nTotal raw picks: ${totalPicks}`);

  const intersections = computeIntersection(results);
  console.log(`Intersections found (≥${MIN_AGREEMENT} sources): ${intersections.length}`);

  for (const ix of intersections) {
    console.log(`  ${ix.match} → ${ix.pick} (${ix.source_count} sources: ${ix.sources.join(', ')})`);
  }

  const output = {
    generated_at: new Date().toISOString(),
    started_at: startedAt,
    min_agreement: MIN_AGREEMENT,
    sources: Object.fromEntries(results.map(r => [r.source, { status: r.status, count: r.picks.length }])),
    sources_detail: results.filter(r => r.status === 'ok').map(r => ({
      source: r.source,
      picks: r.picks.map(p => ({ match: p.match, home: p.home, away: p.away, pick: p.pick, odds: p.odds, league: p.league, time: p.time })),
    })),
    intersections,
  };

  fs.writeFileSync(OUTPUT_FILE, JSON.stringify(output, null, 2));
  console.log(`\nOutput written to ${OUTPUT_FILE}`);
}

main().catch(e => { console.error(`Fatal: ${e.message}`); process.exit(1); });
