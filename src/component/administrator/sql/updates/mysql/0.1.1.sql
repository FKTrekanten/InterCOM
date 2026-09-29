-- Earlier versions prevented delivery-mode changes while any lease existed.
-- Therefore their current mode identifies existing reservations unambiguously.
ALTER TABLE `#__intercom_drafts` ADD COLUMN `delivery_mode` varchar(8) NOT NULL DEFAULT 'live' AFTER `owner_id`;
UPDATE `#__intercom_drafts` SET `delivery_mode` = CASE WHEN (SELECT JSON_UNQUOTE(JSON_EXTRACT(params, '$.mode')) FROM `#__extensions` WHERE element='com_intercom' AND type='component') = 'live' THEN 'live' ELSE 'fake' END;
