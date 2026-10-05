ALTER TABLE `#__intercom_tags` ADD COLUMN `labels` mediumtext NULL AFTER `tag`;
UPDATE `#__intercom_drafts` SET state='draft',tested_revision=NULL,tested_fingerprint=NULL WHERE state='tested';
