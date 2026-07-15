/**
 * cron/scrape_predictions.js — Multi-source prediction scraper (Puppeteer + fetch)
 *
 * Follows the architecture of scrape_scores.js: outputs JSON to stdout.
 * Run externally (local PC or GitHub Actions), pipe output to import_predictions.php:
 *
 *   node cron/scrape_predictions.js | curl -X POST -d @- https://predixa.co.tz/cron/import_predictions.php?key=pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580
 *
 * Accommodates site delays: each source is independent, partial results
 * are preserved server-side until enough sources agree.
 */

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
const TIMEOUT = 20000;

// ── Normalizer (mirrors PHP normalizer for team names) ──
function normalizeTeam(name) {
    return name.toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\b(fc|cf|ac|sc|rc|ss|cd|as|sk|fk|nk|ud|ca|cr|ec|aa|ae|ssc|if|ff|clube|real|club|deportivo|sport|sporting|atletico|athletic|associacao|sociedade|football)\b/g, '').replace(/\s+/g, ' ').trim();
}

// ── Site A: passionpredict.com (JS-rendered, needs Puppeteer) ──
async function scrapePassionPredict(page) {
    const picks = [];
    try {
        await page.goto('https://www.passionpredict.com/', { waitUntil: 'networkidle2', timeout: TIMEOUT });
        await new Promise(r => setTimeout(r, 3000));

        const rows = await page.evaluate(() => {
            const data = [];
            const tables = document.querySelectorAll('table');
            tables.forEach(table => {
                const trs = table.querySelectorAll('tr');
                trs.forEach(tr => {
                    const tds = tr.querySelectorAll('td');
                    if (tds.length >= 4) {
                        const cells = Array.from(tds).map(td => td.innerText.trim());
                        const home = cells[1] || '';
                        const away = cells[3] || '';
                        const tip = cells[4] || '';
                        const odds = cells[5] || '';
                        if (home && away && tip) {
                            data.push({ match: home + ' vs ' + away, home, away, pick: tip, odds, league: '', time: cells[0] || '' });
                        }
                    }
                });
            });
            if (data.length === 0) {
                const matchCards = document.querySelectorAll('[class*="match"], [class*="game"], [class*="prediction"]');
                matchCards.forEach(card => {
                    const text = card.innerText.trim();
                    const vsMatch = text.match(/(.+?)\s+(?:vs|VS|Vs|v\.)\s+(.+?)\s+[-–]\s*(.+?)(?:\n|$)/);
                    if (vsMatch) {
                        data.push({ match: vsMatch[1].trim() + ' vs ' + vsMatch[2].trim(), home: vsMatch[1].trim(), away: vsMatch[2].trim(), pick: vsMatch[3].trim(), odds: '', league: '', time: '' });
                    }
                });
            }
            return data;
        });
        picks.push(...rows);
    } catch (e) {}
    return picks;
}

// ── Site B: r2bet.com (JS-rendered, needs Puppeteer) ──
async function scrapeR2Bet(page) {
    const picks = [];
    try {
        await page.goto('https://www.r2bet.com/', { waitUntil: 'networkidle2', timeout: TIMEOUT });
        await new Promise(r => setTimeout(r, 3000));
        const rows = await page.evaluate(() => {
            const data = [];
            const tables = document.querySelectorAll('table');
            tables.forEach(table => {
                const trs = table.querySelectorAll('tr');
                trs.forEach(tr => {
                    const tds = tr.querySelectorAll('td');
                    if (tds.length >= 3) {
                        const cells = Array.from(tds).map(td => td.innerText.trim());
                        const text = cells.join(' | ');
                        const vsMatch = text.match(/(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s+[-–]\s*(.+?)(?:\||$)/);
                        if (vsMatch) {
                            data.push({ match: vsMatch[1].trim() + ' vs ' + vsMatch[2].trim(), home: vsMatch[1].trim(), away: vsMatch[2].trim(), pick: vsMatch[3].trim(), odds: '', league: '', time: '' });
                        }
                    }
                });
            });
            if (data.length === 0) {
                const blocks = document.querySelectorAll('[class*="match"], [class*="pick"], [class*="game"]');
                blocks.forEach(block => {
                    const text = block.innerText.trim();
                    const vsMatch = text.match(/(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s+[-–]\s*(.+?)(?:\n|$)/);
                    if (vsMatch) {
                        data.push({ match: vsMatch[1].trim() + ' vs ' + vsMatch[2].trim(), home: vsMatch[1].trim(), away: vsMatch[2].trim(), pick: vsMatch[3].trim(), odds: '', league: '', time: '' });
                    }
                });
            }
            return data;
        });
        picks.push(...rows);
    } catch (e) {}
    return picks;
}

// ── Site C: victorspredict.com (Cloudflare-protected, Puppeteer may bypass) ──
async function scrapeVictorsPredict(page) {
    const picks = [];
    try {
        await page.goto('https://victorspredict.com/', { waitUntil: 'networkidle2', timeout: TIMEOUT });
        await new Promise(r => setTimeout(r, 3000));
        const rows = await page.evaluate(() => {
            const data = [];
            const tables = document.querySelectorAll('table');
            tables.forEach(table => {
                const trs = table.querySelectorAll('tr');
                trs.forEach(tr => {
                    const tds = tr.querySelectorAll('td');
                    if (tds.length >= 3) {
                        const cells = Array.from(tds).map(td => td.innerText.trim());
                        const text = cells.join(' | ');
                        const vsMatch = text.match(/(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s+[-–]\s*(.+?)(?:\||$)/);
                        if (vsMatch) {
                            data.push({ match: vsMatch[1].trim() + ' vs ' + vsMatch[2].trim(), home: vsMatch[1].trim(), away: vsMatch[2].trim(), pick: vsMatch[3].trim(), odds: '', league: '', time: '' });
                        }
                    }
                });
            });
            if (data.length === 0) {
                const fixtures = document.querySelectorAll('[class*="sp-fixture"], [class*="sp-event"], [class*="match"], [class*="game"]');
                fixtures.forEach(f => {
                    const text = f.innerText.trim();
                    const vsMatch = text.match(/(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s+[-–]\s*(.+?)(?:\n|$)/);
                    if (vsMatch) {
                        data.push({ match: vsMatch[1].trim() + ' vs ' + vsMatch[2].trim(), home: vsMatch[1].trim(), away: vsMatch[2].trim(), pick: vsMatch[3].trim(), odds: '', league: '', time: '' });
                    }
                });
            }
            return data;
        });
        picks.push(...rows);
    } catch (e) {}
    return picks;
}

// ── Site D: allnigeriafootball.com (clean HTML, use fetch) ──
async function scrapeAllNigeriaFootball() {
    const picks = [];
    try {
        const resp = await fetch('https://allnigeriafootball.com/', { signal: AbortSignal.timeout(15000) });
        const html = await resp.text();
        const pMatches = html.matchAll(/<p[^>]*>(.*?)<\/p>/gis);
        for (const pMatch of pMatches) {
            const pContent = pMatch[1];
            if (!pContent.match(/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/)) continue;
            const parts = pContent.split(/<br\s*\/?>/i);
            if (parts.length < 3) continue;
            const dateTime = parts[0].replace(/<[^>]+>/g, '').trim();
            const league = parts[1].replace(/<[^>]+>/g, '').trim();
            const matchLine = parts[2].replace(/<[^>]+>/g, '').replace(/&#8211;/g, '–').trim();
            const dtMatch = dateTime.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})$/);
            if (!dtMatch) continue;
            if (dtMatch[1] < new Date().toISOString().slice(0, 10)) continue;
            const mLMatch = matchLine.match(/^(.+?)\s+(?:vs|VS|Vs)\s+(.+?)\s*[–-]\s*(.+)$/);
            if (!mLMatch) continue;
            picks.push({ match: mLMatch[1].trim() + ' vs ' + mLMatch[2].trim(), home: mLMatch[1].trim(), away: mLMatch[2].trim(), pick: normalizePick(mLMatch[3].trim()), odds: '', league: league.replace(/^U\d+\s*/, ''), time: dtMatch[2] });
        }
    } catch (e) {}
    return picks;
}

// ── Site E: adibet.com (nested HTML tables with highlighted cells, use fetch) ──
async function scrapeAdibet() {
    const picks = [];
    try {
        const resp = await fetch('https://www.adibet.com/', { signal: AbortSignal.timeout(15000) });
        const html = await resp.text();

        // Find data tables by lazy-matching <table>...</table>.
        // JS's [\s\S]*? stops at the first </table> which may be an inner table close.
        // We keep all tables and then filter at the row level.
        const tableMatches = html.matchAll(/<table[^>]*>([\s\S]*?)<\/table>/gis);
        for (const tblMatch of tableMatches) {
            const tbl = tblMatch[1];
            // Only process tables with dash-separated team names
            if (!tbl.match(/[-–]\s+/) && !tbl.match(/\b1\s+X\s+2\b/i)) continue;

            const rowMatches = tbl.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/gis);
            for (const rowMatch of rowMatches) {
                const row = rowMatch[1];

                const cellMatches = [];
                let tdPos = 0;
                while (true) {
                    const tdS = row.indexOf('<td', tdPos);
                    if (tdS === -1) break;
                    const tdE = row.indexOf('</td>', tdS);
                    if (tdE === -1) break;
                    cellMatches.push(row.substring(tdS, tdE + 5));
                    tdPos = tdE + 5;
                }

                // Data tables have 8 cells (flag, match, 1, X, 2, +1.5, GG, +2.5)
                if (cellMatches.length < 7) continue;

                const cells = cellMatches.map(c => ({
                    text: c.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim(),
                    bg: (c.match(/bgcolor=(["'])([^"']+?)\1/i) || [])[2] || '',
                    raw: c
                }));

                // Skip header row (distinct bg on cell 0)
                if (cells[0].bg && cells[0].bg.toLowerCase() !== '#3e415a' && cells[0].bg.toLowerCase() !== '#272727') continue;

                const matchText = cells[1] ? cells[1].text : '';
                const sep = matchText.indexOf(' \u2013 ') !== -1 ? ' \u2013 ' : (matchText.indexOf(' - ') !== -1 ? ' - ' : null);
                if (!sep) continue;
                const parts = matchText.split(sep);
                if (parts.length < 2) continue;
                const home = parts[0].trim();
                const away = parts.slice(1).join(sep).trim();
                if (!home || !away) continue;

                const colMap = { 2: '1', 3: 'X', 4: '2', 5: '+1.5', 6: 'GG', 7: '+2.5' };
                const highlighted = [];
                for (const [idx, label] of Object.entries(colMap)) {
                    const ci = parseInt(idx);
                    if (cells[ci] && cells[ci].bg && cells[ci].bg.toLowerCase() === '#272727') {
                        highlighted.push(label);
                    }
                }
                if (highlighted.length === 0) continue;

                let league = '';
                const altMatch = cells[0].raw.match(/alt=(["'])([^"']+)\1/i);
                if (altMatch) league = altMatch[2];

                const resultPicks = highlighted.filter(h => h === '1' || h === 'X' || h === '2');
                const ouPicks = highlighted.filter(h => h === '+1.5' || h === '+2.5');
                const bttsPicks = highlighted.filter(h => h === 'GG');

                if (resultPicks.length > 0) picks.push({ match: home + ' vs ' + away, home, away, pick: normalizePick(resultPicks.join('')), odds: '', league, time: '' });
                for (const ou of ouPicks) picks.push({ match: home + ' vs ' + away, home, away, pick: normalizePick(ou), odds: '', league, time: '' });
                for (const bt of bttsPicks) picks.push({ match: home + ' vs ' + away, home, away, pick: normalizePick(bt), odds: '', league, time: '' });
            }
        }
    } catch (e) {}
    return picks;
}

// ── Pick normalizer (mirrors PHP normalizer) ──
function normalizePick(raw) {
    let s = raw.replace(/[\x80-\xFF]/g, ' ').replace(/[^\x20-\x7E]/g, ' ');
    s = s.replace(/\s+/g, ' ').trim().toLowerCase();
    const map = {
        'home': 'Home(1)', 'home win': 'Home(1)', '1': 'Home(1)',
        'away': 'Away(2)', 'away win': 'Away(2)', '2': 'Away(2)',
        'draw': 'Draw(X)', 'x': 'Draw(X)', 'draw (x)': 'Draw(X)',
        '1x': '1X', 'x2': 'X2', '12': '12',
        'gg': 'GG(BTTS)', 'btts': 'GG(BTTS)', 'both teams to score': 'GG(BTTS)', 'ng': 'NG',
        'over 1.5': 'Over 1.5', '+1.5': 'Over 1.5', 'under 1.5': 'Under 1.5', '-1.5': 'Under 1.5',
        'over 2.5': 'Over 2.5', '+2.5': 'Over 2.5', 'under 2.5': 'Under 2.5', '-2.5': 'Under 2.5',
        'over 3.5': 'Over 3.5', '+3.5': 'Over 3.5', 'under 3.5': 'Under 3.5', '-3.5': 'Under 3.5',
        'home(dnb)': 'Home(DNB)', 'away(dnb)': 'Away(DNB)',
        'home clean sheet': 'Home Clean Sheet', 'away clean sheet': 'Away Clean Sheet',
        'home win either half': 'Home Win Either Half', 'away win either half': 'Away Win Either Half',
    };
    if (map[s]) return map[s];
    for (const [key, val] of Object.entries(map)) {
        if (s.startsWith(key) || key.startsWith(s)) return val;
    }
    if (s.match(/\(draw no bet\)/)) return 'Home(DNB)';
    const chars = s.replace(/[^1x2]/g, '').toUpperCase();
    if (chars === '1') return 'Home(1)';
    if (chars === '2') return 'Away(2)';
    if (chars === 'X') return 'Draw(X)';
    if (chars === '1X') return '1X';
    if (chars === 'X2') return 'X2';
    if (chars === '12') return '12';
    const ouMatch = s.match(/(over|under)\s*(\d+\.?\d*)/i);
    if (ouMatch) return ouMatch[1].charAt(0).toUpperCase() + ouMatch[1].slice(1).toLowerCase() + ' ' + ouMatch[2];
    return raw.trim();
}

// ── Main ──
(async () => {
    const browser = await puppeteer.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-web-security']
    });
    const page = await browser.newPage();
    await page.setUserAgent(UA);
    await page.setViewport({ width: 1366, height: 768 });

    const result = { generated_at: new Date().toISOString(), sources: {} };

    console.error('[scrape_predictions] Starting all scrapers...');

    result.sources.passionpredict = await scrapePassionPredict(page);
    console.error('[passionpredict] ' + result.sources.passionpredict.length + ' picks');

    result.sources.r2bet = await scrapeR2Bet(page);
    console.error('[r2bet] ' + result.sources.r2bet.length + ' picks');

    result.sources.victorspredict = await scrapeVictorsPredict(page);
    console.error('[victorspredict] ' + result.sources.victorspredict.length + ' picks');

    await browser.close();

    result.sources.allnigeriafootball = await scrapeAllNigeriaFootball();
    console.error('[allnigeriafootball] ' + result.sources.allnigeriafootball.length + ' picks');

    result.sources.adibet = await scrapeAdibet();
    console.error('[adibet] ' + result.sources.adibet.length + ' picks');

    console.log(JSON.stringify(result));
    console.error('[scrape_predictions] Done.');
})();
