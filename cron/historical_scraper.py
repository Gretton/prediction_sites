#!/usr/bin/env python3
"""
Historical match scraper: backfills match_results with past scores + leagues.
Source: OpenFootballData Football.TXT files on GitHub (free, no API key).

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
  --hook=URL       Optional webhook URL
"""

import sys, json, time, re, urllib.request, urllib.parse
from datetime import datetime, timedelta

CURRENT_YEAR = time.localtime().tm_year

# ─── League definitions ───────────────────────────────────
# Format: slug -> (display_name, repo, file_path_pattern)
# {season} = "2024-25" for winter leagues, "2025" for summer leagues
# {file} = filename inside the season dir

LEAGUES = {
    # --- Standalone repos (season subdirectories) ---
    "eng-premier-league": (
        "England - Premier League",
        "openfootball/england",
        "{season}/1-premierleague.txt",
    ),
    "eng-championship": (
        "England - Championship",
        "openfootball/england",
        "{season}/2-championship.txt",
    ),
    "eng-league-one": (
        "England - League One",
        "openfootball/england",
        "{season}/3-league1.txt",
    ),
    "ger-bundesliga": (
        "Germany - Bundesliga",
        "openfootball/deutschland",
        "{season}/1-bundesliga.txt",
    ),
    "ger-2-bundesliga": (
        "Germany - 2. Bundesliga",
        "openfootball/deutschland",
        "{season}/2-bundesliga2.txt",
    ),
    "ita-serie-a": (
        "Italy - Serie A",
        "openfootball/italy",
        "{season}/1-seriea.txt",
    ),
    "ita-serie-b": (
        "Italy - Serie B",
        "openfootball/italy",
        "{season}/2-serieb.txt",
    ),
    "spa-primera-division": (
        "Spain - La Liga",
        "openfootball/espana",
        "{season}/1-liga.txt",
    ),
    "spa-segunda-division": (
        "Spain - La Liga 2",
        "openfootball/espana",
        "{season}/2-liga2.txt",
    ),
    "bel-pro-league": (
        "Belgium - Pro League",
        "openfootball/belgium",
        "{season}/be1.txt",
    ),
    "aut-bundesliga": (
        "Austria - Bundesliga",
        "openfootball/austria",
        "{season}/1-bundesliga.txt",
    ),
    # --- europe repo (flat files in country dirs) ---
    "fra-ligue-1": (
        "France - Ligue 1",
        "openfootball/europe",
        "france/{season}_fr1.txt",
    ),
    "fra-ligue-2": (
        "France - Ligue 2",
        "openfootball/europe",
        "france/{season}_fr2.txt",
    ),
    "ned-eredivisie": (
        "Netherlands - Eredivisie",
        "openfootball/europe",
        "netherlands/{season}_nl1.txt",
    ),
    "por-primeira-liga": (
        "Portugal - Primeira Liga",
        "openfootball/europe",
        "portugal/{season}_pt1.txt",
    ),
    "sco-premiership": (
        "Scotland - Premiership",
        "openfootball/europe",
        "scotland/{season}_sco1.txt",
    ),
    "tur-sueperlig": (
        "Turkey - Süper Lig",
        "openfootball/europe",
        "turkey/{season}_tr1.txt",
    ),
    "gre-superleague": (
        "Greece - Super League",
        "openfootball/europe",
        "greece/{season}_gr1.txt",
    ),
    "den-superliga": (
        "Denmark - Superliga",
        "openfootball/europe",
        "denmark/{season}_dk1.txt",
    ),
    "nor-eliteserien": (
        "Norway - Eliteserien",
        "openfootball/europe",
        "norway/{season}_no1.txt",
    ),
    "swe-allsvenskan": (
        "Sweden - Allsvenskan",
        "openfootball/europe",
        "sweden/{season}_se1.txt",
    ),
    "pol-ekstraklasa": (
        "Poland - Ekstraklasa",
        "openfootball/europe",
        "poland/{season}_pl1.txt",
    ),
    "cze-first-league": (
        "Czech Republic - First League",
        "openfootball/europe",
        "czech-republic/{season}_cz1.txt",
    ),
    "rou-liga-i": (
        "Romania - Liga I",
        "openfootball/europe",
        "romania/{season}_ro1.txt",
    ),
    "cro-hnl": (
        "Croatia - HNL",
        "openfootball/europe",
        "croatia/{season}_hr1.txt",
    ),
    "ser-superliga": (
        "Serbia - Super Liga",
        "openfootball/europe",
        "serbia/{season}_rs1.txt",
    ),
    "sui-super-league": (
        "Switzerland - Super League",
        "openfootball/europe",
        "switzerland/{season}_ch1.txt",
    ),
    "hun-nb-i": (
        "Hungary - NB I",
        "openfootball/europe",
        "hungary/{season}_hu1.txt",
    ),
    "bul-first-league": (
        "Bulgaria - First League",
        "openfootball/europe",
        "bulgaria/{season}_bg1.txt",
    ),
    "ukr-premyer-liga": (
        "Ukraine - Premier League",
        "openfootball/europe",
        "ukraine/{season}_ua1.txt",
    ),
    "rus-premier-liga": (
        "Russia - Premier Liga",
        "openfootball/europe",
        "russia/{season}_ru1.txt",
    ),
    # --- south-america repo ---
    "bra-serie-a": (
        "Brazil - Série A",
        "openfootball/south-america",
        "brazil/{season}_br1.txt",
    ),
    "arg-liga-profesional": (
        "Argentina - Liga Profesional",
        "openfootball/south-america",
        "argentina/{season}_ar1.txt",
    ),
}

UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
MONTHS = {
    "jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
    "jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12,
}


def log(msg):
    print(f"[{datetime.now().strftime('%H:%M:%S')}] {msg}")


def fetch_txt(url, retries=3):
    hdrs = {"User-Agent": UA, "Accept": "text/plain"}
    for a in range(retries):
        try:
            req = urllib.request.Request(url, headers=hdrs)
            with urllib.request.urlopen(req, timeout=20) as r:
                return r.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            if e.code == 404:
                return None  # file not found
            log(f"  HTTP {e.code}, retry {a+1}")
            if a < retries - 1: time.sleep(1.5 ** a)
        except Exception as e:
            log(f"  fetch error: {e}, retry {a+1}")
            if a < retries - 1: time.sleep(1.5 ** a)
    return None


def parse_football_txt(text, league_display, season_year):
    """Parse Football.TXT format into match dicts.

    Handles both formats:
      New (2024+):  Team v Team 1-0 (0-0)     with dates "Fri Aug 16 2024"
      Old (pre-2024): Team 0-3 (0-2) Team      with dates "Fri Aug 11" (year in header)
    """
    matches = []
    current_date = None
    # Year extracted from header comment, used for date lines without year
    header_year_start = None
    header_year_end = None
    # Track which year we're in. The date range in the header tells us when
    # the season splits across years. Dates before the split use header_year_start,
    # dates after use header_year_end.
    season_split_day = None
    season_split_mon = None

    months_re = re.compile(
        r"(mon|tue|wed|thu|fri|sat|sun)\s+(\w+)\s+(\d+)\s*(\d{4})?", re.IGNORECASE
    )
    # New format: "Team v Team 1-0 (0-0)"
    match_new = re.compile(
        r"^\s*(?:\d+:\d+\s+)?(.+?)\s+v\s+(.+?)\s+(\d+)[-–](\d+)(?:\s+\(.*?\))?\s*$",
        re.IGNORECASE,
    )
    # Old format: "Team 0-3 (0-2) Team" or "Team 0-0 Team" (score between teams, no v)
    match_old = re.compile(
        r"^\s*(?:\d+:\d+\s+)?(.+?)\s+(\d+)[-–](\d+)(?:\s+\(.*?\))?\s+(.+?)\s*$",
        re.IGNORECASE,
    )
    # Header date range: "# Date Fri Aug 11 2023 - Sun May 19 2024"
    # Groups: month_word, day, year_start, month_word2, day2, year_end
    header_re = re.compile(
        r"#\s+Date\s+\w+\s+(\w+)\s+(\d+)\s+(\d{4})\s*-\s*\w+\s+(\w+)\s+(\d+)\s+(\d{4})",
        re.IGNORECASE,
    )

    for line in text.split("\n"):
        line = line.rstrip()

        if not line:
            continue

        # Parse header date range for year context and split point
        if line.lstrip().startswith("# Date"):
            hd = header_re.search(line)
            if hd:
                start_mon = hd.group(1).lower()[:3]
                header_year_start = int(hd.group(3))
                header_year_end = int(hd.group(6))
                split_mon = MONTHS.get(start_mon)
                if split_mon:
                    season_split_day = int(hd.group(2))
                    season_split_mon = split_mon
            continue

        if line.lstrip().startswith("#"):
            continue
        if line.lstrip().startswith("\u25aa"):
            continue
        if line.lstrip().startswith("▪"):
            continue

        # Check for date line
        dm = months_re.search(line)
        if dm:
            month_name = dm.group(2).lower()[:3]
            month_num = MONTHS.get(month_name)
            if not month_num:
                continue
            day = int(dm.group(3))
            year_str = dm.group(4)

            if year_str:
                # Full date: "Fri Aug 16 2024"
                year = int(year_str)
                if year <= 99:
                    year += 2000
                current_date = f"{year:04d}-{month_num:02d}-{day:02d}"
                # Track the split point (first date with year tells us the boundary)
                if header_year_start and not season_split_day:
                    season_split_day = day
                    season_split_mon = month_num
            else:
                # Date without year: "Fri Aug 11" — use header year range
                # Determine which year: before the split date = header_year_start,
                # after = header_year_end
                if header_year_start is not None and header_year_end is not None and season_split_mon:
                    if month_num < season_split_mon or (month_num == season_split_mon and day < season_split_day):
                        year = header_year_end
                    else:
                        year = header_year_start
                elif header_year_start is not None:
                    year = header_year_start
                else:
                    continue
                current_date = f"{year:04d}-{month_num:02d}-{day:02d}"
            continue

        # Try new format first (with v separator)
        mm = match_new.search(line)
        if mm and current_date:
            home = mm.group(1).strip()
            away = mm.group(2).strip()
            try:
                hs = int(mm.group(3))
                as_ = int(mm.group(4))
            except ValueError:
                continue
            if home and away:
                matches.append({
                    "home_team": home, "away_team": away,
                    "home_score": hs, "away_score": as_,
                    "match_date": current_date, "league": league_display,
                })
            continue

        # Try old format (score between teams, no v)
        mm = match_old.search(line)
        if mm and current_date:
            home = mm.group(1).strip()
            try:
                hs = int(mm.group(2))
                as_ = int(mm.group(3))
            except ValueError:
                continue
            away = mm.group(4).strip()
            if home and away:
                matches.append({
                    "home_team": home, "away_team": away,
                    "home_score": hs, "away_score": as_,
                    "match_date": current_date, "league": league_display,
                })

    return matches


def season_dirs(repo):
    """Check if repo uses season subdirectories or flat files."""
    flat_repos = {"openfootball/europe", "openfootball/south-america"}
    return repo not in flat_repos


WINTER_LEAGUES = {
    "eng-premier-league", "eng-championship", "eng-league-one",
    "ger-bundesliga", "ger-2-bundesliga",
    "ita-serie-a", "ita-serie-b",
    "spa-primera-division", "spa-segunda-division",
    "bel-pro-league", "aut-bundesliga",
    "fra-ligue-1", "fra-ligue-2",
    "ned-eredivisie", "por-primeira-liga",
    "sco-premiership", "tur-sueperlig", "gre-superleague",
    "den-superliga", "sui-super-league",
    "pol-ekstraklasa", "cze-first-league",
    "rou-liga-i", "cro-hnl", "ser-superliga",
    "hun-nb-i", "bul-first-league",
    "ukr-premyer-liga", "rus-premier-liga",
}


def scrape_league(slug, display_name, repo, file_pattern, seasons=3):
    """Download Football.TXT files and parse."""
    all_matches = []
    has_dirs = season_dirs(repo)
    summer_leagues = {"swe-allsvenskan", "nor-eliteserien", "bra-serie-a",
                      "arg-liga-profesional"}

    # Winter leagues (Aug-May) need offset=1 because the latest completed
    # season (2025-26) may not be published yet as of mid-2026.
    season_offset = 1 if slug in WINTER_LEAGUES else 0

    for s in range(seasons):
        season_start = CURRENT_YEAR - s - season_offset

        if slug in summer_leagues:
            # Summer leagues use single-year: "2025"
            season_str = str(season_start)
        else:
            season_end = season_start + 1
            season_str = f"{season_start}-{season_end % 100:02d}"

        filepath = file_pattern.replace("{season}", season_str)
        url = f"https://raw.githubusercontent.com/{repo}/master/{filepath}"

        log(f"  fetching {slug} {season_str}...")
        text = fetch_txt(url)

        if not text and not has_dirs:
            # Flat files sometimes have different name patterns
            alt_season = str(season_start)
            filepath2 = file_pattern.replace("{season}", alt_season)
            url2 = f"https://raw.githubusercontent.com/{repo}/master/{filepath2}"
            log(f"  trying {slug} {alt_season}...")
            text = fetch_txt(url2)

        if not text:
            # Try to readme to see available files
            log(f"  [{slug}] No data for {season_str}")
            continue

        rows = parse_football_txt(text, display_name, season_start)
        if rows:
            log(f"  [{slug}] {season_str}: {len(rows)} matches")
            all_matches.extend(rows)
        else:
            log(f"  [{slug}] {season_str}: file found but no matches parsed")

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
            req = urllib.request.Request(url, data=payload, headers=hdrs,
                                          method="POST")
            with urllib.request.urlopen(req, timeout=60) as r:
                result = json.loads(r.read().decode("utf-8"))
                log(f"  POST result: {result}")
                return result.get("inserted", 0)
        except Exception as e:
            log(f"  POST error (attempt {a+1}): {e}")
            if a < 2:
                time.sleep(3)
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
        elif a.startswith("--leagues="):
            league_filter = a.split("=", 1)[1].split(",")
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
                d, r, f = LEAGUES[slug]
                leagues_scrape.append((slug, d, r, f))
            else:
                log(f"Unknown league slug: {slug}")
    else:
        leagues_scrape = [(k, v[0], v[1], v[2]) for k, v in LEAGUES.items()]

    mode = "RECENT (7 days)" if recent_days else f"{seasons} season(s)"
    log(f"Scraping {len(leagues_scrape)} leagues, {mode}, dry_run={dry_run}")

    recent_cutoff = None
    if recent_days:
        recent_cutoff = (datetime.now() - timedelta(days=recent_days)).strftime(
            "%Y-%m-%d"
        )
        log(f"  Only matches after {recent_cutoff}")

    total_posted = 0
    total_found = 0

    for slug, display, repo, file_pattern in leagues_scrape:
        log(f"\n--- {display} ({slug}) ---")
        matches = scrape_league(slug, display, repo, file_pattern, seasons)
        if not matches:
            continue

        if recent_cutoff:
            matches = [
                m for m in matches if m.get("match_date", "") >= recent_cutoff
            ]
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
