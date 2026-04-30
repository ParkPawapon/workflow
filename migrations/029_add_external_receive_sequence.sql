-- Add per-year receive sequence for external circular registration.

CREATE TABLE IF NOT EXISTS `dh_sequences` (
  `seqKey` varchar(50) NOT NULL,
  `currentValue` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`seqKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @add_column_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `dh_circulars` ADD COLUMN `extReceiveSeq` int(11) unsigned DEFAULT NULL AFTER `extBookNo`',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dh_circulars'
      AND COLUMN_NAME = 'extReceiveSeq'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;

SET @receive_year := NULL;
SET @receive_seq := 0;

UPDATE `dh_circulars` AS c
INNER JOIN (
    SELECT
        ordered.`circularID`,
        @receive_seq := IF(@receive_year = ordered.`dh_year`, @receive_seq + 1, 1) AS `nextReceiveSeq`,
        @receive_year := ordered.`dh_year` AS `sequenceYear`
    FROM (
        SELECT `circularID`, `dh_year`
        FROM `dh_circulars`
        WHERE `circularType` = 'EXTERNAL'
          AND `deletedAt` IS NULL
        ORDER BY `dh_year` ASC, `createdAt` ASC, `circularID` ASC
    ) AS ordered
    CROSS JOIN (SELECT @receive_year := NULL, @receive_seq := 0) AS vars
) AS numbered ON numbered.`circularID` = c.`circularID`
SET c.`extReceiveSeq` = numbered.`nextReceiveSeq`
WHERE c.`circularType` = 'EXTERNAL'
  AND c.`extReceiveSeq` IS NULL;

INSERT INTO `dh_sequences` (`seqKey`, `currentValue`)
SELECT CONCAT('external_receive:', `dh_year`), MAX(`extReceiveSeq`)
FROM `dh_circulars`
WHERE `circularType` = 'EXTERNAL'
  AND `extReceiveSeq` IS NOT NULL
GROUP BY `dh_year`
ON DUPLICATE KEY UPDATE
  `currentValue` = GREATEST(`currentValue`, VALUES(`currentValue`));

SET @add_index_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `dh_circulars` ADD UNIQUE KEY `uq_cir_external_receive_seq` (`dh_year`, `circularType`, `extReceiveSeq`)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'dh_circulars'
      AND INDEX_NAME = 'uq_cir_external_receive_seq'
);
PREPARE add_index_stmt FROM @add_index_sql;
EXECUTE add_index_stmt;
DEALLOCATE PREPARE add_index_stmt;
