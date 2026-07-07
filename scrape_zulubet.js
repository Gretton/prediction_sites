const fetch = require('node-fetch');
const { normalizePick } = require('./normalizer');

const SOURCE = 'zulubet';
const URL = 'https://www.zulubet.com/';

async function scrape() {
  const picks = [];

  try {
    const res = await fetch(URL, {
      headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
      timeout: 15000,
    });
    const html = await res.text();

    // Zulubet uses tables. Each row: date, "Home - Away", percentages, TIP, stars, odds, score
    const tableRegex = /<table[^>]*>(.*?)<\/table>/gsi;
    let tableMatch;

    while ((tableMatch = tableRegex.exec(html)) !== null) {
      const tbl = tableMatch[1];
      if (!/[-–]/.test(tbl)) continue;

      const cellRegex = /<t[dh][^>]*>(.*?)<\/t[dh]>/gsi;
      const allCells = [];
      let cellMatch;
      while ((cellMatch = cellRegex.exec(tbl)) !== null) {
        const text = cellMatch[1].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
        if (text) allCells.push(text);
      }

      // Walk cells to find match patterns: "Home - Away" followed by prediction data
      for (let i = 0; i < allCells.length - 2; i++) {
        const c = allCells[i];
        // Match cells look like "Botafogo SP - Avai" or "Vila Nova - São Bernardo"
        if (!/[-–]/.test(c)) continue;
        if (/^\d/.test(c) || /^[A-Z]+:/.test(c)) continue; // skip date/percentage cells

        const sepIdx = c.indexOf(' - ');
        if (sepIdx < 1) continue;
        const home = c.slice(0, sepIdx).trim();
        const away = c.slice(sepIdx + 3).trim();
        if (!home || !away || home.length < 2 || away.length < 2) continue;

        // The TIP cell comes 4-5 cells after the match (varies by layout)
        // Pattern: match, 1:XX%, X:XX%, 2:XX%, XX%, XX%, XX%, TIP, stars, odds
        // Look ahead for a cell that's exactly "1", "X", "2", "1X", "X2", or "12"
        let tip = '';
        for (let j = i + 1; j < Math.min(i + 10, allCells.length); j++) {
          const val = allCells[j].trim();
          if (/^[1X2]{1,2}$/.test(val) && val !== val.replace(/[^1X2]/g, '')) continue;
          if (/^(1|X|2|1X|X2|12)$/.test(val)) {
            tip = val;
            break;
          }
        }

        if (tip) {
          const pick = normalizePick(tip);
          if (pick) {
            picks.push({
              match: `${home} vs ${away}`,
              home, away, pick, odds: '', league: '', time: '',
            });
          }
        }
      }
    }
  } catch (e) {
    console.error(`[${SOURCE}] Error: ${e.message}`);
  }

  return picks;
}

if (require.main === module) {
  scrape().then(p => console.log(JSON.stringify({ source: SOURCE, picks: p })));
}

module.exports = { scrape, SOURCE };
