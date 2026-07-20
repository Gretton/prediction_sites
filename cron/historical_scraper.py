#!/usr/bin/env python3
"""
Historical match scraper: backfills match_results with past scores + leagues.
Source: OpenFootballData CSV files on GitHub (free, no API key, no blocking).

Usage:
  python cron/historical_scraper.py --key=SECRET --seasons=3
  python cron/historical_scraper.py --key=SECRET --recent
  python cron/historical_scraper.py --key=SECRET --seasons=2 --dry-run

Options:
  --key=SECRET     Key for fetch_scores.php endpoint (required)
  --seasons=N      How many past seasons to scrape (default: 3)
  --recent         Only scrape last 7 days (for daily cron)
  --leagues=NAME   Comma-separated league slugs (default: all)
  --dry-run        Print matches without posting
  --hook=URL       Optional webhook URL (default: predixa.co.tz)
"""

import sys, csv, io, json, time, re, urllib.request, urllib.parse
from datetime import datetime, timedelta

# ─── League definitions ───────────────────────────────────
# Each entry: slug -> (display_name, owner/repo/path_pattern, season_format)
# CSV format: Round,Day,Date,Team 1,FT,Team 2 (or similar)
# Downloaded from raw.githubusercontent.com

LEAGUES = {
    # England
    "eng-premier-league":   ("England - Premier League", "openfootball/eng-england/master/2020s/{season}/1-premierleague.csv"),
    "eng-championship":     ("England - Championship", "openfootball/eng-england/master/2020s/{season}/2-championship.csv"),
    "eng-league-one":       ("England - League One", "openfootball/eng-england/master/2020s/{season}/3-leagueone.csv"),
    # Spain
    "spa-primera-division": ("Spain - La Liga", "openfootball/esp-spain/master/2020s/{season}/1-laliga.csv"),
    # Italy
    "ita-serie-a":          ("Italy - Serie A", "openfootball/ita-italy/master/2020s/{season}/1-seriea.csv"),
    # Germany
    "ger-bundesliga":       ("Germany - Bundesliga", "openfootball/ger-germany/master/2020s/{season}/1-bundesliga.csv"),
    "ger-2-bundesliga":     ("Germany - 2. Bundesliga", "openfootball/ger-germany/master/2020s/{season}/2-bundesliga2.csv"),
    # France
    "fra-ligue-1":          ("France - Ligue 1", "openfootball/fra-france/master/2020s/{season}/1-ligue1.csv"),
    "fra-ligue-2":          ("France - Ligue 2", "openfootball/fra-france/master/2020s/{season}/2-ligue2.csv"),
    # Netherlands
    "ned-eredivisie":       ("Netherlands - Eredivisie", "openfootball/ned-netherlands/master/2020s/{season}/1-eredivisie.csv"),
    # Portugal
    "por-primeira-liga":    ("Portugal - Primeira Liga", "openfootball/por-portugal/master/2020s/{season}/1-primeiraliga.csv"),
    # Belgium
    "bel-pro-league":       ("Belgium - Pro League", "openfootball/bel-belgium/master/2020s/{season}/1-proleague.csv"),
    # Scotland
    "sco-premiership":      ("Scotland - Premiership", "openfootball/sco-scotland/master/2020s/{season}/1-premiership.csv"),
    # Turkey
    "tur-sueperlig":        ("Turkey - Süper Lig", "openfootball/tur-turkey/master/2020s/{season}/1-superlig.csv"),
    # Greece
    "gre-superleague":      ("Greece - Super League", "openfootball/gre-greece/master/2020s/{season}/1-superleague.csv"),
    # Switzerland
    "sui-super-league":     ("Switzerland - Super League", "openfootball/sui-switzerland/master/2020s/{season}/1-superleague.csv"),
    # Austria
    "aut-bundesliga":       ("Austria - Bundesliga", "openfootball/aut-austria/master/2020s/{season}/1-bundesliga.csv"),
    # Denmark
    "den-superliga":        ("Denmark - Superliga", "openfootball/den-denmark/master/2020s/{season}/1-superliga.csv"),
    # Sweden
    "swe-allsvenskan":      ("Sweden - Allsvenskan", "openfootball/swe-sweden/master/2020s/{season}/1-allsvenskan.csv"),
    # Norway
    "nor-eliteserien":      ("Norway - Eliteserien", "openfootball/nor-norway/master/2020s/{season}/1-eliteserien.csv"),
    # Poland
    "pol-ekstraklasa":      ("Poland - Ekstraklasa", "openfootball/pol-poland/master/2020s/{season}/1-ekstraklasa.csv"),
    # Czech Republic
    "cze-first-league":     ("Czech Republic - First League", "openfootball/cze-czech-republic/master/2020s/{season}/1-firstleague.csv"),
    # Romania
    "rou-liga-i":           ("Romania - Liga I", "openfootball/rou-romania/master/2020s/{season}/1-liga1.csv"),
    # Croatia
    "cro-hnl":              ("Croatia - HNL", "openfootball/cro-croatia/master/2020s/{season}/1-hnl.csv"),
    # Brazil
    "bra-serie-a":          ("Brazil - Série A", "openfootball/bra-brazil/master/2020s/{season}/1-seriea.csv"),
    # Argentina
    "arg-liga-profesional": ("Argentina - Liga Profesional", "openfootball/arg-argentina/master/2020s/{season}/1-ligaprofesional.csv"),
}

CURRENT_YEAR = time.localtime().tm_year
UA = "Mozilla/5.0"


def log(msg):
    print(f"[{datetime.now().strftime('%H:%M:%S')}] {msg}")


def fetch_csv(url, retries=3):
    """Download a CSV file from a URL."""
    hdrs = {"User-Agent": UA}
    for a in range(retries):
        try:
            req = urllib.request.Request(url, headers=hdrs)
            with urllib.request.urlopen(req, timeout=20) as resp:
                return resp.read().decode("utf-8", errors="replace")
        except Exception as e:
            log(f"  fetch error: {e}, retry {a+1}")
            if a < retries - 1: time.sleep(1.5 ** a)
    return None


def parse_csv_matches(csv_text, league_display, season_year):
    """Parse OpenFootballData CSV into match dicts."""
    matches = []
    reader = csv.DictReader(io.StringIO(csv_text))
    
    for row in reader:
        # Try common column name variants
        date = row.get("Date") or row.get("date") or row.get("Day") or ""
        home = row.get("Team 1") or row.get("Home") or row.get("home_team") or ""
        away = row.get("Team 2") or row.get("Away") or row.get("away_team") or ""
        score = row.get("FT") or row.get("Score") or row.get("score") or row.get("Result") or ""
        
        if not home or not away or not score:
            continue
        
        # Normalize date: DD.MM.YYYY or YYYY-MM-DD or DD/MM/YYYY
        md = ""
        if "-" in date:
            parts = date.split("-")
            if len(parts) == 3:
                if len(parts[0]) == 4: md = date  # YYYY-MM-DD
                else: md = f"{parts[2]}-{parts[1].zfill(2)}-{parts[0].zfill(2)}"
        elif "." in date:
            parts = date.split(".")
            if len(parts) == 3:
                if len(parts[0]) == 4: md = f"{parts[0]}-{parts[1].zfill(2)}-{parts[2].zfill(2)}"
                else: md = f"{parts[2]}-{parts[1].zfill(2)}-{parts[0].zfill(2)}"
        elif "/" in date:
            parts = date.split("/")
            if len(parts) == 3:
                if len(parts[0]) == 4: md = f"{parts[0]}-{parts[1].zfill(2)}-{parts[2].zfill(2)}"
                elif len(parts[2]) == 4: md = f"{parts[2]}-{parts[1].zfill(2)}-{parts[0].zfill(2)}"
        
        # If still no date, infer from season
        if not md:
            md = f"{season_year}-06-15"
        
        # Parse score: "2-1" or "2:1" or "2 – 1"
        score = score.replace("\u2013", "-").replace("\u2014", "-").replace("\u2015", "-").replace("\u00a0", "")
        if ":" in score:
            score = score.replace(":", "-")
        score = score.replace(" ", "")
        
        sm = re.search(r'(\d+)\s*[-–]\s*(\d+)', score)
        if not sm:
            continue
        
        try:
            hs = int(sm.group(1))
            as_ = int(sm.group(2))
        except (ValueError, AttributeError):
            continue
        
        home = home.strip()
        away = away.strip()
        if not home or not away:
            continue
        
        matches.append({
            "home_team": home,
            "away_team": away,
            "home_score": hs,
            "away_score": as_,
            "match_date": md,
            "league": league_display,
        })
    
    return matches


def scrape_league(slug, display_name, csv_pattern, seasons=3):
    """Download CSV files from GitHub for each season and parse."""
    all_matches = []
    
    for s in range(seasons):
        season_start = CURRENT_YEAR - s
        season_end = season_start + 1
        season_str = f"{season_start}-{season_end}"
        
        # Build GitHub raw URL
        filepath = csv_pattern.replace("{season}", season_str)
        url = f"https://raw.githubusercontent.com/{filepath}"
        
        log(f"  fetching {slug} {season_str}...")
        csv_text = fetch_csv(url)
        if not csv_text:
            # Try alternate season format (single year)
            filepath2 = csv_pattern.replace("{season}", str(season_start))
            url2 = f"https://raw.githubusercontent.com/{filepath2}"
            log(f"  trying {slug} {season_start}...")
            csv_text = fetch_csv(url2)
        
        if not csv_text:
            log(f"  [{slug}] No CSV for {season_str}")
            continue
        
        rows = parse_csv_matches(csv_text, display_name, season_start)
        if rows:
            log(f"  [{slug}] {season_str}: {len(rows)} matches")
            all_matches.extend(rows)
        else:
            log(f"  [{slug}] {season_str}: CSV found but no matches parsed")
        
        time.sleep(0.3)
    
    return all_matches


def post_matches(matches, secret_key, hook_url=None, dry_run=False):
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
    hdrs = {"User-Agent": UA, "Content-Type": "application/json"}
    
    for a in range(3):
        try:
            req = urllib.request.Request(url, data=payload, headers=hdrs, method="POST")
            with urllib.request.urlopen(req, timeout=60) as resp:
                result = json.loads(resp.read().decode("utf-8"))
                log(f"  POST result: {result}")
                return result.get("inserted", 0)
        except Exception as e:
            log(f"  POST error (attempt {a+1}): {e}")
            if a < 2: time.sleep(3)
    return 0


def main():
    argv = sys.argv[1:]
    secret_key = ""
    seasons = 3
    league_filter = None
    dry_run = False
    hook_url = None
    recent_days = 0
    
    i = 0
    while i < len(argv):
        a = argv[i]
        if a == "--dry-run": dry_run = True
        elif a == "--recent": recent_days = 7
        elif a.startswith("--key="): secret_key = a.split("=", 1)[1]
        elif a.startswith("--seasons="): seasons = int(a.split("=", 1)[1])
        elif a.startswith("--leagues="): league_filter = a.split("=", 1)[1].split(",")
        elif a.startswith("--hook="): hook_url = a.split("=", 1)[1]
        i += 1
    
    if not secret_key and not dry_run:
        print("Error: --key=SECRET is required (or use --dry-run)")
        sys.exit(1)
    
    leagues_scrape = []
    if league_filter:
        for slug in league_filter:
            slug = slug.strip()
            if slug in LEAGUES:
                leagues_scrape.append((slug, LEAGUES[slug][0], LEAGUES[slug][1]))
            else:
                log(f"Unknown league slug: {slug}")
    else:
        leagues_scrape = [(k, v[0], v[1]) for k, v in LEAGUES.items()]
    
    mode = "RECENT (7 days)" if recent_days else f"{seasons} season(s)"
    log(f"Scraping {len(leagues_scrape)} leagues, {mode}, dry_run={dry_run}")
    
    recent_cutoff = None
    if recent_days:
        recent_cutoff = (datetime.now() - timedelta(days=recent_days)).strftime("%Y-%m-%d")
        log(f"  Only matches after {recent_cutoff}")
    
    total_posted = 0
    total_found = 0
    
    for slug, display, csv_pattern in leagues_scrape:
        log(f"\n--- {display} ({slug}) ---")
        matches = scrape_league(slug, display, csv_pattern, seasons)
        if not matches:
            continue
        
        if recent_cutoff:
            matches = [m for m in matches if m.get("match_date", "") >= recent_cutoff]
            log(f"  After date filter: {len(matches)} matches remain")
        
        if not matches:
            continue
        total_found += len(matches)
        
        for i in range(0, len(matches), 50):
            batch = matches[i:i+50]
            posted = post_matches(batch, secret_key, hook_url, dry_run)
            total_posted += posted
            if not dry_run:
                time.sleep(0.5)
    
    log(f"\n=== Done ===")
    log(f"  Matches found: {total_found}")
    if not dry_run:
        log(f"  Inserted into match_results: {total_posted}")
    else:
        log(f"  (dry run — no writes performed)")


if __name__ == "__main__":
    main()
