ALTER TABLE `#__intercom_filters` ADD COLUMN `reconciliation_status` varchar(32) NOT NULL DEFAULT '', ADD COLUMN `checked_at` datetime DEFAULT NULL;
ALTER TABLE `#__intercom_filter_creations` ADD COLUMN `reconciliation_status` varchar(32) NOT NULL DEFAULT '', ADD COLUMN `checked_at` datetime DEFAULT NULL;
