<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

$contentClassName = esc_attr(getBackupPageContentClassName('support'));
?>
<div id="sg-backup-page-content-support" class="sg-backup-page-content <?php echo $contentClassName; ?>">
    <div class="sg-wrap-container">
        <iframe id="sg-backup-guard-iframe" src="<?php echo SG_BACKUP_SUPPORT_URL ?>"></iframe>
    </div>
</div>
