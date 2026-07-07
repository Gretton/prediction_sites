const fs = require('fs');
const path = require('path');

const { normalizeTeam, normalizePick } = require('./normalizer');

// All scrapers to run
const SCRAPERS = [
  require('./scrape_passionpredict'),
  require('./scrape_r2bet'),
  require('./scrape_victorspredict'),
  require('./scrape_allnigeriafootball'),
  require('./scrape_adibet'),
];

const MIN_AGREEMENT = 3;
const OUTPUT_DIR = path.join(__dirname, 'output');
const OUTPUT_FILE = path.join(OUTPUT_DIR, 'picks.json');

function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

async function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

async function runScraperWithRetry(scraperModule, retries = 2) {
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const picks = await scraperModule.scrape();
      return { source: scraperModule.SOURCE, status: 'ok', picks, attempt: attempt + 1 };
    } catch (e) {
      console.error(`[${scraperModule.SOURCE}] Attempt ${attempt + 1}/${retries + 1} failed: ${e.message}`);
      if (attempt < retries) await sleep(5000 * (attempt + 1));
    }
  }
  return { source: scraperModule.SOURCE, status: 'error', picks: [], attempt: retries + 1 };
}

function computeIntersection(allResults) {
  // Group picks by normalized team names
  const matches = {}; // matchKey => { display, home, away, picks: { pickVal => [sources] } }

  for (const result of allResults) {
    if (result.status !== 'ok') continue;
    const source = result.source;
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
        matches[matchKey] = {
          display: `${home} vs ${away}`,
          home, away, nHome, nAway,
          picks: {}, // pickValue => [sources]
          sources: {}, // source => { pick, odds, league, time }
        };
      }

      if (!matches[matchKey].picks[pick]) matches[matchKey].picks[pick] = [];
      if (!matches[matchKey].picks[pick].includes(source)) {
        matches[matchKey].picks[pick].push(source);
      }
      matches[matchKey].sources[source] = { pick, odds: p.odds || '', league: p.league || '', time: p.time || '' };
    }
  }

  // Find intersections with enough agreement
  const intersections = [];
  for (const [key, m] of Object.entries(matches)) {
    for (const [pick, srcs] of Object.entries(m.picks)) {
      if (srcs.length >= MIN_AGREEMENT) {
        intersections.push({
          match: m.display,
          home: m.home,
          away: m.away,
          pick,
          sources: srcs,
          source_count: srcs.length,
          odds: '',
          league: '',
          time: '',
        });
      }
    }
  }

  // Sort by agreement count descending
  intersections.sort((a, b) => b.source_count - a.source_count);

  return intersections;
}

async function main() {
  ensureDir(OUTPUT_DIR);

  const startedAt = new Date().toISOString();
  console.log(`[${new Date().toISOString()}] Starting ${SCRAPERS.length} scrapers...\n`);

  // Run scrapers sequentially to avoid resource contention
  const results = [];
  for (const scraper of SCRAPERS) {
    const t0 = Date.now();
    const result = await runScraperWithRetry(scraper);
    const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
    console.log(`  ${result.source}: ${result.picks.length} picks in ${elapsed}s (status: ${result.status}, attempt: ${result.attempt})`);
    results.push(result);
  }

  const totalPicks = results.reduce((sum, r) => sum + r.picks.length, 0);
  console.log(`\n[${new Date().toISOString()}] Total raw picks: ${totalPicks}`);

  // Compute intersection
  const intersections = computeIntersection(results);
  console.log(`[${new Date().toISOString()}] Intersections found (>=${MIN_AGREEMENT}): ${intersections.length}`);

  if (intersections.length > 0) {
    console.log('');
    for (const ix of intersections) {
      console.log(`  ${ix.match} -> ${ix.pick} (${ix.source_count} sources: ${ix.sources.join(', ')})`);
    }
  } else {
    console.log('  (no picks with enough cross-source agreement)');
  }

  // Build output
  const output = {
    generated_at: new Date().toISOString(),
    started_at: startedAt,
    min_agreement: MIN_AGREEMENT,
    sources: Object.fromEntries(results.map(r => [r.source, {
      status: r.status,
      count: r.picks.length,
      attempt: r.attempt,
    }])),
    sources_detail: results.filter(r => r.status === 'ok').map(r => ({
      source: r.source,
      picks: r.picks.map(p => ({
        match: p.match, home: p.home, away: p.away,
        pick: p.pick, odds: p.odds, league: p.league, time: p.time,
      })),
    })),
    intersections,
  };

  fs.writeFileSync(OUTPUT_FILE, JSON.stringify(output, null, 2));
  console.log(`\n[${new Date().toISOString()}] Output written to ${OUTPUT_FILE}`);

  // Also print summary as compact JSON for piping
  if (process.stdout.isTTY === false) {
    console.log('\n--- JSON_OUTPUT ---');
    console.log(JSON.stringify({
      generated_at: output.generated_at,
      sources: output.sources,
      intersection_count: intersections.length,
      intersections,
    }));
  }
}

main().catch(e => {
  console.error(`Fatal: ${e.message}`);
  process.exit(1);
});
