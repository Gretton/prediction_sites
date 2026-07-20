#!/usr/bin/env python3
"""
Historical match scraper: backfills match_results with past scores + leagues.
Primary source: worldfootball.net (static HTML).
Fallback: ESPN FC.

Usage:
  python cron/historical_scraper.py [--key=SECRET] [--seasons=3] [--leagues=subset]
  python cron/historical_scraper.py --key=SECRET --seasons=2 --dry-run
  python cron/historical_scraper.py --key=SECRET --recent

Options:
  --key=SECRET     Key for fetch_scores.php endpoint (required)
  --seasons=N      How many past seasons to scrape (default: 3)
  --recent         Only scrape last 7 days (for daily cron)
  --leagues=NAME   Comma-separated league slugs (default: all major)
  --dry-run        Print matches without posting
  --hook=URL       Optional webhook URL
"""

import os, sys, re, json, time, urllib.parse

# ─── CONFIG ───────────────────────────────────────────────
SITE_BASE = "https://www.worldfootball.net"

# League slugs + ESPN league IDs for fallback
MAJOR_LEAGUES = {
    "eng-premier-league":   ("England - Premier League", "ENG.1"),
    "eng-championship":     ("England - Championship", "ENG.2"),
    "eng-league-one":       ("England - League One", "ENG.3"),
    "spa-primera-division": ("Spain - La Liga", "ESP.1"),
    "spa-segunda-division": ("Spain - La Liga 2", "ESP.2"),
    "ita-serie-a":          ("Italy - Serie A", "ITA.1"),
    "ita-serie-b":          ("Italy - Serie B", "ITA.2"),
    "ger-bundesliga":       ("Germany - Bundesliga", "GER.1"),
    "ger-2-bundesliga":     ("Germany - 2. Bundesliga", "GER.2"),
    "fra-ligue-1":          ("France - Ligue 1", "FRA.1"),
    "fra-ligue-2":          ("France - Ligue 2", "FRA.2"),
    "por-primeira-liga":    ("Portugal - Primeira Liga", "POR.1"),
    "ned-eredivisie":       ("Netherlands - Eredivisie", "NED.1"),
    "bel-pro-league":       ("Belgium - Pro League", "BEL.1"),
    "sco-premiership":      ("Scotland - Premiership", "SCO.1"),
    "tur-sueperlig":        ("Turkey - Süper Lig", "TUR.1"),
    "gre-superleague":      ("Greece - Super League", "GRE.1"),
    "sui-super-league":     ("Switzerland - Super League", "SUI.1"),
    "aut-bundesliga":       ("Austria - Bundesliga", "AUT.1"),
    "den-superliga":        ("Denmark - Superliga", "DEN.1"),
    "swe-allsvenskan":      ("Sweden - Allsvenskan", "SWE.1"),
    "nor-eliteserien":      ("Norway - Eliteserien", "NOR.1"),
    "rus-premier-liga":     ("Russia - Premier Liga", "RUS.1"),
    "ukr-premyer-liga":     ("Ukraine - Premier League", "UKR.1"),
    "cze-first-league":     ("Czech Republic - First League", "CZE.1"),
    "rou-liga-i":           ("Romania - Liga I", "ROU.1"),
    "bul-first-league":     ("Bulgaria - First League", "BUL.1"),
    "cro-hnl":              ("Croatia - HNL", "CRO.1"),
    "hun-nb-i":             ("Hungary - NB I", "HUN.1"),
    "pol-ekstraklasa":      ("Poland - Ekstraklasa", "POL.1"),
    "ser-superliga":        ("Serbia - Super Liga", "SRB.1"),
    "jpn-j1-league":        ("Japan - J1 League", "JPN.1"),
    "sau-saudi-league":     ("Saudi Arabia - Saudi League", "SAU.1"),
    "bra-serie-a":          ("Brazil - Série A", "BRA.1"),
    "arg-liga-profesional": ("Argentina - Liga Profesional", "ARG.1"),
}

CHROME_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36"
CURRENT_YEAR = time.localtime().tm_year


def log(msg):
    print(f"[{time.strftime('%H:%M:%S')}] {msg}")


# ─── HTTP with session ────────────────────────────────────
_http = None

def get_http():
    global _http
    if _http is not None:
        return _http
    try:
        # Try cloudscraper first (bypasses Cloudflare/WAF)
        try:
            import cloudscraper
            _http = cloudscraper.create_scraper()
            _http.headers.update({
                "User-Agent": CHROME_UA,
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "en-US,en;q=0.9,de;q=0.8",
                "Accept-Encoding": "gzip, deflate, br",
            })
            log("  using cloudscraper")
            return _http
        except ImportError:
            pass
        import requests as req
        sess = req.Session()
        sess.headers.update({
            "User-Agent": CHROME_UA,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9,de;q=0.8",
            "Accept-Encoding": "gzip, deflate, br",
            "Referer": "https://www.google.com/",
            "DNT": "1",
            "Connection": "keep-alive",
            "Upgrade-Insecure-Requests": "1",
            "Sec-Fetch-Dest": "document",
            "Sec-Fetch-Mode": "navigate",
            "Sec-Fetch-Site": "none",
            "Sec-Fetch-User": "?1",
        })
        _http = sess
        return sess
    except ImportError:
        log("  requests not available, using urllib")
    return None


def get_html(url, retries=3):
    """Fetch a page with retries. Tries cloudscraper first, then fallback."""
    http = get_http()
    if http:
        for attempt in range(retries):
            try:
                resp = http.get(url, timeout=30)
                if resp.status_code == 200:
                    txt = resp.text
                    if "standard_tabelle" in txt or "Spielplan" in txt or "schedule" in txt:
                        return txt, "wf"
                    log(f"  -> no table found, retry {attempt+1}")
                else:
                    log(f"  -> HTTP {resp.status_code}, retry {attempt+1}")
                    if attempt < retries - 1:
                        time.sleep(1.5 ** attempt)
            except Exception as e:
                log(f"  -> error: {e}, retry {attempt+1}")
                if attempt < retries - 1:
                    time.sleep(1.5 ** attempt)
        return None, None

    # Fallback: urllib
    hdrs = {"User-Agent": CHROME_UA, "Accept": "text/html", "Accept-Language": "en-US,en;q=0.9"}
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers=hdrs)
            with urllib.request.urlopen(req, timeout=30) as resp:
                data = resp.read().decode("utf-8", errors="replace")
                if "standard_tabelle" in data or "Spielplan" in data or "schedule" in data:
                    return data, "wf"
        except Exception:
            time.sleep(1.5 ** attempt)
    return None, None


# ─── ESPN fallback ────────────────────────────────────────
ESPN_BASE = "https://www.espn.com/soccer/scoreboard/_/league"

def get_espn_html(league_id, year, month, day, retries=2):
    """Try ESPN as fallback for a specific date."""
    http = get_http()
    if not http:
        return None
    url = f"{ESPN_BASE}/{league_id}/date/{year}{month:02d}{day:02d}"
    for a in range(retries):
        try:
            resp = http.get(url, timeout=20)
            if resp.status_code == 200:
                return resp.text
        except Exception:
            time.sleep(1)
    return None


def parse_espn_matches(html, league_display):
    """Parse ESPN scoreboard HTML for finished matches."""
    matches = []
    # ESPN embeds match data in a script tag with __NEXT_DATA__ or in data- attributes
    # Look for match cards with final scores
    # Pattern: score cells with "fullScore" or "final" class
    
    # Find __NEXT_DATA__ JSON blob (Next.js sites)
    nd_match = re.search(r'<script id="__NEXT_DATA__"[^>]*type="application/json"[^>]*>(.*?)</script>', html, re.DOTALL)
    if nd_match:
        try:
            data = json.loads(nd_match.group(1))
            # Navigate to find matches - ESPN's Next.js state varies
            def walk(obj, depth=0):
                results = []
                if depth > 7 or not isinstance(obj, (dict, list)):
                    return results
                if isinstance(obj, list):
                    for v in obj:
                        results.extend(walk(v, depth + 1))
                    return results
                if isinstance(obj, dict):
                    if all(k in obj for k in ["homeTeam", "awayTeam", "homeScore", "awayScore", "status"]):
                        results.append(obj)
                    for v in obj.values():
                        results.extend(walk(v, depth + 1))
                return results
            
            all_matches_in_state = walk(data)
            for m in all_matches_in_state:
                try:
                    status = m.get("status", {})
                    if isinstance(status, dict):
                        st_type = status.get("type", "")
                        st_detail = status.get("detail", "")
                        if st_type not in ("finished", "completed") and "FT" not in str(st_detail):
                            continue
                    h_name = m.get("homeTeam", {}).get("name", "") or m.get("homeTeam", {}).get("displayName", "")
                    a_name = m.get("awayTeam", {}).get("name", "") or m.get("awayTeam", {}).get("displayName", "")
                    hs = m.get("homeScore", {})
                    as_ = m.get("awayScore", {})
                    if isinstance(hs, dict): hs_score = hs.get("score") or hs.get("value") or hs.get("current")
                    else: hs_score = hs
                    if isinstance(as_, dict): as_score = as_.get("score") or as_.get("value") or as_.get("current")
                    else: as_score = as_
                    if not h_name or not a_name or hs_score is None or as_score is None:
                        continue
                    matches.append({
                        "home_team": str(h_name).strip(),
                        "away_team": str(a_name).strip(),
                        "home_score": int(hs_score),
                        "away_score": int(as_score),
                        "league": league_display,
                    })
                except (ValueError, TypeError, AttributeError):
                    continue
        except (json.JSONDecodeError, Exception):
            pass
        if matches:
            return matches

    # Fallback: regex for pattern in HTML
    # Look for scoreboard__card or similar structures
    card_pattern = re.compile(
        r'<div[^>]*class="[^"]*ScoreboardScoreCell[^"]*"[^>]*>.*?'
        r'<span[^>]*class="[^"]*ScoreCell__TeamName[^"]*"[^>]*>([^<]+)</span>.*?'
        r'<span[^>]*class="[^"]*ScoreCell__Score[^"]*"[^>]*>(\d+)</span>.*?'
        r'<span[^>]*class="[^"]*ScoreCell__TeamName[^"]*"[^>]*>([^<]+)</span>.*?'
        r'<span[^>]*class="[^"]*ScoreCell__Score[^"]*"[^>]*>(\d+)</span>',
        re.DOTALL
    )
    for cm in card_pattern.finditer(html):
        matches.append({
            "home_team": cm.group(1).strip(),
            "away_team": cm.group(3).strip(),
            "home_score": int(cm.group(2)),
            "away_score": int(cm.group(4)),
            "league": league_display,
        })
    
    return matches


# ─── Worldfootball parser ─────────────────────────────────
def parse_wf_rows(html, league_display):
    """Extract matches from worldfootball.net table rows."""
    matches = []
    row_re = re.compile(
        r'<tr[^>]*>.*?'
        r'<td[^>]*class[^>]*>[^<]*(\d{2}\.\d{2}\.\d{4})[^<]*</td>.*?'
        r'<td[^>]*>.*?<a[^>]*>([^<]+)</a>.*?</td>.*?'
        r'<td[^>]*class[^>]*>.*?(\d+)[:\s](-?\s?\d+).*?</td>.*?'
        r'<td[^>]*>.*?<a[^>]*>([^<]+)</a>.*?</td>.*?'
        r'</tr>', re.DOTALL | re.IGNORECASE
    )
    for rm in row_re.finditer(html):
        date_str = rm.group(1).strip()
        home = rm.group(2).strip()
        score_h = rm.group(3).strip()
        score_a_raw = rm.group(4).strip().replace(" ", "")
        away = rm.group(5).strip()
        parts = date_str.split(".")
        if len(parts) != 3: continue
        match_date = f"{parts[2]}-{parts[1].zfill(2)}-{parts[0].zfill(2)}"
        try:
            hs = int(score_h)
            ascore = int(score_a_raw) if score_a_raw.lstrip("-").isdigit() else None
        except ValueError:
            continue
        if ascore is None: continue
        home = re.sub(r'\s+', ' ', home).strip()
        away = re.sub(r'\s+', ' ', away).strip()
        if not home or not away: continue
        matches.append({
            "home_team": home, "away_team": away,
            "home_score": hs, "away_score": ascore,
            "match_date": match_date, "league": league_display,
        })
    return matches


# ─── League scraping ──────────────────────────────────────
def scrape_league(slug, display_name, espn_id, seasons=3):
    """Scrape past N seasons for a league from worldfootball.net, fallback ESPN."""
    all_matches = []
    
    for s in range(seasons):
        season_start = CURRENT_YEAR - s
        season_end = season_start + 1
        season_str = f"{season_start}-{season_end}"
        
        # Try worldfootball URLs
        urls = [
            f"{SITE_BASE}/schedule/{slug}-{season_str}-spieltag/",
            f"{SITE_BASE}/schedule/{slug}-{season_start}-spieltag/",
            f"{SITE_BASE}/competition/{slug}/",
        ]
        if season_start < CURRENT_YEAR:
            urls.append(f"{SITE_BASE}/competition/{slug}/?season={season_start}")
        
        html = None
        source = None
        used_url = None
        for u in urls:
            html, source = get_html(u)
            if html:
                used_url = u
                break
        
        rows = []
        if html and source == "wf":
            rows = parse_wf_rows(html, display_name)
        
        if rows:
            log(f"  [{slug}] Season {season_str}: {len(rows)} matches (worldfootball)")
            all_matches.extend(rows)
            time.sleep(0.8)
            continue
        
        # Fallback: ESPN (try a few dates in the season)
        if espn_id:
            log(f"  [{slug}] Season {season_str}: trying ESPN fallback...")
            espn_matches = set()
            # Sample dates: mid-season (Oct-Dec, Feb-May) for the season
            try:
                import calendar
                # Determine start/end year for the season
                sy = season_start
                if season_end == CURRENT_YEAR + 1:
                    ey = CURRENT_YEAR
                else:
                    ey = season_end
                
                # Sample months
                months_to_try = []
                for m in range(8, 13):  # Aug-Dec
                    months_to_try.append((sy, m))
                for m in range(1, 6):   # Jan-May
                    months_to_try.append((ey, m))
                
                for yr, mo in months_to_try:
                    if yr > CURRENT_YEAR or (yr == CURRENT_YEAR and mo > time.localtime().tm_mon):
                        continue
                    max_day = min(28, calendar.monthrange(yr, mo)[1])
                    espn_html = get_espn_html(espn_id, yr, mo, 15)
                    if not espn_html:
                        continue
                    espn_rows = parse_espn_matches(espn_html, display_name)
                    for r in espn_rows:
                        key = f"{r['home_team']}|{r['away_team']}|{r['home_score']}|{r['away_score']}"
                        if key not in espn_matches:
                            espn_matches.add(key)
                            # ESPN doesn't give dates easily; approximate
                            r["match_date"] = f"{yr}-{mo:02d}-15"
                            all_matches.append(r)
                    time.sleep(0.5)
                
                if all_matches:
                    log(f"  [{slug}] ESPN fallback: {len(espn_matches)} unique matches")
            except Exception as e:
                log(f"  [{slug}] ESPN fallback error: {e}")
        else:
            log(f"  [{slug}] Season {season_str}: no matches found")
        
        time.sleep(0.5)
    
    return all_matches


def post_matches(matches, secret_key, hook_url=None, dry_run=False):
    if not matches:
        return 0
    if dry_run:
        log(f"  DRY RUN: would post {len(matches)} matches")
        for m in matches[:3]:
            log(f"    {m.get('match_date','?')} {m['home_team']} {m['home_score']}-{m['away_score']} {m['away_team']} [{m['league']}]")
        if len(matches) > 3:
            log(f"    ... and {len(matches)-3} more")
        return len(matches)
    
    base_url = hook_url or "https://predixa.co.tz/cron/fetch_scores.php"
    url = f"{base_url}?key={urllib.parse.quote(secret_key)}"
    payload = json.dumps({"matches": matches}).encode("utf-8")
    
    http = get_http()
    if http:
        for attempt in range(3):
            try:
                resp = http.post(url, data=payload, timeout=60)
                result = resp.json()
                log(f"  POST result: {result}")
                return result.get("inserted", 0)
            except Exception as e:
                log(f"  POST error (attempt {attempt+1}): {e}")
                if attempt < 2: time.sleep(3)
        return 0
    
    # fallback urllib
    hdrs = {"User-Agent": CHROME_UA, "Content-Type": "application/json"}
    for attempt in range(3):
        try:
            req = urllib.request.Request(url, data=payload, headers=hdrs, method="POST")
            with urllib.request.urlopen(req, timeout=60) as resp:
                result = json.loads(resp.read().decode("utf-8"))
                log(f"  POST result: {result}")
                return result.get("inserted", 0)
        except Exception as e:
            log(f"  POST error (attempt {attempt+1}): {e}")
            if attempt < 2: time.sleep(3)
    return 0


def main():
    argv = sys.argv[1:]
    secret_key = ""
    seasons = 3
    league_filter = None
    dry_run = False
    hook_url = None
    recent_days = 0
    
    for a in argv:
        if a == "--dry-run": dry_run = True
        elif a == "--recent": recent_days = 7
        elif a.startswith("--key="): secret_key = a.split("=", 1)[1]
        elif a.startswith("--seasons="): seasons = int(a.split("=", 1)[1])
        elif a.startswith("--leagues="): league_filter = a.split("=", 1)[1].split(",")
        elif a.startswith("--hook="): hook_url = a.split("=", 1)[1]
    
    if not secret_key and not dry_run:
        print("Error: --key=SECRET is required (or use --dry-run)")
        sys.exit(1)
    
    leagues_to_scrape = []
    if league_filter:
        for slug in league_filter:
            slug = slug.strip()
            if slug in MAJOR_LEAGUES:
                leagues_to_scrape.append((slug, MAJOR_LEAGUES[slug][0], MAJOR_LEAGUES[slug][1]))
            else:
                log(f"Unknown league slug: {slug}")
    else:
        leagues_to_scrape = [(k, v[0], v[1]) for k, v in MAJOR_LEAGUES.items()]
    
    mode = "RECENT (7 days)" if recent_days else f"{seasons} season(s)"
    log(f"Scraping {len(leagues_to_scrape)} leagues, {mode}, dry_run={dry_run}")
    
    recent_cutoff = None
    if recent_days:
        from datetime import datetime, timedelta
        recent_cutoff = (datetime.now() - timedelta(days=recent_days)).strftime("%Y-%m-%d")
        log(f"  Only matches after {recent_cutoff}")
    
    total_posted = 0
    total_found = 0
    
    for slug, display, espn_id in leagues_to_scrape:
        log(f"\n--- {display} ({slug}) ---")
        matches = scrape_league(slug, display, espn_id, seasons)
        if not matches:
            continue
        
        if recent_cutoff:
            matches = [m for m in matches if m.get("match_date", "") >= recent_cutoff]
            log(f"  After date filter: {len(matches)} matches remain")
        
        if not matches:
            continue
        total_found += len(matches)
        
        batch_size = 50
        for i in range(0, len(matches), batch_size):
            batch = matches[i:i+batch_size]
            posted = post_matches(batch, secret_key, hook_url, dry_run)
            total_posted += posted
            if not dry_run:
                time.sleep(1)
    
    log(f"\n=== Done ===")
    log(f"  Matches found: {total_found}")
    if not dry_run:
        log(f"  Inserted into match_results: {total_posted}")
    else:
        log(f"  (dry run — no writes performed)")


if __name__ == "__main__":
    main()
