CREATE TABLE IF NOT EXISTS `#__intercom_design` (`id` tinyint unsigned NOT NULL, `revision` int unsigned NOT NULL DEFAULT 1, `configuration` mediumtext NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `#__intercom_design` (`id`,`configuration`) VALUES (1,'{}');
UPDATE `#__intercom_drafts` SET state='draft',tested_revision=NULL,tested_fingerprint=NULL WHERE state='tested';
