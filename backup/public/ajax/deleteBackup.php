<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();
require_once(SG_BACKUP_PATH . 'SGBackup.php');

if (isset($_POST['backupName'])) {
    $backupName = backupGuardSanitizeTextField($_POST['backupName']);
    $backupName = backupGuardRemoveSlashes($backupName);
    for ($i = 0; $i < count($backupName); $i++) {
        SGBackup::deleteBackup($backupName[$i]);
    }
}
die('{"success":1}');
