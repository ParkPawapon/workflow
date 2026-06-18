-- Persist the selected memo sender faction separately from the creator profile.

SET @memo_sender_fid_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'dh_memos'
    AND COLUMN_NAME = 'senderFID'
);

SET @sql := IF(
  @memo_sender_fid_exists > 0,
  'SELECT 1',
  'ALTER TABLE `dh_memos` ADD COLUMN `senderFID` int(3) DEFAULT NULL AFTER `writeDate`'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @memo_sender_fid_idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'dh_memos'
    AND INDEX_NAME = 'idx_memo_senderFID'
);

SET @sql := IF(
  @memo_sender_fid_idx_exists > 0,
  'SELECT 1',
  'ALTER TABLE `dh_memos` ADD KEY `idx_memo_senderFID` (`senderFID`)'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @faction_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'faction'
);

SET @memo_sender_fid_fk_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND CONSTRAINT_NAME = 'fk_memo_sender_faction'
);

SET @sql := IF(
  @faction_table_exists = 0 OR @memo_sender_fid_fk_exists > 0,
  'SELECT 1',
  'ALTER TABLE `dh_memos` ADD CONSTRAINT `fk_memo_sender_faction` FOREIGN KEY (`senderFID`) REFERENCES `faction` (`fID`) ON DELETE SET NULL ON UPDATE CASCADE'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
