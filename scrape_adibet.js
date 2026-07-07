const fetch = require('node-fetch');
const { normalizePick } = require('./normalizer');

const SOURCE = 'adibet';
const URL = 'https://www.adibet.com/';

async function scrape() {
  const picks = [];

  try {
    const res = await fetch(URL, {
      headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
      timeout: 15000,
    });
    const html = await res.text();

    // Parse tables — each row has cells: flag, match, 1, X, 2, +1.5, GG, +2.5
    // Highlighted cells have bgcolor="#272727"
    const tableRegex = /<table[^>]*>(.*?)<\/table>/gsi;
    let tableMatch;

    while ((tableMatch = tableRegex.exec(html)) !== null) {
      const tbl = tableMatch[1];
      if (!/[-–]/.test(tbl) && !/vs/i.test(tbl)) continue;

      const rowRegex = /<tr[^>]*>(.*?)<\/tr>/gsi;
      let rowMatch;
      while ((rowMatch = rowRegex.exec(tbl)) !== null) {
        const row = rowMatch[1];

        const cellRegex = /<t[dh][^>]*>(.*?)<\/t[dh]>/gsi;
        const cellHtml = [];
        let cellMatch;
        while ((cellMatch = cellRegex.exec(row)) !== null) {
          cellHtml.push(cellMatch[0]);
        }
        if (cellHtml.length < 6) continue;

        // Extract text and bgcolor per cell
        const cells = cellHtml.map(ch => ({
          text: ch.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim(),
          bg: ((ch.match(/bgcolor=(["'])([^"']+?)\1/i) || [])[2] || '').toLowerCase(),
        }));

        // Skip header row
        if (cells[0].bg === '#666666') continue;

        // Parse "Home - Away"
        const matchText = cells[1].text || '';
        const sepIdx = matchText.indexOf(' - ');
        if (sepIdx < 1) continue;
        const home = matchText.slice(0, sepIdx).trim();
        const away = matchText.slice(sepIdx + 3).trim();
        if (!home || !away) continue;

        // League from flag alt
        const flagMatch = cellHtml[0].match(/alt=(["'])([^"']+?)\1/i);
        const league = flagMatch ? flagMatch[2] : '';

        // Which columns are highlighted?
        const colLabels = [null, null, '1', 'X', '2', '+1.5', 'GG', '+2.5'];
        const highlighted = [];
        for (let ci = 2; ci <= 7 && ci < cells.length; ci++) {
          if (cells[ci].bg === '#272727') highlighted.push(colLabels[ci]);
        }
        if (!highlighted.length) continue;

        const resultPicks = highlighted.filter(h => ['1', 'X', '2'].includes(h));
        const ouPicks = highlighted.filter(h => ['+1.5', '+2.5'].includes(h));
        const bttsPicks = highlighted.filter(h => h === 'GG');

        if (resultPicks.length) {
          const pick = normalizePick(resultPicks.join(''));
          if (pick) picks.push({ match: `${home} vs ${away}`, home, away, pick, odds: '', league, time: '' });
        }
        for (const ou of ouPicks) {
          const pick = normalizePick(ou);
          if (pick) picks.push({ match: `${home} vs ${away}`, home, away, pick, odds: '', league, time: '' });
        }
        for (const b of bttsPicks) {
          const pick = normalizePick(b);
          if (pick) picks.push({ match: `${home} vs ${away}`, home, away, pick, odds: '', league, time: '' });
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
