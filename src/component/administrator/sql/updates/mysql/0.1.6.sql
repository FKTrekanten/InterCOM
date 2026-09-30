INSERT INTO `#__intercom_audit` (`actor_id`, `event`, `draft_id`, `context`, `created_at`) SELECT 0, 'migration.filters_retired', 0, JSON_OBJECT('count', COUNT(*)), UTC_TIMESTAMP() FROM `#__intercom_filters` WHERE `managed` = 0 AND `draft_id` IS NULL HAVING COUNT(*) > 0;
DELETE FROM `#__intercom_filters` WHERE `managed` = 0 AND `draft_id` IS NULL;
UPDATE `#__extensions` SET `params` = JSON_REMOVE(IF(JSON_VALID(`params`), `params`, '{}'), '$.filter_ids') WHERE `type` = 'component' AND `element` = 'com_intercom';
