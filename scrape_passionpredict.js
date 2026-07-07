const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const { normalizePick } = require('./normalizer');

const SOURCE = 'passionpredict';
const URL = 'https://passionpredict.com/';

async function scrape() {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

  const picks = [];

  try {
    await page.goto(URL, { waitUntil: 'networkidle2', timeout: 35000 });
    // Extra wait for Alpine.js to fully render
    await new Promise(r => setTimeout(r, 3000));

    // The site renders a table with predictions.
    // Look for table rows containing match data.
    const rows = await page.evaluate(() => {
      const results = [];
      // Try various selectors that might contain the prediction data
      const selectors = [
        'table tbody tr',
        '.predictions-table tr',
        '.table tr',
        '[x-data] tr',
        'tr',
      ];
      let trs = [];
      for (const sel of selectors) {
        const found = document.querySelectorAll(sel);
        if (found.length > 5) { trs = found; break; }
      }
      if (!trs.length) return results;

      for (const tr of trs) {
        const cells = tr.querySelectorAll('td');
        if (cells.length < 4) continue;
        const texts = Array.from(cells).map(c => c.innerText.trim()).filter(t => t);
        const rowText = texts.join(' | ');
        if (!/vs/i.test(rowText) && !/\d+\.\d+/.test(rowText)) continue;
        results.push(texts);
      }
      return results;
    });

    for (const cells of rows) {
      const rowText = cells.join(' ').replace(/\s+/g, ' ').trim();
      // Pattern: "Time Home vs Away Pick Odds" or "Home vs Away Pick Odds"
      const match = rowText.match(
        /(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s+(Home\(1\)|Away\(2\)|Draw\(X\)|Over\s+\d+\.?\d*|Under\s+\d+\.?\d*|GG|NG|1X|X2|12)\s+(\d+\.\d+)/
      );
      if (match) {
        const pick = normalizePick(match[3]);
        if (pick) {
          picks.push({
            match: `${match[1].trim()} vs ${match[2].trim()}`,
            home: match[1].trim(),
            away: match[2].trim(),
            pick,
            odds: match[4],
            league: '',
            time: '',
          });
        }
      }
    }

    // Fallback: plain text extraction
    if (picks.length === 0) {
      const bodyText = await page.evaluate(() => document.body.innerText);
      const lines = bodyText.split('\n').map(l => l.trim()).filter(l => l);
      for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(
          /(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s+(Home\(1\)|Away\(2\)|Draw\(X\)|Over\s+\d+\.?\d*|Under\s+\d+\.?\d*|GG|NG|1X|X2|12)\s+(\d+\.\d+)/
        );
        if (m) {
          const pick = normalizePick(m[3]);
          if (pick) picks.push({
            match: `${m[1].trim()} vs ${m[2].trim()}`,
            home: m[1].trim(), away: m[2].trim(), pick, odds: m[4], league: '', time: '',
          });
        }
      }
    }
  } catch (e) {
    console.error(`[${SOURCE}] Error: ${e.message}`);
  } finally {
    await browser.close();
  }

  return picks;
}

if (require.main === module) {
  scrape().then(picks => {
    console.log(JSON.stringify({ source: SOURCE, generated_at: new Date().toISOString(), picks }));
  }).catch(e => {
    console.error(e.message);
    process.exit(1);
  });
}

module.exports = { scrape, SOURCE };
