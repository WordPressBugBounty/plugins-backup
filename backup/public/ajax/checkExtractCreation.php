<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');

$runningActions = array();

if (backupGuardIsAjax()) {
	$iteration = 10; //in sec
	while ($iteration !== 0) {
		sleep(1);
		$iteration--;
		$created = SGConfig::get('SG_RUNNING_ACTION', true);

		if ($created) {
			$runningActions = SGBackup::getRunningActions();
			// when there are multiple uncompleted actions
			if ($runningActions && count($runningActions) == 1 && $runningActions[0]['progress'] == 0) {
				$actionId = $runningActions[0]['id'];
				die(json_encode(array(
					'status' => 0,
					'external_enabled' => SGExternalRestore::isEnabled() ? 1 : 0,
					'external_url' => SGExternalRestore::getInstance()->getDestinationFileUrl()
				)));
			}
		}
	}
	if (!empty($runningActions)) {
		SGBackup::cleanRunningActions($runningActions);
		die(json_encode(array(
			'status' => 'cleaned'
		)));
	}

	die(json_encode(["status" => 1]));
}
