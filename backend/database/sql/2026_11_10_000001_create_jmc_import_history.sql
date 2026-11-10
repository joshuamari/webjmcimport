-- Import history for Web JMC Import.
-- Database: webjmrdb
-- Matches database/migrations/2026_11_10_000001_create_jmc_import_history_table.php
-- The PHP migration also copies existing JSON files from storage/app/private/jmc-history.

CREATE TABLE IF NOT EXISTS `jmc_import_history` (
  `id` char(32) NOT NULL,
  `imported_at` datetime NOT NULL,
  `filename` varchar(255) NOT NULL,
  `group_name` varchar(100) NOT NULL,
  `added` int unsigned NOT NULL DEFAULT 0,
  `skipped` int unsigned NOT NULL DEFAULT 0,
  `result` varchar(32) NOT NULL DEFAULT 'Completed',
  `imported_by` varchar(100) NOT NULL DEFAULT '',
  `employee_number` int unsigned NULL,
  `projects` json NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `jmc_import_history_group_name_imported_at_index` (`group_name`, `imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
