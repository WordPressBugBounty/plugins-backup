<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

$backups = SGBackup::getAllBackups();
$downloadUrl = admin_url('admin-post.php?action=backup_guard_downloadBackup&');
$contentClassName = getBackupPageContentClassName('backups');
$timezone = wp_timezone_string() ?? 'UTC';
@date_default_timezone_set($timezone);

?>
<div id="sg-backup-page-content-backups" class="sg-backup-page-content <?php echo $contentClassName; ?>">
	<?php if (SGConfig::get('SG_REVIEW_POPUP_STATE') == SG_SHOW_REVIEW_POPUP) : ?>
        <!--  Review Box  -->
        <script type="text/javascript">sgShowReview = 1;</script>
	<?php endif; ?>

    <fieldset>
        <div><h1 class="sg-backup-page-title"><?php _backupGuardT('Backups') ?></h1></div>

        <a href="javascript:void(0)" id="sg-manual-backup" class="pull-left btn btn-success sg-backup-action-buttons"
           data-toggle="modal" data-modal-name="manual-backup" data-remote="modalManualBackup"
           sg-data-backup-type="<?php echo SG_BACKUP_METHOD_STANDARD ?>">
            <span class="sg-backup-start sg-backup-buttons-content"></span>
            <span class="sg-backup-buttons-content sg-backup-buttons-text"><?php _backupGuardT('Backup') ?></span>
        </a>

        <!--
        <a href="javascript:void(0)" id="sg-backup-with-migration"
           class="pull-left btn btn-primary sg-backup-action-buttons" data-toggle="modal"
           data-modal-name="manual-backup" data-remote="modalManualBackup"
           sg-data-backup-type="<?php //echo SG_BACKUP_METHOD_MIGRATE ?>"<?php //echo SGBoot::isFeatureAvailable('BACKUP_WITH_MIGRATION') ? '' : 'disabled' ?>>
            <span class="sg-backup-migrate sg-backup-buttons-content"></span>
            <span class="sg-backup-buttons-text sg-backup-buttons-content"><?php // _backupGuardT('Migrate') ?></span>
        </a> -->
		<?php if (!(defined('SG_USER_MODE') && SG_USER_MODE)) : ?>
            <a href="javascript:void(0)" id="sg-import"
               class="btn btn-primary sg-margin-left-12 pull-left  sg-backup-action-buttons" data-toggle="modal"
               data-modal-name="import" data-remote="modalImport">
                <span class="sg-backup-import sg-backup-buttons-content"></span>
                <span class="sg-backup-buttons-text sg-backup-buttons-content"><?php _backupGuardT('Import / Migrate') ?><span>
            </a>
		<?php endif; ?>
		<?php if ($pluginCapabilities == BACKUP_GUARD_CAPABILITIES_FREE) : ?>
            <a href="<?php echo BACKUP_GUARD_WORDPRESS_SUPPORT_URL; ?>" target="_blank">
                <button type="button" id="sg-report-problem-button"
                        class="btn btn btn-primary sg-margin-left-12 pull-right sg-backup-action-buttons sg-button-red pull-right">
                    <span class="sg-backup-report"></span>

                    <span class="sg-backup-buttons-text sg-backup-buttons-content"><?php _backupGuardT('Report issue') ?></span>
                </button>
            </a>
		<?php endif; ?>
        <a id="sg-delete-multi-backups" class="pull-right btn btn-danger sg-margin-left-12 sg-backup-action-buttons">
            <span class="sg-backup-delete sg-backup-buttons-content"></span>
            <span class="sg-backup-buttons-text sg-backup-buttons-content"><?php _backupGuardT('Delete') ?></span>
        </a>
        <div class="clearfix"></div>
        <br/>
        <table class="table table-striped paginated sg-backup-table sg-backups">
            <thead>
            <tr>
                <th><input type="checkbox" id="sg-checkbox-select-all" autocomplete="off"></th>
                <th><?php _backupGuardT('Filename') ?></th>
                <th><?php _backupGuardT('Size') ?></th>
                <th><?php _backupGuardT('Date') ?></th>
                <th><?php _backupGuardT('Status') ?></th>
                <th><?php _backupGuardT('Actions') ?></th>
            </tr>
            </thead>
            <tbody>
			<?php if (empty($backups)) : ?>
                <tr>
                    <td colspan="6"><?php _backupGuardT('No backups found.') ?></td>
                </tr>
			<?php endif; ?>
			<?php foreach ($backups as $backup) : ?>


                <?php

                $status =  $backup['status'];
				$disabled = $backup['active'] && $status <= 2 ? 'disabled' : null;

                ?>

				<?php  ?>

                <tr>
                    <td><input type="checkbox" autocomplete="off"
                               value="<?php echo esc_attr($backup['name']) ?>" <?php echo $disabled ?>>
                    </td>
                    <td><?php echo esc_html($backup['name'])?> </td>
                    <td><?php echo !$backup['active'] ? esc_html($backup['size']) : '' ?></td>
                    <td><?php echo $backup['date']; ?></td>
                    <td id="sg-status-tabe-data-<?php echo esc_attr($backup['id']) ?>" <?php echo $backup['active'] ? 'data-toggle="tooltip" data-bs-placement="top" data-original-title="" data-bs-container="#sg-wrapper"' : '' ?>>
						<?php
						if ($status == 22 || $backup['active'] && $status <= 2) :
							$filteredStatuses = backupGuardFilterStatusesByActionType($backup, $backup['options']);
							?>
                            <input type="hidden" class="sg-active-action-id" value="<?php echo esc_attr($backup['id']); ?>"/>
							<?php foreach ($filteredStatuses as $statusCode) : ?>
                            <span class="btn-xs sg-status-icon sg-status-<?php echo esc_attr($statusCode); ?>">&nbsp;</span>
						<?php endforeach; ?>
                            <div class="sg-progress progress">
                                <div class="progress-bar"></div>
                            </div>
                            <span class="tree-line-data d-none" style="font-size: 10px; line-height: 10px;display: inline-block;">Generating tree files... (<span class="files-amount">0</span>)</span>
                            <span class="log-line-data d-none" style="font-size: 10px; line-height: 10px; display: inline-block;"></span>

						<?php else : ?>
							<?php if ($status == SG_ACTION_STATUS_FINISHED_WARNINGS || $status == 500) : ?>
                                <span class="btn-xs text-warning" data-toggle="tooltip" data-bs-placement="top"
                                      title="
                                <?php if ($backup['type'] == SG_ACTION_TYPE_BACKUP) :
										  echo _backupGuardT('Warnings found during backup', true);
                                      elseif ($backup['type'] == SG_ACTION_TYPE_RESTORE) :
										  echo _backupGuardT('Warnings found during restore', true);
									  else :
										  echo _backupGuardT('Warnings found during upload', true);
									  endif; ?>
                                      " data-bs-container="#sg-wrapper"><?php _backupGuardT('Warning') ?></span>
							<?php elseif ($status == SG_ACTION_STATUS_ERROR) : ?>
                                <span class="btn-xs text-danger" data-toggle="tooltip" data-bs-placement="top"
                                      title="<?php echo _backupGuardT('Errors found, see log file for more details', true); ?>" data-bs-container="#sg-wrapper"><?php _backupGuardT('Failed') ?></span>

							<?php elseif ($status == SG_ACTION_STATUS_NULL) : ?>
                                <span class="btn-xs text-danger" data-toggle="tooltip" data-bs-placement="top"
                                      title="
                                     <?php echo _backupGuardT('Could not retrieve status file', true); ?>
                                      " data-bs-container="#sg-wrapper"><?php _backupGuardT('Error getting status') ?></span>

							<?php elseif ($status == SG_ACTION_STATUS_IMPORTED) : ?>
                                <span class="btn-xs sg-text-success" data-toggle="tooltip" data-bs-placement="top"
                                      title="
                                     <?php echo _backupGuardT('Imported Backup', true); ?>
                                      " data-bs-container="#sg-wrapper"><?php _backupGuardT('This is an imported Backup') ?></span>
							<?php else : ?>


                                <span class="btn-xs sg-text-success"><?php _backupGuardT('Success') ?></span>
							<?php endif; ?>
						<?php endif; ?>
                    </td>
                    <td class="sg-backup-actions-td">
						<?php if ($backup['active'] && $status <= 2) : ?>
							<?php if ($backup['type'] != SG_ACTION_TYPE_RESTORE) : ?>
                                <a class="btn-xs sg-cancel-backup" sg-data-backup-id="<?php echo esc_attr($backup['id']) ?>"
                                   href="javascript:void(0)" title="<?php _backupGuardT('Stop') ?>">&nbsp;&nbsp;</a>
							<?php endif; ?>
                            <div class="btn-group">
                                <a href="javascript:void(0)" class="sg-bg-download-button btn-xs"
                                   data-toggle="dropdown1" aria-expanded="false"
                                   title="<?php _backupGuardT('Download') ?>">
                                </a>
                                <ul class="dropdown-menu">
									<?php if ($backup['backup_log']) : ?>
                                        <li>
                                            <a href="<?php echo $downloadUrl . 'backupName=' . htmlspecialchars(@$backup['name']) . '&downloadType=' . SG_BACKUP_DOWNLOAD_TYPE_BACKUP_LOG ?>">
                                                <i class="glyphicon glyphicon-list-alt"
                                                   aria-hidden="true"></i> <?php _backupGuardT('Backup log') ?>
                                            </a>
                                        </li>
									<?php endif; ?>
                                </ul>
                            </div>

						<?php else : ?>
                            <a href="javascript:void(0)"
                               data-sgbackup-name="<?php echo htmlspecialchars($backup['name']); ?>"
                               data-remote="deleteBackup" class="sg-remove-backup btn-xs"
                               title="<?php _backupGuardT('Delete') ?>">&nbsp;&nbsp;</a>
                            <div class="btn-group">
                                <a href="javascript:void(0)" class="sg-bg-download-button btn-xs"
                                   data-toggle="dropdown1" aria-expanded="false"
                                   title="<?php _backupGuardT('Download') ?>">
                                </a>
                                <ul class="dropdown-menu">
									<?php if ($backup['files']) : ?>
                                        <li>
                                            <a href="<?php echo $downloadUrl . 'backupName=' . htmlspecialchars(@$backup['name']) . '&downloadType=' . SG_BACKUP_DOWNLOAD_TYPE_SGBP ?>">
                                                <i class="glyphicon glyphicon-hdd"
                                                   aria-hidden="true"></i> <?php _backupGuardT('Backup') ?>
                                            </a>
                                        </li>
									<?php endif; ?>
									<?php if ($backup['backup_log']) : ?>
                                        <li>
                                            <a href="<?php echo $downloadUrl . 'backupName=' . htmlspecialchars(@$backup['name']) . '&downloadType=' . SG_BACKUP_DOWNLOAD_TYPE_BACKUP_LOG ?>">
                                                <i class="glyphicon glyphicon-list-alt"
                                                   aria-hidden="true"></i> <?php _backupGuardT('Backup log') ?>
                                            </a>
                                        </li>
									<?php endif; ?>
									<?php if ($backup['restore_log']) : ?>
                                        <li>
                                            <a href="<?php echo $downloadUrl . 'backupName=' . @$backup['name'] . '&downloadType=' . SG_BACKUP_DOWNLOAD_TYPE_RESTORE_LOG ?>">
                                                <i class="glyphicon glyphicon-th-list"
                                                   aria-hidden="true"></i> <?php _backupGuardT('Restore log') ?>
                                            </a>
                                        </li>
									<?php endif; ?>
                                </ul>
                            </div>
							<?php

							$path = SG_BACKUP_DIRECTORY . $backup['name'] . '/' . $backup['name'] . '.sgbp';
							?>
							<?php if (file_exists($path) && ($status != SG_ACTION_STATUS_ERROR) && (filesize($path) > SG_BACKUP_VALID_ARCHIVE_SIZE)) : ?>
                                <a href="javascript:void(0)" title="<?php _backupGuardT('Restore') ?>"
                                   class="sg-restore-button btn-xs" data-toggle="modal" data-modal-name="manual-restore"
                                   data-remote="modalManualRestore"
                                   data-sgbp-params="<?php echo htmlspecialchars($backup['name']) ?>">
                                </a>
                                <a href="javascript:void(0)" title="<?php _backupGuardT('Extract') ?>"
                                   class="sg-extract-button btn-xs" data-toggle="modal" data-modal-name="manual-extract"
                                   data-remote="modalManualExtract"
                                   data-sgbp-params="<?php echo htmlspecialchars($backup['name']) ?>">
                                </a>
							<?php endif; ?>
						<?php endif; ?>
                    </td>
                </tr>
			<?php endforeach; ?>
            </tbody>
        </table>
        <div class="text-right sg-backups">
            <ul class="pagination"></ul>
        </div>
    </fieldset>
    <div class="clearfix"></div>
</div>
