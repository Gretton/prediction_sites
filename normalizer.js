const normalizePick = (raw) => {
  if (!raw) return '';
  let s = String(raw)
    .replace(/[\x80-\xFF]/g, ' ')
    .replace(/[^\x20-\x7E]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  const map = [
    [/^home\s*win$/i, 'Home(1)'],
    [/^home\s*$/i, 'Home(1)'],
    [/^1$/i, 'Home(1)'],
    [/^1\s*\.$/i, 'Home(1)'],
    [/^home\s*\(1\)$/i, 'Home(1)'],
    [/^home\s*win\s*\(1\)$/i, 'Home(1)'],
    [/^draw$/i, 'Draw(X)'],
    [/^draw\s*\(x\)$/i, 'Draw(X)'],
    [/^x$/i, 'Draw(X)'],
    [/^away\s*win$/i, 'Away(2)'],
    [/^away\s*$/i, 'Away(2)'],
    [/^2$/i, 'Away(2)'],
    [/^2\s*\.$/i, 'Away(2)'],
    [/^away\s*\(2\)$/i, 'Away(2)'],
    [/^away\s*win\s*\(2\)$/i, 'Away(2)'],
    [/^1x$/i, '1X'],
    [/^home\s*win\s*or\s*draw$/i, '1X'],
    [/^home\s*or\s*draw$/i, '1X'],
    [/^x2$/i, 'X2'],
    [/^away\s*win\s*or\s*draw$/i, 'X2'],
    [/^away\s*or\s*draw$/i, 'X2'],
    [/^12$/i, '12'],
    [/^home\s*or\s*away$/i, '12'],
    [/^over\s*(\d+\.?\d*)\s*(goals)?$/i, 'Over $1'],
    [/^o\s*(\d+\.?\d*)/i, 'Over $1'],
    [/^\+(\d+\.?\d*)/i, 'Over $1'],
    [/^over$/i, 'Over 1.5'],
    [/^under\s*(\d+\.?\d*)\s*(goals)?$/i, 'Under $1'],
    [/^u\s*(\d+\.?\d*)/i, 'Under $1'],
    [/^under$/i, 'Under 3.5'],
    [/^gg$/i, 'GG(BTTS)'],
    [/^btts$/i, 'GG(BTTS)'],
    [/^both\s*teams\s*to\s*score$/i, 'GG(BTTS)'],
    [/^goal\s*goal$/i, 'GG(BTTS)'],
    [/^yes$/i, 'GG(BTTS)'],
    [/^ng$/i, 'NG'],
    [/^no$/i, 'NG'],
    [/^home\s*win\s*either\s*half$/i, 'Home Win Either Half'],
    [/^hweh$/i, 'Home Win Either Half'],
    [/^away\s*win\s*either\s*half$/i, 'Away Win Either Half'],
    [/^aweh$/i, 'Away Win Either Half'],
    [/^weh$/i, 'Home Win Either Half'],
    [/^home\s*\(dnb\)$/i, 'Home(DNB)'],
    [/^away\s*\(dnb\)$/i, 'Away(DNB)'],
    [/^draw\s*no\s*bet\s*home$/i, 'Home(DNB)'],
    [/^draw\s*no\s*bet\s*away$/i, 'Away(DNB)'],
    [/^\(draw no bet\)\s*/i, 'Home(DNB)'],
    [/^home\s*clean\s*sheet$/i, 'Home Clean Sheet'],
    [/^away\s*clean\s*sheet$/i, 'Away Clean Sheet'],
  ];

  for (const [pat, repl] of map) {
    if (pat.test(s)) {
      return s.replace(pat, repl);
    }
  }

  const t = s.trim();
  if (/^1$/.test(t)) return 'Home(1)';
  if (/^2$/.test(t)) return 'Away(2)';
  if (/^[Xx]$/.test(t)) return 'Draw(X)';
  if (/^1[Xx]$/.test(t)) return '1X';
  if (/^[Xx]2$/.test(t)) return 'X2';
  if (/^12$/.test(t)) return '12';
  if (/^\(?1\)?\.?\s*$/.test(t)) return 'Home(1)';
  if (/^\(?2\)?\.?\s*$/.test(t)) return 'Away(2)';
  if (/^\(?[Xx]\)?\.?\s*$/.test(t)) return 'Draw(X)';

  return t;
};

const normalizeTeam = (name) => {
  if (!name) return '';
  let s = String(name).replace(/\s+/g, ' ').trim();
  s = s.replace(/^(FC|AS|AC|SS|SC|CD|EC|SSC|GAIS|IFK|IF|BK|FK|SK|IK|IL)\s+/i, '');
  s = s.replace(/\s+(FC|AS|AC|SS|SC|CD|EC|SSC|GAIS|IFK|IF|BK|FK|SK|IK|IL)$/i, '');
  s = s.replace(/\s*(U19|U20|U21|U23|II|2|B|Reserves?)\s*$/i, '');
  s = s.replace(/[^\w\s]/g, '');
  s = s.toLowerCase().trim();
  const subs = {
    'manchester': 'man', 'united': 'utd', 'city': 'cty',
    'athletic': 'ath', 'athletico': 'ath', 'internacional': 'inter',
    'internazionale': 'inter',
    'paris saint germain': 'psg', 'real madrid': 'realmadrid',
    'barcelona': 'barca', 'chelsea': 'chelsea',
    'liverpool': 'liverpool', 'arsenal': 'arsenal',
    'juvenil': 'juv', 'sao': 'sao', 'bologna': 'bol',
    'deportivo': 'dep', 'sportivo': 'sport',
    // City name variants (English ↔ native)
    'praha': 'prague', 'praga': 'prague',
    'muenchen': 'munich', 'münchen': 'munich',
    'koeln': 'cologne', 'köln': 'cologne',
    'roma': 'rome', 'romae': 'rome',
    'milano': 'milan',
    'torino': 'turin',
    'napoli': 'naples',
    'firenze': 'florence',
    'wien': 'vienna',
    'basel': 'basel',
    'geneve': 'geneva', 'genf': 'geneva',
    'beograd': 'belgrade',
    'warszawa': 'warsaw',
    'moskva': 'moscow',
    'kiev': 'kyiv',
    'bruxelles': 'brussels', 'brussel': 'brussels',
    'bucuresti': 'bucharest',
    'lisboa': 'lisbon',
    'porto': 'porto',
    'sevilla': 'seville',
    'quebec': 'quebec',
    // Common club suffixes/nicknames
    'utd': 'utd', 'fc': 'fc', 'ac': 'ac',
    'juventus': 'juve',
    'levski': 'levski',
  };
  for (const [from, to] of Object.entries(subs)) {
    s = s.replace(from, to);
  }
  return s.trim();
};

const teamsMatch = (a, b) => {
  if (!a || !b) return false;
  const na = normalizeTeam(a);
  const nb = normalizeTeam(b);
  if (na === nb) return true;
  if (na.includes(nb) || nb.includes(na)) return true;
  const wa = na.split(' ');
  const wb = nb.split(' ');
  const common = wa.filter(w => wb.includes(w));
  const maxLen = Math.max(wa.length, wb.length);
  return maxLen > 0 && common.length / maxLen >= 0.6;
};

module.exports = { normalizePick, normalizeTeam, teamsMatch };
