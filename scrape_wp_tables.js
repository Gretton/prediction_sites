const fetch = require('node-fetch');
const { normalizePick } = require('./normalizer');

const SITES = [
  { name: 'azzbet',          url: 'https://azzbet.com/' },
  { name: 'donnbet',         url: 'https://donnbet.com/' },
  { name: 'guzzbet',         url: 'https://guzzbet.com/' },
  { name: 'freesoccertips.asia',  url: 'https://freesoccertips.asia/' },
  { name: 'freesoccerpredictions', url: 'https://freesoccerpredictions.eu/' },
  { name: 'freesoccertips.today', url: 'https://freesoccertips.today/' },
  { name: 'freetipsdaily',   url: 'https://freetipsdaily.com/' },
];

async function scrape() {
  const allPicks = [];
  const today = new Date();
  const todayStr = `${String(today.getDate()).padStart(2, '0')}.${String(today.getMonth() + 1).padStart(2, '0')}.${today.getFullYear()}`;

  for (const site of SITES) {
    try {
      const res = await fetch(site.url, {
        headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
        timeout: 15000,
      });
      const html = await res.text();
      const rows = html.match(/<tr[^>]*>[\s\S]{0,2000}?<\/tr>/gi) || [];

      for (const row of rows) {
        if (/<th/i.test(row)) continue;

        const cells = {};
        const cellRegex = /<td[^>]*class="column-(\d+)"[^>]*>([\s\S]{0,500}?)<\/td>/gi;
        let cm;
        while ((cm = cellRegex.exec(row)) !== null) {
          cells[parseInt(cm[1])] = cm[2].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
        }
        if (Object.keys(cells).length < 3) continue;

        // Check date
        const dateStr = cells[1] || '';
        if (dateStr !== todayStr) continue;

        // Find match column (contains " - ")
        let matchText = '', home = '', away = '';
        for (let ci = 1; ci <= 9; ci++) {
          const val = cells[ci] || '';
          if (/[-–]/.test(val) && val.includes(' ')) {
            const parts = val.split(/[-–]/).map(s => s.trim());
            if (parts.length >= 2 && parts[0].length >= 2 && parts[1].length >= 2) {
              matchText = val;
              home = parts[0];
              away = parts[1];
              break;
            }
          }
        }
        if (!home || !away) continue;

        // Find prediction value in other cells
        let pick = '';
        for (let ci = 1; ci <= 9; ci++) {
          if (ci === matchText) continue;
          const val = cells[ci] || '';
          if (!val) continue;

          // Direct pick: "x", "1", "X", "2", "1X", "X2", "12", "GG", "NG"
          const pickMatch = val.match(/\b(1|X|2|1X|X2|12|GG|NG)\b/i);
          if (pickMatch) {
            pick = pickMatch[1];
            break;
          }

          // Combined: "1 at 1.30 odds", "1x 1.34 odd", "1 - 1.58"
          const combinedMatch = val.match(/^\s*(1[Xx]?|[Xx]2|12|GG|NG)\s*(at|[-–])\s*\d+/i);
          if (combinedMatch) {
            pick = combinedMatch[1];
            break;
          }

          // "x" mark (azzbet-style)
          if (val.toLowerCase() === 'x') {
            // Column 4=1, 5=X, 6=2, 7=Under2.5, 8=GG, 9=Over2.5
            const pickByCol = { 4: '1', 5: 'X', 6: '2', 7: 'Under 2.5', 8: 'GG', 9: 'Over 2.5' };
            if (pickByCol[ci]) {
              pick = pickByCol[ci];
              break;
            }
          }
        }

        if (!pick) continue;

        const normalized = normalizePick(String(pick));
        if (normalized) {
          allPicks.push({
            source: site.name,
            match: `${home} vs ${away}`,
            home, away,
            pick: normalized,
            odds: '',
            league: cells[2] || '',
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
  scrape().then(p => console.log(JSON.stringify({ source: 'wp_tables', picks: p })));
}

module.exports = { scrape, SOURCE: 'wp_tables' };
