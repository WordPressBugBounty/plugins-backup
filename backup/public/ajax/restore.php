<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');
require_once(SG_RESTORE_PATH . 'Restore.php');

if (backupGuardIsAjax() && count($_POST)) {
    $error = array();
    try {
        define('BG_EXTERNAL_RESTORE_RUNNING', true);

		$backupName = sanitize_textarea_field($_POST['bname']);
		$restoreMode = isset($_POST['type'])? sanitize_textarea_field($_POST['type']) : SG_RESTORE_MODE_FULL;

        $backup = new SGBackup();
		$restore = new Restore();
		$backup->clearCache();
		$backup->cleanUpRestoreState($backupName);
		$backup->dropActionsList();
		$restore->doRestore($backupName, null, $restoreMode, SG_ACTION_STATUS_CREATED);
    } catch (SGException $exception) {
        $error[] = $exception->getMessage();
        die(json_encode($error));
    }
}
