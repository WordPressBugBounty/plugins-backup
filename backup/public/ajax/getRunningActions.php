<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');

if (backupGuardIsAjax()) {
    $runningAction = backupGuardGetRunningActions();
    if ($runningAction) {
        die(json_encode($runningAction));
    }
    die('0');
}
