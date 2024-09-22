<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');

try {
    $state = false;
    $success = array('success' => 1);

    if (backupGuardIsAjax() && count($_POST)) {
        SGBackup::dropActionsList();
		$allActions = SGBackup::getRunningActions();

        if (count($allActions)) { // abort any other backup if there is an active action
            die(json_encode(array(
                "error" => _backupGuardT("There is an active backup running. Please try later", true)
            )));
        }

        $options = $_POST;
        $error = array();
        SGConfig::set("SG_BACKUP_TYPE", (int)$options['backupType']);
        $options = backupGuardGetBackupOptions($options);

        $sgbgBackup = new SGBackup();
        $sgbgBackup->backup($options);

        die(json_encode($success));
    }

    die(json_encode(array(
        "error" => "Direct call"
    )));
} catch (SGException $exception) {
    $error[] = $exception->getMessage();
    die(json_encode($error));
}
