# Git Commit Reference (Windows)

## First-time setup
```powershell
git config --global user.email "your-github-email@example.com"
git config --global user.name "YourGitHubUsername"
```

## Clone + overwrite file
```powershell
git clone https://github.com/YourUsername/YourRepo.git
cd YourRepo
cmd /c copy /Y "C:\path\to\local\file.ext" file.ext
```

## Stage, commit, push
```powershell
git add -A
git commit -m "Your message"
git push origin main
```

### If `git add -A` says "nothing to commit"
```powershell
git add -A --force
git commit -m "Force update full file"
git push origin main --force
```

## Git not found
- Install from https://git-scm.com/download/win
- Restart terminal after install
- Verify with: `git --version`

## Push needs a password
- Use a **Personal Access Token** (not your login password)
- Create at: GitHub.com → Settings → Developer settings → Personal access tokens → Tokens (classic)
- Scope: `repo` (full control)
- Copy the token, paste as password when prompted


# PUSHING TO GITHUB

# 1. Copy the updated scraper to the repo
Copy-Item "E:\TIM\Projects\SOCCER PREDICTION\predixa\Scrap\multi_bookie_scraper.py" "C:\Users\USER\Desktop\multi_bookie_scraper\multi_bookie_scraper.py"

# 2. Change to repo directory
Set-Location "C:\Users\USER\Desktop\multi_bookie_scraper"

# 3. Stage, commit, and push
& "C:\Program Files\Git\bin\git.exe" add multi_bookie_scraper.py
& "C:\Program Files\Git\bin\git.exe" commit -m "Fix Odds_Drops append beyond grid limit"
& "C:\Program Files\Git\bin\git.exe" push


# PUSHING TO GITHUB --- >  Version 2


# 1. Copy the updated scraper
Copy-Item "E:\TIM\Projects\SOCCER PREDICTION\predixa\Scrap\multi_bookie_scraper.py" "C:\Users\USER\Desktop\multi_bookie_scraper\multi_bookie_scraper.py"

# 2. Stage, commit, push
& "C:\Program Files\Git\bin\git.exe" -C "C:\Users\USER\Desktop\multi_bookie_scraper" add multi_bookie_scraper.py
& "C:\Program Files\Git\bin\git.exe" -C "C:\Users\USER\Desktop\multi_bookie_scraper" commit -m "Remove Odds_Drops writer, keep only consensus sheet"
& "C:\Program Files\Git\bin\git.exe" -C "C:\Users\USER\Desktop\multi_bookie_scraper" push


# LATEST: prediction_sites repo pushes (Jul 2026)

## Push 1: standings pipeline + value edge + score cooldown fix
```powershell
cd E:\xampp\htdocs\PRED
git add classes/BayesianModel.php cron/fetch_standings.php cron/scrape_standings.py cron/bayesian_predict.php migrations/005_create_league_standings.sql .github/workflows/scrape-standings.yml cron/fetch_scores.php cron/settle_picks.php cron/signal_engine.php
git commit -m "feat: league standings scraper + pipeline, market odds value edge, score cooldown fix"
git pull --rebase origin main
git push
```

## Push 2: PRO tab dashboard + track-record links disable
```powershell
cd E:\xampp\htdocs\PRED
git add admin.php dashboard.php dropping-odds.php index.php pikka.php signals.php track-record.php
git commit -m "feat: PRO tab Bayesian picks, mobile responsive, disable track-record links"
git pull --rebase origin main
git push
```