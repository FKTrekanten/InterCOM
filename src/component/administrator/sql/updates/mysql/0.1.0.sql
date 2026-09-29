-- Fresh installations already have this column and register schema 0.1.0.
-- The pre-release upgrade fixture exercises Joomla's actual SQL migration runner.
ALTER TABLE `#__intercom_drafts` ADD COLUMN `tested_revision` int unsigned DEFAULT NULL AFTER `revision`;
