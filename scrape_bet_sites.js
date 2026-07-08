const fetch = require('node-fetch');
const { normalizePick } = require('./normalizer');

const SITES = [
  { name: 'oversbet', url: 'https://www.oversbet.com/' },
  { name: 'glaobet',  url: 'https://www.glaobet.com/' },
  { name: 'ehobet',   url: 'https://www.ehobet.com/' },
  { name: 'odd2bet',  url: 'https://www.odd2bet.com/' },
];

async function scrape() {
  const allPicks = [];

  for (const site of SITES) {
    try {
      const res = await fetch(site.url, {
        headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
        timeout: 15000,
      });
      const html = await res.text();
      const rows = html.match(/<tr[^>]*>[\s\S]{0,1500}?<\/tr>/gi) || [];
      const today = new Date().toISOString().slice(0, 10);

      for (const row of rows) {
        const cells = row.match(/<t[dh][^>]*>[\s\S]{0,500}?<\/t[dh]>/gi) || [];
        if (cells.length < 4) continue;

        // Skip header rows
        const cellTexts = cells.map(c => c.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim());
        if (!cellTexts.some(t => /[-–]/.test(t) || /^[A-Z]/.test(t))) continue;
        if (cellTexts[0] === '' && cellTexts[1] === '') continue;

        // Extract match name (cell with " - ")
        let matchCell = -1, pickCell = -1;
        for (let i = 0; i < cellTexts.length; i++) {
          if (/[-–]/.test(cellTexts[i]) && !/^\d/.test(cellTexts[i]) && matchCell === -1) matchCell = i;
          else if (pickCell === -1 && (/^(Over|Under|1|X|2|1X|X2|12|GG|NG)/i.test(cellTexts[i]))) pickCell = i;
        }
        if (matchCell === -1 || pickCell === -1) continue;

        const parts = cellTexts[matchCell].split(/[-–]/).map(s => s.trim());
        if (parts.length < 2) continue;
        const home = parts[0], away = parts[1];
        if (!home || !away || home.length < 2 || away.length < 2) continue;

        const pick = normalizePick(cellTexts[pickCell]);
        if (pick) {
          allPicks.push({
            source: site.name,
            match: `${home} vs ${away}`,
            home, away,
            pick,
            odds: '',
            league: '',
            time: '',
          });
        }
      }
    } catch (e) {
      console.error(`[${site.name}] Error: ${e.message}`);
    }
  }

  return allPicks;
}

if (require.main === module) {
  scrape().then(p => console.log(JSON.stringify({ source: 'bet_sites', picks: p })));
}

module.exports = { scrape, SOURCE: 'bet_sites' };
