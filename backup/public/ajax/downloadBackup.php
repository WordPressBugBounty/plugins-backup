<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();
require_once SG_BACKUP_PATH . 'SGBackup.php';

if (count($_GET)) {

    $response = array();
    $downloadType = isset($_GET['downloadType']) ? (int)$_GET['downloadType'] : null;
	$backupName = isset($_GET['backupName']) ? backupGuardRemoveSlashes(sanitize_text_field($_GET['backupName'])) : null;

	$types = array(
			SG_BACKUP_DOWNLOAD_TYPE_BACKUP_LOG,
			SG_BACKUP_DOWNLOAD_TYPE_RESTORE_LOG,
			SG_BACKUP_DOWNLOAD_TYPE_SGBP
		);

    if ($backupName && $downloadType && in_array($downloadType, $types)) {

        try {
            SGBackup::download($backupName, $downloadType);
        } catch (SGException $exception) {
            die($exception->getMessage());
        }
    }
}
