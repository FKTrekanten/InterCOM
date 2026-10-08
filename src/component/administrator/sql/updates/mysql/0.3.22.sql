CREATE TABLE IF NOT EXISTS `#__intercom_connection_renewals` (`provider` varchar(64) NOT NULL, `metadata` text NOT NULL, `pending` mediumtext NOT NULL, PRIMARY KEY (`provider`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `#__intercom_connection_renewals` (`provider`,`metadata`,`pending`) VALUES ('cleverreach','{}','');
