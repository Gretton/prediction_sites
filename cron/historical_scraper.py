#!/usr/bin/env python3
"""
Historical match scraper: backfills match_results with past scores + leagues.
Scrapes worldfootball.net (static HTML, no JS needed, no API key).

Usage:
  python cron/historical_scraper.py [--key=SECRET] [--seasons=3] [--leagues=subset]
  python cron/historical_scraper.py --key=SECRET --seasons=2 --dry-run

Options:
  --key=SECRET     Key for fetch_scores.php endpoint (required)
  --seasons=N      How many past seasons to scrape (default: 3)
  --leagues=NAME   Comma-separated league slugs (default: all major)
  --dry-run        Print matches without posting
  --once           Single league only (testing), e.g. --leagues=eng-premier-league
  --hook=URL       Optional webhook URL to POST to (default: auto-detected)
"""

import os, sys, re, json, time, urllib.request, urllib.parse

# ─── CONFIG ───────────────────────────────────────────────
SITE_BASE = "https://www.worldfootball.net"

# League slugs → display names (worldfootball.net uses these in URLs)
MAJOR_LEAGUES = {
    # England
    "eng-premier-league":    "England - Premier League",
    "eng-championship":      "England - Championship",
    "eng-league-one":        "England - League One",
    # Spain
    "spa-primera-division":  "Spain - La Liga",
    "spa-segunda-division":  "Spain - La Liga 2",
    # Italy
    "ita-serie-a":           "Italy - Serie A",
    "ita-serie-b":           "Italy - Serie B",
    # Germany
    "ger-bundesliga":        "Germany - Bundesliga",
    "ger-2-bundesliga":      "Germany - 2. Bundesliga",
    # France
    "fra-ligue-1":           "France - Ligue 1",
    "fra-ligue-2":           "France - Ligue 2",
    # Portugal
    "por-primeira-liga":     "Portugal - Primeira Liga",
    # Netherlands
    "ned-eredivisie":        "Netherlands - Eredivisie",
    # Belgium
    "bel-pro-league":        "Belgium - Pro League",
    # Scotland
    "sco-premiership":       "Scotland - Premiership",
    # Turkey
    "tur-sueperlig":         "Turkey - Süper Lig",
    # Greece
    "gre-superleague":       "Greece - Super League",
    # Switzerland
    "sui-super-league":      "Switzerland - Super League",
    # Austria
    "aut-bundesliga":        "Austria - Bundesliga",
    # Denmark
    "den-superliga":         "Denmark - Superliga",
    # Sweden
    "swe-allsvenskan":       "Sweden - Allsvenskan",
    # Norway
    "nor-eliteserien":       "Norway - Eliteserien",
    # Russia
    "rus-premier-liga":      "Russia - Premier Liga",
    # Ukraine
    "ukr-premyer-liga":      "Ukraine - Premier League",
    # Czech Republic
    "cze-first-league":      "Czech Republic - First League",
    # Romania
    "rou-liga-i":            "Romania - Liga I",
    # Bulgaria
    "bul-first-league":      "Bulgaria - First League",
    # Croatia
    "cro-hnl":               "Croatia - HNL",
    # Hungary
    "hun-nb-i":              "Hungary - NB I",
    # Poland
    "pol-ekstraklasa":       "Poland - Ekstraklasa",
    # Serbia
    "ser-superliga":         "Serbia - Super Liga",
    # Japan
    "jpn-j1-league":         "Japan - J1 League",
    # Saudi Arabia
    "sau-saudi-league":      "Saudi Arabia - Saudi League",
    # China
    "chn-super-league":      "China - Super League",
    # Brazil
    "bra-serie-a":           "Brazil - Série A",
    # Argentina
    "arg-liga-profesional":  "Argentina - Liga Profesional",
}

USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
CURRENT_YEAR = time.localtime().tm_year


def log(msg):
    print(f"[{time.strftime('%H:%M:%S')}] {msg}")


def get_html(url, retries=3):
    """Fetch a page with retries + backoff."""
    hdrs = {"User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml",
            "Accept-Language": "en-US,en;q=0.9"}
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers=hdrs)
            with urllib.request.urlopen(req, timeout=30) as resp:
                data = resp.read().decode("utf-8", errors="replace")
                if "Spielplan" in data or "schedule" in data or "table" in data or "standard_tabelle" in data:
                    return data
                log(f"  -> suspicious page (no table found), retry {attempt+1}")
        except Exception as e:
            log(f"  -> fetch error: {e}, retry {attempt+1}")
            time.sleep(2 ** attempt)
    return None


def parse_match_rows(html, league_display):
    """Extract (home, away, home_score, away_score, date) from league table rows."""
    matches = []
    # worldfootball.net uses tables with class "standard_tabelle"
    # Each row: <tr><td>date</td><td>home</td><td>score</td><td>away</td>...</tr>
    # Score is "2-1" or "2:1", sometimes with extra links
    
    # Find all table rows
    row_re = re.compile(
        r'<tr[^>]*>.*?'
        r'<td[^>]*class[^>]*>[^<]*(\d{2}\.\d{2}\.\d{4})[^<]*</td>.*?'  # date
        r'<td[^>]*>.*?<a[^>]*>([^<]+)</a>.*?</td>.*?'                   # home team
        r'<td[^>]*class[^>]*>.*?(\d+)[:\s](-?\s?\d+).*?</td>.*?'         # score (home : away)
        r'<td[^>]*>.*?<a[^>]*>([^<]+)</a>.*?</td>.*?'                   # away team
        r'</tr>', re.DOTALL | re.IGNORECASE
    )
    
    for rm in row_re.finditer(html):
        date_str = rm.group(1).strip()
        home = rm.group(2).strip()
        score_h = rm.group(3).strip()
        score_a_raw = rm.group(4).strip().replace(" ", "")
        away = rm.group(5).strip()
        
        # Parse date (DD.MM.YYYY)
        parts = date_str.split(".")
        if len(parts) != 3: continue
        match_date = f"{parts[2]}-{parts[1].zfill(2)}-{parts[0].zfill(2)}"
        
        try:
            hs = int(score_h)
            ascore = int(score_a_raw) if score_a_raw.lstrip("-").isdigit() else None
        except ValueError:
            continue
        if ascore is None: continue
        
        # Clean team names
        home = re.sub(r'\s+', ' ', home).strip()
        away = re.sub(r'\s+', ' ', away).strip()
        if not home or not away: continue
        
        matches.append({
            "home_team": home,
            "away_team": away,
            "home_score": hs,
            "away_score": ascore,
            "match_date": match_date,
            "league": league_display,
        })
    
    return matches


def scrape_league(slug, display_name, seasons=3):
    """Scrape past N seasons for a league."""
    all_matches = []
    for s in range(seasons):
        season_start = CURRENT_YEAR - s
        season_end = season_start + 1
        season_str = f"{season_start}-{season_end}"
        
        # Worldfootball uses various URL patterns; try the schedule page
        urls = [
            f"{SITE_BASE}/schedule/{slug}-{season_str}-spieltag/",
            f"{SITE_BASE}/schedule/{slug}-{season_start}-spieltag/",
            f"{SITE_BASE}/competition/{slug}/",
        ]
        
        # Try adding ?season= param
        if season_start < CURRENT_YEAR:
            urls.append(f"{SITE_BASE}/competition/{slug}/?season={season_start}")
        
        html = None
        for u in urls:
            html = get_html(u)
            if html and ("standard_tabelle" in html or "Spielplan" in html):
                break
        
        if not html:
            log(f"  [{slug}] No data for season {season_str}")
            continue
        
        # First try the schedule page with multiple matchdays
        # On worldfootball, the schedule page shows one matchday at a time with pagination
        # But the competition page shows a table of all results for the season
        
        if "/competition/" in u:
            rows = parse_match_rows(html, display_name)
        else:
            rows = parse_match_rows(html, display_name)
        
        if rows:
            log(f"  [{slug}] Season {season_str}: {len(rows)} matches")
            all_matches.extend(rows)
        else:
            log(f"  [{slug}] Season {season_str}: no matches parsed (check HTML structure)")
        
        time.sleep(0.5)  # be polite
    
    return all_matches


def post_matches(matches, secret_key, hook_url=None, dry_run=False):
    """POST matches to fetch_scores.php."""
    if not matches:
        return 0
    
    if dry_run:
        log(f"  DRY RUN: would post {len(matches)} matches")
        for m in matches[:3]:
            log(f"    {m['match_date']} {m['home_team']} {m['home_score']}-{m['away_score']} {m['away_team']} [{m['league']}]")
        if len(matches) > 3:
            log(f"    ... and {len(matches)-3} more")
        return len(matches)
    
    base_url = hook_url or "https://predixa.co.tz/cron/fetch_scores.php"
    url = f"{base_url}?key={urllib.parse.quote(secret_key)}"
    
    payload = json.dumps({"matches": matches}).encode("utf-8")
    hdrs = {
        "User-Agent": USER_AGENT,
        "Content-Type": "application/json",
    }
    
    retries = 3
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, data=payload, headers=hdrs, method="POST")
            with urllib.request.urlopen(req, timeout=60) as resp:
                result = json.loads(resp.read().decode("utf-8"))
                log(f"  POST result: {result}")
                return result.get("inserted", 0)
        except Exception as e:
            log(f"  POST error (attempt {attempt+1}): {e}")
            if attempt < retries - 1:
                time.sleep(3)
    
    return 0


def main():
    argv = sys.argv[1:]
    secret_key = ""
    seasons = 3
    league_filter = None
    dry_run = False
    hook_url = None
    
    for a in argv:
        if a == "--dry-run":
            dry_run = True
        elif a.startswith("--key="):
            secret_key = a.split("=", 1)[1]
        elif a.startswith("--seasons="):
            seasons = int(a.split("=", 1)[1])
        elif a.startswith("--leagues="):
            league_filter = a.split("=", 1)[1].split(",")
        elif a.startswith("--hook="):
            hook_url = a.split("=", 1)[1]
    
    if not secret_key and not dry_run:
        print("Error: --key=SECRET is required (or use --dry-run)")
        sys.exit(1)
    
    # Determine which leagues to scrape
    leagues_to_scrape = []
    if league_filter:
        for slug in league_filter:
            slug = slug.strip()
            if slug in MAJOR_LEAGUES:
                leagues_to_scrape.append((slug, MAJOR_LEAGUES[slug]))
            else:
                log(f"Unknown league slug: {slug}")
    else:
        leagues_to_scrape = list(MAJOR_LEAGUES.items())
    
    log(f"Scraping {len(leagues_to_scrape)} leagues, {seasons} season(s) each, dry_run={dry_run}")
    
    total_posted = 0
    total_found = 0
    
    for slug, display in leagues_to_scrape:
        log(f"\n--- {display} ({slug}) ---")
        matches = scrape_league(slug, display, seasons)
        if not matches:
            continue
        total_found += len(matches)
        
        # Post in batches of 50
        batch_size = 50
        for i in range(0, len(matches), batch_size):
            batch = matches[i:i+batch_size]
            posted = post_matches(batch, secret_key, hook_url, dry_run)
            total_posted += posted
            if not dry_run:
                time.sleep(1)  # rate limit between batches
    
    log(f"\n=== Done ===")
    log(f"  Matches found: {total_found}")
    if not dry_run:
        log(f"  Inserted into match_results: {total_posted}")
    else:
        log(f"  (dry run — no writes performed)")


if __name__ == "__main__":
    main()
