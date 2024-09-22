<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGArchive.php');

$pluginCapabilities = backupGuardGetCapabilities();
$backupName = sanitize_text_field($_POST['bname']);
$path = SG_BACKUP_DIRECTORY . $backupName . '/' . $backupName . '.sgbp';
$archive = new SGBGArchive($path);
$archive->open('r');
$headers = $archive->getHeaders();

$siteUrl  = preg_replace("(^https?://)", "", str_replace('//www.', '//', $headers['siteUrl']) );
$dbPrefix = $headers['dbPrefix'];

if ($siteUrl != preg_replace("(^https?://)", "", str_replace('//www.', '//', SG_SITE_URL))) {
    printf("The source url (%s) doesn’t match the current url (%s). This is considered as migration and it is not available in this plan. <a href='%s' target='_blank'>Upgrade now</a>", $siteUrl, SG_SITE_URL, BG_UPGRADE_URL);
} else if ($dbPrefix != SG_ENV_DB_PREFIX) {
    printf("The source db prefix (%s) doesn’t match the current db prefix (%s). This is considered as migration and it is not available in this plan.
You can change the current db prefix manually or upgrade. <a href='%s' target='_blank'>Upgrade now</a>", $dbPrefix, SG_ENV_DB_PREFIX, BG_UPGRADE_URL);
}
