CREATE TABLE IF NOT EXISTS `dh_memo_inbox_archives` (
  `archiveID` bigint(20) NOT NULL AUTO_INCREMENT,
  `memoID` bigint(20) NOT NULL,
  `pID` varchar(13) NOT NULL,
  `isArchived` tinyint(1) NOT NULL DEFAULT 1,
  `archivedAt` datetime DEFAULT NULL,
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`archiveID`),
  UNIQUE KEY `uq_memo_inbox_archive` (`memoID`, `pID`),
  KEY `idx_memo_inbox_archive_user` (`pID`, `isArchived`, `archivedAt`),
  KEY `idx_memo_inbox_archive_memo` (`memoID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
