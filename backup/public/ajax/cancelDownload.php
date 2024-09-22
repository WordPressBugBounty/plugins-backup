<?php
require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

if (backupGuardIsAjax()) {

	$name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : null;
	if (!$name) die('{"success":0}');

	require_once(SG_BACKUP_PATH . 'SGBackup.php');

	$sgbgBackup = new SGBackup();
	$is_action = SGBackup::getActionByName($name);

    if ($is_action) {
		@unlink(SG_BACKUP_DIRECTORY . $name);
		die('{"success":1}');
	}

	die('{"success":0}');

}
