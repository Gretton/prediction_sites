CREATE TABLE IF NOT EXISTS `teams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `normalized_name` VARCHAR(255) NOT NULL,
    `aliases` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_teams_normalized` (`normalized_name`(100)),
    INDEX `idx_teams_name` (`name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
