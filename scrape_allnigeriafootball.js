const fetch = require('node-fetch');
const { normalizePick } = require('./normalizer');

const SOURCE = 'allnigeriafootball';
const URL = 'https://allnigeriafootball.com/';

async function scrape() {
  const picks = [];

  try {
    const res = await fetch(URL, {
      headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
      timeout: 15000,
    });
    const html = await res.text();

    // Extract <p> tags — each contains: DATE TIME<br/>LEAGUE<br/>HOME vs AWAY – PICK
    const pRegex = /<p[^>]*>(.*?)<\/p>/gsi;
    const today = new Date().toISOString().slice(0, 10);
    let match;

    while ((match = pRegex.exec(html)) !== null) {
      const p = match[1];
      if (!/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(p)) continue;

      const parts = p.split(/<br\s*\/?>/i);
      if (parts.length < 3) continue;

      const dateTime = parts[0].replace(/<[^>]+>/g, '').trim();
      const league = parts[1].replace(/<[^>]+>/g, '').trim();
      const matchLine = parts[2].replace(/<[^>]+>/g, '').replace(/&#8211;|–/g, '–').trim();

      const dtMatch = dateTime.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/);
      if (!dtMatch || dtMatch[1] < today) continue;

      const m = matchLine.match(/^(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s*[–-]\s*(.+)$/);
      if (!m) continue;

      const pick = normalizePick(m[3]);
      if (pick) {
        picks.push({
          match: `${m[1].trim()} vs ${m[2].trim()}`,
          home: m[1].trim(), away: m[2].trim(),
          pick, odds: '', league: league.replace(/^U\d+\s*/, ''), time: dtMatch[2],
        });
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
