<?php if (!defined('WPINC')) die ('Direct access is not allowed'); ?>

<h2>
	<b>JetBackup Error:</b>  Directory is not writable
</h2>
<p>
	Backup directory is not writable. Please consider changing the permissions of the following directory:<br>
	<strong><?php echo SGConfig::get('SG_BACKUP_NOT_WRITABLE_DIR_PATH'); ?></strong>
</p>
