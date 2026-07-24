-- ============================================================
-- DATA CLEANUP SQL — Run on live after uploading fixed PHP files
-- ============================================================

-- 1. Reset wrongly settled double-chance picks (C1 fix)
--    These were settled as draw-only/away-only due to str_contains order bug.
--    Reset to 'pending' so next settlePredictions run fixes them.
UPDATE bayesian_predictions 
SET result = 'pending', settled_at = NULL 
WHERE result = 'incorrect' 
  AND (recommended_pick LIKE '%1X:%' OR recommended_pick LIKE '%X2:%' OR recommended_pick LIKE '%12:%');

-- 2. Reset Under 1.5 picks that were always marked correct (C2 fix)
--    They were never actually checked, so some may be wrong.
UPDATE bayesian_predictions 
SET result = 'pending', settled_at = NULL 
WHERE result = 'correct' 
  AND recommended_pick LIKE '%Under 1.5%';

-- 3. Delete corrupted signal_engine rows (C3 fix)
--    match_name contained 'rollover'/'corners' instead of actual match names.
DELETE FROM admin_featured_picks 
WHERE match_name IN ('rollover', 'corners', 'over', 'gg', 'over_15');

-- 4. Verify after running
SELECT result, COUNT(*) as cnt FROM bayesian_predictions GROUP BY result;
SELECT COUNT(*) as corrupted_remaining FROM admin_featured_picks WHERE match_name IN ('rollover', 'corners', 'over', 'gg', 'over_15');
