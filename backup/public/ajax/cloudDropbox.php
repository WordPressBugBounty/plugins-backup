<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_STORAGE_PATH . 'SGDropboxStorage.php');

if (backupGuardIsAjax()) {
    SGConfig::set('SG_DROPBOX_ACCESS_TOKEN', '');
    SGConfig::set('SG_DROPBOX_CONNECTION_STRING', '');
    SGConfig::set('SG_DROPBOX_REFRESH_TOKEN', '');

    if (isset($_POST['cancel'])) {
        die('{"success":1}');
    }
}

$dp = new SGDropboxStorage();
try {
    $dp->connect();
} catch (Exception $e) {
    header("Location: " . SG_PUBLIC_CLOUD_URL);
}

if ($dp->isConnected()) {
    header("Location: " . SG_PUBLIC_CLOUD_URL);
    exit();
}
