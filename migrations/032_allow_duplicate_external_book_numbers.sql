-- Allow external source document numbers to repeat.
-- The internal receive sequence (extReceiveSeq) remains unique per year.

SET @circulars_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'dh_circulars'
);

SET @ext_book_unique_index := (
  SELECT grouped_indexes.INDEX_NAME
  FROM (
    SELECT
      INDEX_NAME,
      MIN(NON_UNIQUE) AS NON_UNIQUE,
      GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dh_circulars'
      AND INDEX_NAME <> 'PRIMARY'
    GROUP BY INDEX_NAME
  ) AS grouped_indexes
  WHERE grouped_indexes.NON_UNIQUE = 0
    AND grouped_indexes.indexed_columns = 'dh_year,extBookNo'
  LIMIT 1
);

SET @sql := IF(
  @circulars_table_exists = 0 OR @ext_book_unique_index IS NULL,
  'SELECT 1',
  CONCAT(
    'ALTER TABLE `dh_circulars` DROP INDEX `',
    REPLACE(@ext_book_unique_index, '`', '``'),
    '`'
  )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ext_book_lookup_index_exists := (
  SELECT COUNT(*)
  FROM (
    SELECT
      INDEX_NAME,
      GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dh_circulars'
      AND INDEX_NAME <> 'PRIMARY'
    GROUP BY INDEX_NAME
  ) AS grouped_indexes
  WHERE grouped_indexes.indexed_columns = 'dh_year,extBookNo'
);

SET @sql := IF(
  @circulars_table_exists = 0 OR @ext_book_lookup_index_exists > 0,
  'SELECT 1',
  'ALTER TABLE `dh_circulars` ADD KEY `idx_cir_ext_book` (`dh_year`, `extBookNo`)'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
