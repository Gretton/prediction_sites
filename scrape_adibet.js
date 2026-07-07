const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const { normalizePick } = require('./normalizer');

const SOURCE = 'adibet';
const URL = 'https://www.adibet.com/';

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

    // Adibet uses tables with bgcolor="#272727" on highlighted (predicted) cells
    // Columns: 0=flag, 1=match, 2=1, 3=X, 4=2, 5=+1.5, 6=GG, 7=+2.5
    const tableData = await page.evaluate(() => {
      const results = [];
      const tables = document.querySelectorAll('table');
      for (const table of tables) {
        if (!table.innerText.includes('-') && !table.innerText.includes('vs')) continue;
        const rows = table.querySelectorAll('tr');
        for (const row of rows) {
          const cells = row.querySelectorAll('td');
          if (cells.length < 6) continue;

          // Get cell text and background color
          const cellInfo = Array.from(cells).map(cell => ({
            text: cell.innerText.replace(/\s+/g, ' ').trim(),
            bg: (cell.getAttribute('bgcolor') || '').toLowerCase(),
          }));

          // Skip header rows (first row or rows with different bg pattern)
          if (cellInfo[0].bg === '#666666') continue;

          const matchText = cellInfo[1]?.text || '';
          const parts = matchText.split(' - ');
          if (parts.length !== 2) continue;
          const home = parts[0].trim();
          const away = parts[1].trim();
          if (!home || !away) continue;

          // Determine league from flag image alt text
          const flagCell = cells[0];
          const img = flagCell.querySelector('img');
          const league = img ? (img.getAttribute('alt') || '') : '';

          // Check which prediction columns are highlighted
          const colMap = [null, null, '1', 'X', '2', '+1.5', 'GG', '+2.5'];
          const highlighted = [];
          for (let ci = 2; ci <= 7 && ci < cellInfo.length; ci++) {
            if (cellInfo[ci].bg === '#272727') {
              highlighted.push(colMap[ci]);
            }
          }
          if (!highlighted.length) continue;

          // Split into categories
          const resultPicks = highlighted.filter(h => ['1', 'X', '2'].includes(h));
          const ouPicks = highlighted.filter(h => ['+1.5', '+2.5'].includes(h));
          const bttsPicks = highlighted.filter(h => h === 'GG');

          if (resultPicks.length) {
            const pick = normalizePick(resultPicks.join(''));
            if (pick) results.push({ match: `${home} vs ${away}`, home, away, pick, odds: '', league, time: '' });
          }
          for (const ou of ouPicks) {
            const pick = normalizePick(ou);
            if (pick) results.push({ match: `${home} vs ${away}`, home, away, pick, odds: '', league, time: '' });
          }
          for (const b of bttsPicks) {
            const pick = normalizePick(b);
            if (pick) results.push({ match: `${home} vs ${away}`, home, away, pick, odds: '', league, time: '' });
          }
        }
      }
      return results;
    });

    picks.push(...tableData);
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
