CREATE TABLE IF NOT EXISTS `league_standings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `league` VARCHAR(255) NOT NULL,
    `team` VARCHAR(255) NOT NULL,
    `position` INT NOT NULL DEFAULT 99,
    `played` INT DEFAULT 0,
    `wins` INT DEFAULT 0,
    `draws` INT DEFAULT 0,
    `losses` INT DEFAULT 0,
    `goals_for` INT DEFAULT 0,
    `goals_against` INT DEFAULT 0,
    `goal_diff` INT DEFAULT 0,
    `points` INT DEFAULT 0,
    `updated_at` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_standings_team_league_date` (`team`(100), `league`(100), `updated_at`),
    INDEX `idx_standings_league` (`league`(100)),
    INDEX `idx_standings_team` (`team`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;