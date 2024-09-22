<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_BACKUP_PATH . 'SGBackup.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGArchive.php');
$backupName = sanitize_text_field($_GET['param']);
$backupName = backupGuardRemoveSlashes($backupName);
$backupPath = SG_BACKUP_DIRECTORY . $backupName;
$backupPath = $backupPath . '/' . $backupName . '.sgbp';

$backup = new SGBackup();
$archive = new SGBGArchive($backupPath);
$archive->setDelegate($backup);
$archive->open('r');
$headers = $archive->getHeaders();
?>

<div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h4 class="modal-title"><?php _backupGuardT("Manual Extract") ?></h4>
        </div>
        <form class="form-horizontal" method="post" id="manualBackup">
            <div class="modal-body sg-modal-body">
                Extracted path - Files will be extracted into <?= SG_BACKUP_DIRECTORY . $backupName ?><b>/extracted</b>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="sgBackup.startExtract('<?php echo addslashes(htmlspecialchars($backupName)) ?>')"
                        class="btn btn-success"><?php _backupGuardT('Extract') ?></button>
            </div>
        </form>
    </div>
</div>
