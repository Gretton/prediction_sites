const fetch = require('node-fetch');
const cheerio = require('cheerio');

async function scrape() {
  const res = await fetch('https://livescore.sportybet.com/', {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
    timeout: 20000,
  });
  const html = await res.text();
  const $ = cheerio.load(html);
  const matches = [];

  // Find all match card containers by the scores section
  $('[class*=sh-match__scores]').each((_, scoresEl) => {
    // Get the parent match card
    const matchCard = $(scoresEl).closest('[class]').parent();
    if (!matchCard.length) return;

    // Check for "Ended" status
    const statusEl = matchCard.find('[class*=sh-match__status]');
    const statusText = statusEl.text();
    if (!statusText.includes('Ended') && !statusText.includes('END')) return;

    // Get team names
    const teamsSection = matchCard.find('[class*=sh-match__teams]');
    const teamEls = teamsSection.find('.truncate');
    const homeTeam = $(teamEls[0]).text().trim();
    const awayTeam = $(teamEls[1]).text().trim();
    if (!homeTeam || !awayTeam) return;

    // Get scores: first two rounded-match__score divs under scores section
    const scoreEls = $(scoresEl).find('[class*=rounded-match__score]');
    const homeScore = parseInt($(scoreEls[0]).text().trim());
    const awayScore = parseInt($(scoreEls[1]).text().trim());
    if (isNaN(homeScore) || isNaN(awayScore)) return;
    if (homeScore > 15 || awayScore > 15) return; // Not football

    matches.push({ home_team: homeTeam, away_team: awayTeam, home_score: homeScore, away_score: awayScore });
  });

  // Deduplicate
  const seen = new Set();
  const unique = [];
  for (const match of matches) {
    const key = match.home_team + '||' + match.away_team;
    if (!seen.has(key)) { seen.add(key); unique.push(match); }
  }

  return unique;
}

if (require.main === module) {
  scrape().then(m => {
    console.log(JSON.stringify({ matches: m }));
    console.error(`Found ${m.length} finished matches`);
  }).catch(e => {
    console.error('Error:', e.message);
    process.exit(1);
  });
}

module.exports = { scrape, SOURCE: 'sportybet' };
