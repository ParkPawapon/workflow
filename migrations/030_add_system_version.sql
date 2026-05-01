-- Add DB Sarabun display version to the system settings row.

SET @dh_version_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'thesystem'
    AND COLUMN_NAME = 'dh_version'
);

SET @sql := IF(
  @dh_version_exists > 0,
  'SELECT 1',
  'ALTER TABLE `thesystem` ADD COLUMN `dh_version` varchar(12) NOT NULL DEFAULT ''1.0.0'' AFTER `dh_status`'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `thesystem`
SET `dh_version` = '1.0.0'
WHERE `dh_version` = '';
