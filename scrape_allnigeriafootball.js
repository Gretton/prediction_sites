const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const { normalizePick } = require('./normalizer');

const SOURCE = 'allnigeriafootball';
const URL = 'https://allnigeriafootball.com/';

async function scrape() {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

  const picks = [];

  try {
    await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await new Promise(r => setTimeout(r, 1000));

    // The site stores each prediction in a <p> tag with <br> separators:
    // "DATE TIME<br/>LEAGUE<br/>HOME vs AWAY &#8211; PICK"
    const paragraphs = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('p')).map(p => p.innerHTML);
    });

    const today = new Date().toISOString().slice(0, 10);

    for (const html of paragraphs) {
      if (!/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(html)) continue;

      const parts = html.split(/<br\s*\/?>/i);
      if (parts.length < 3) continue;

      const dateTime = parts[0].replace(/<[^>]+>/g, '').trim();
      const league = parts[1].replace(/<[^>]+>/g, '').trim();
      const matchLine = parts[2].replace(/<[^>]+>/g, '').replace(/&#8211;|–/g, '–').trim();

      const dtMatch = dateTime.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/);
      if (!dtMatch) continue;
      const date = dtMatch[1];
      if (date < today) continue;

      const m = matchLine.match(/^(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s*[–-]\s*(.+)$/);
      if (!m) continue;

      const pick = normalizePick(m[3]);
      if (pick) {
        picks.push({
          match: `${m[1].trim()} vs ${m[2].trim()}`,
          home: m[1].trim(),
          away: m[2].trim(),
          pick,
          odds: '',
          league: league.replace(/^U\d+\s*/, ''),
          time: dtMatch[2],
        });
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
