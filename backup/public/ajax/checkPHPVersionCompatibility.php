<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGArchive.php');

if (backupGuardIsAjax() && count($_POST)) {
    try {
        $name = sanitize_text_field($_POST['bname']);
        $name = backupGuardRemoveSlashes($name);
        $path = SG_BACKUP_DIRECTORY . $name . '/' . $name . '.sgbp';

        $archive = new SGBGArchive($path);
        $archive->open('r');
        $headers = $archive->getHeaders();

		if (isset($headers['phpVersion'])) {
            $oldPHPVersion  = (int) $headers['phpVersion'];
            $currentVersion = (int) phpversion();

            // Drop the last digits of version (e.g. 5.3.3 will be 5) by explicit casting from string to int. This will check the migrations like php 5.x.x -> 7.x.x
            if ($oldPHPVersion != $currentVersion) {
                die(json_encode(array(
					'error' => sprintf("<strong>ERROR : PHP version mismatch!</strong> <br /> <br /> This backup was taken using PHP version <strong>%s</strong>, the current PHP version is <strong>%s</strong>. <br /> Backups can only be restored using the same PHP version it was taken on. <br /><br /> Please change your PHP version to <strong>%s</strong> in order to proceed with the restore. If you don’t know how to do so, please contact your hosting provider.", $oldPHPVersion, $currentVersion, $oldPHPVersion)
                    )));
            }
        }

        die(json_encode(array()));
    } catch (Exception $e) {
        die(json_encode(array(
                            'error' => $e->getMessage()
                        )));
    }
}
