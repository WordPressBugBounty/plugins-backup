<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');

if (backupGuardIsAjax() && count($_POST)) {
    $error = array();

    try {
		$backupName = isset($_POST['backupName']) ? sanitize_text_field($_POST['backupName']) : null;
        if ($backupName && file_exists(SG_BACKUP_DIRECTORY . $backupName)) throw new SGExceptionForbidden($backupName . " backup already exists");

		SGConfig::set('SG_RUNNING_ACTION', 0, true);
        //SGConfig::set('SG_BACKUP_CURRENT_KEY', md5(microtime(true)), true);

		die('{"success":1}');

    } catch (SGException $exception) {
        $error[] = $exception->getMessage();
        die(json_encode($error));
    }
}
