<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');

if (backupGuardIsAjax()) {

	$timeout = 30; //in sec
	while ($timeout != 0) {

		sleep(1);
		$timeout--;
		$created = SGConfig::get('SG_RUNNING_ACTION', true);
		if ($created) die('{"status":1}');

	}

	die('{"status":0}');

}
