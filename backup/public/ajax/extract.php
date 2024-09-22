<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');
require_once(SG_RESTORE_PATH . 'Extract.php');

if (backupGuardIsAjax() && count($_POST)) {
	define('BG_EXTERNAL_EXTRACT_RUNNING', true);

	try {
		$backupName = sanitize_textarea_field($_POST['bname']);

		$backup = new SGBackup();
		$extract = new Extract();

		$backup->cleanUpExtractState($backupName);
		$backup->dropActionsList();
        $extract->doExtract($backupName);
	} catch (SGException $exception) {
		die(json_encode($exception->getMessage()));
	}
}
