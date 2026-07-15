# PREDIXA Operations Guide

## Daily Schedule (EAT = UTC+3)

| Time | Script | What it does |
|------|--------|-------------|
| 5:55 AM | `reset_sheets.php` | Wipes both Google Sheets for a fresh day |
| 6:30 AM | `multi_bookie_scraper.py` (1st run) | Opens all 10 bookies, writes opening odds |
| 9:00 AM | `cron_analysis.php` | Reads Odds_Drops → runs analysis → writes `web_picks` |
| 10:00 AM | `seed_pikka.php` | Seeds Pikka feed from consensus + web_picks |
| 12:00 PM | `tipster_bot.php` | Posts falling-odds signals as Pikka tips |
| 3:00 PM | `tipster_bot.php` | Same |
| 4:00 PM | `seed_pikka.php` | Refresh Pikka feed |
| 6:00 PM | `tipster_bot.php` | Same |
| 9:00 PM | `tipster_bot.php` | Same |

All cron scripts use: `?key=pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580` for auth (defined in `config.php` as `CRON_SECRET`).

## Two Google Sheets

### 1. New Consensus Sheet (`1o-7cuDK...`)
- **Tabs cleared at 5:55AM**: ALL tabs including `Odds_Movements`
- **Written by**: `multi_bookie_scraper.py` (all 10 bookies)
- **Read by**: `MultiBookieSheetsAPI.php` for VERIFIED badge

### 2. Old Analysis Sheet (`1MTGkNce...`)
- **Data tabs cleared at 5:55AM**: `Sheet1`, `Odds_Drops`, `Opening_Odds` (standings preserved)
- **Written by**: `multi_bookie_scraper.py` (Sportybet only via `write_odds_drops_for_analyzer()`)
- **Read by**: `GoogleSheetsAPI.php` → `OddsAnalyzer.php` → `web_picks`

## Scrapers (multi_bookie_scraper.py)

**Location**: `E:\TIM\Projects\SOCCER PREDICTION\predixa\Scrap\multi_bookie_scraper.py`
**GitHub**: `github.com/Gretton/multi_bookie_scraper`
**Requires**: Python 3.10+, Playwright (`pip install playwright && playwright install chromium`)
**Auth**: `google_credentials.json` (service account for Sheets API)

### 10 Bookies

| Bookie | Selectors | Status |
|--------|-----------|--------|
| Sportybet | `.match-league-wrap`, `.home-team`, `.away-team`, `.m-outcome-odds` | ✅ Working |
| Meridianbet | Rows `.t`, selector-based odd spans | ✅ Working |
| Streetbet | Sportybet-like with `x-` prefix | ✅ Working |
| SportPesa | select-based odd spans | ✅ Working |
| MSport | Astro SPA, custom nav URL | ✅ Working (50 matches) |
| Bangbet | Vue SSR, `.match-base-box`, `.oddF2.oddsW` | ✅ Working |
| VunjabeiBet | Vue SSR | ✅ Working (8 matches) |
| Chezacash | Regex on body text (btobet iframe) | ✅ Working (24 matches) |
| Betxchange | Aardvark SPA (API pending) | ❌ No data |
| Tictacbets | Aardvark SPA (API pending) | ❌ No data |

### How the scraper works
1. Playwright opens each bookie's football page
2. Extracts match data (teams, odds, league, time) via DOM selectors
3. Writes to consensus sheet (all bookies) via `write_to_sheets()`
4. Writes to analysis sheet (Sportybet only) via `write_odds_drops_for_analyzer()`

### Opening Window (6:00–7:30 AM)
- `write_odds_drops_for_analyzer()` uses self-baseline: `Before = Now = same_odds`
- Ensures analysis engine sees ALL Sportybet matches with valid odds (not just movements)
- Re-runnable any number of times within window

### Outside Opening Window (7:30 AM onward)
- Writes movement data (delta > 0.10) for existing matches
- Self-baseline for new matches (never silent-drops any match)

### Stale data prevention
- `write_to_sheets()` clears ALL tabs first, rewrites only successful bookies
- Failed scrapers never leave yesterday's data

## Badge System

| Badge | When it shows | Logic |
|-------|--------------|-------|
| **VERIFIED** | Consensus ≥50% | Green (≥75%), Amber (50-74%), hidden (<50%) |
| **BANKER** | EV ≥ 5% | Cyan badge showing EV% (e.g., "BANKER +8%") |
| **NOISY** | Low confidence pick | Suppresses all other badges |

- EV formula: `stripMarginThreeWay()` → Shin true prob → `calculateEV(trueProb, odds)`
- DC picks (1X/12/X2): sums relevant Shin probabilities for real EV
- Non-1X2/non-DC picks: never get BANKER badge

## Pikka Platform

### Premium Users
- `ALTER TABLE web_users ADD COLUMN is_premium TINYINT(1) DEFAULT 0`
- Gold crown icon on their name in feed + profile
- Toggle at `pikka.php?action=admin`

### Boosted Picks
- `ALTER TABLE tipster_picks ADD COLUMN is_boosted TINYINT(1) DEFAULT 0, ADD COLUMN boosted_until DATETIME DEFAULT NULL`
- Gold border + "🌟 Boosted" badge on card
- Sorted first in feed (`ORDER BY is_boosted DESC`)
- Toggle at `pikka.php?action=admin`

### Daily Free Limit
- Non-logged-in users: 5 picks/day via `localStorage` key `pikka_daily`
- Resets daily (midnight)
- Banner after pick #5: "Create Free Account" / "Sign in"
- Limit enforced by `_pikkaEnforceLimit()` on DOMContentLoaded + infinite scroll

### Popular Matches
- Sidebar widget showing matches with active (pending-only) picks
- Match disappears when ANY pick is marked won/lost
- Mark result via `pikka.php?action=mark_result` (POST: pick_id + status)

## Key Files

| File | Purpose |
|------|---------|
| `config.php` | DB creds, `CRON_SECRET = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580'` (line 94) |
| `cron/reset_sheets.php` | 5:55AM sheet wipe (both workbooks) |
| `cron/seed_pikka.php` | Seed Pikka feed from scraper JSON + web_picks |
| `cron/tipster_bot.php` | Post falling-odds signals as Pikka tips |
| `cron_analysis.php` | Main analysis engine (9AM, weekdays) |
| `classes/GoogleSheetsAPI.php` | Reads old analysis sheet |
| `classes/MultiBookieSheetsAPI.php` | Reads new consensus sheet |
| `classes/OddsAnalyzer.php` | Line 119: skips matches with empty `Odds_1_Before` |
| `classes/TeamForm.php` | Auto-creates `match_results` table |
| `includes/value_calculator.php` | Shin margin, EV calc |
| `includes/signals_engine.php` | `analyzeMatch()`, `getMatchVerifiedAll()` |
| `dashboard.php` | Main UI: VERIFIED, BANKER, NOISY badges |
| `signals.php` | Signals page with badge rendering |
| `odds-signals.php` | Odds signals with badge rendering |
| `pikka.php` | Community tipping feed |

## Important Gotchas

- **Always push `multi_bookie_scraper.py` via git CLI, NOT web upload** — web upload truncates the 50KB file
- **DO NOT manually scrape to populate sheets during testing** — changes affect real morning analysis
- **GitHub Actions timing is unreliable** — scheduled 1AM frequently drifts
- **Namecheap cron is reliable** — use for time-sensitive jobs (5:55AM wipe)
- **Analysis engine line 119** requires valid `Odds_1_Before` and `Odds_2_Before` — scraper now guarantees this via self-baseline
- **PM2/Manual start on VPS**: `playwright install chromium` required before first run
