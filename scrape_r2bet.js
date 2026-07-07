const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const { normalizePick } = require('./normalizer');

const SOURCE = 'r2bet';
const URL = 'https://www.r2bet.com/';

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
    await new Promise(r => setTimeout(r, 3000));

    // Try DOM extraction with multiple selectors
    const rows = await page.evaluate(() => {
      const results = [];
      const selectors = [
        'table tbody tr',
        '.predictions tr',
        '.match-row',
        '.tip-row',
        'tr',
      ];
      let trs = [];
      for (const sel of selectors) {
        const found = document.querySelectorAll(sel);
        if (found.length > 3) { trs = found; break; }
      }
      for (const el of trs) {
        const text = el.innerText.replace(/\s+/g, ' ').trim();
        if (!/vs/i.test(text)) continue;
        const cells = el.querySelectorAll('td, th');
        const texts = Array.from(cells).map(c => c.innerText.trim()).filter(t => t);
        results.push(texts.length ? texts : [text]);
      }
      return results;
    });

    for (const cells of rows) {
      const rowText = (Array.isArray(cells) ? cells.join(' ') : cells).replace(/\s+/g, ' ').trim();
      const m = rowText.match(
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

    // Fallback: plain text scan
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
