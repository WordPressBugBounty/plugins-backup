<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');

if (backupGuardIsAjax() && count($_POST)) {

	(new SGBackup)->clearCache();
    @session_write_close();

    $actionId = isset($_POST['actionId']) ? (int)$_POST['actionId'] : null;
	if (!$actionId)  die('0');

	$currentAction = SGBackup::getAction($actionId);

	if (!$currentAction) die('0');

	$status = isset($currentAction['status']) ? (int) $currentAction['status'] : null;
	if (!$status) die('0');

	$types = array(
		SG_ACTION_STATUS_TREE,
		SG_ACTION_STATUS_IN_PROGRESS_FILES,
		SG_ACTION_STATUS_IN_PROGRESS_DB
	);

	if (in_array($status, $types)) die(json_encode($currentAction));

    die('0');
}
