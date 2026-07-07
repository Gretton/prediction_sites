const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const { normalizePick } = require('./normalizer');

const SOURCE = 'victorspredict';
const URL = 'https://victorspredict.com/';

async function scrape() {
  const browser = await puppeteer.launch({
    headless: true,
    args: [
      '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
      '--disable-web-security', '--disable-features=IsolateOrigins',
      '--disable-site-isolation-trials',
    ],
  });
  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
  await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

  const picks = [];

  try {
    // Cloudflare challenge may take extra time
    await page.goto(URL, { waitUntil: 'networkidle2', timeout: 60000 });
    await new Promise(r => setTimeout(r, 5000));

    // Check if we passed Cloudflare
    const pageTitle = await page.title();
    if (pageTitle.includes('Just a moment') || pageTitle.includes('Cloudflare')) {
      // Try waiting for challenge to resolve
      await new Promise(r => setTimeout(r, 15000));
    }

    // Try DOM extraction — victorspredict uses WordPress tables
    const rows = await page.evaluate(() => {
      const results = [];
      const selectors = [
        '.sp-fixture tr',
        '.sp-table-wrapper tr',
        'table tr',
        '.match-row',
        '.prediction-item',
      ];
      let trs = [];
      for (const sel of selectors) {
        const found = document.querySelectorAll(sel);
        if (found.length > 3) { trs = found; break; }
      }
      for (const el of trs) {
        const cells = el.querySelectorAll('td, th');
        const texts = Array.from(cells).map(c => c.innerText.trim()).filter(t => t);
        if (texts.length >= 3) results.push(texts);
      }
      return results;
    });

    for (const cells of rows) {
      const rowText = cells.join(' ').replace(/\s+/g, ' ').trim();
      // Pattern variants: "Team1 vs Team2 - 1" or "Team1 - Team2 1X2 1"
      let m = rowText.match(/(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s*[–-]\s*(Home\(1\)|Away\(2\)|Draw\(X\)|Over\s+\d+\.?\d*|Under\s+\d+\.?\d*|GG|NG|1|2|X|1X|X2|12)/);
      if (!m) {
        m = rowText.match(/(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s+(?:1|X|2)\s+(?:1|X|2)\s+(?:1|X|2)\s+(\d+)/);
      }
      if (m) {
        const pick = normalizePick(m[3]);
        if (pick) picks.push({
          match: `${m[1].trim()} vs ${m[2].trim()}`,
          home: m[1].trim(), away: m[2].trim(), pick, odds: '', league: '', time: '',
        });
      }
    }

    // Fallback: plain text
    if (picks.length === 0) {
      const bodyText = await page.evaluate(() => document.body.innerText);
      const lines = bodyText.split('\n').map(l => l.trim()).filter(l => l);
      for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(/(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s*[–-]\s*(.+)/);
        if (m) {
          const pick = normalizePick(m[3]);
          if (pick) picks.push({
            match: `${m[1].trim()} vs ${m[2].trim()}`,
            home: m[1].trim(), away: m[2].trim(), pick, odds: '', league: '', time: '',
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
