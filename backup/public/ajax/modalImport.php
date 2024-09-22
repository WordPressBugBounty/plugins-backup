<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

$backupDirectory = SGConfig::get('SG_BACKUP_DIRECTORY');
$maxUploadSize = ini_get('upload_max_filesize');
$dropbox = SGConfig::get('SG_DROPBOX_ACCESS_TOKEN') ?? null;
$gdrive = SGConfig::get('SG_GOOGLE_DRIVE_REFRESH_TOKEN') ?? null;
$ftp = SGConfig::get('SG_STORAGE_FTP_CONNECTED') ?? null;
$amazon = SGConfig::get('SG_AMAZON_KEY') ?? null;
$oneDrive = SGConfig::get('SG_ONE_DRIVE_REFRESH_TOKEN') ?? null;
$pCloud = SGConfig::get('SG_P_CLOUD_ACCESS_TOKEN') ?? null;
$box = SGConfig::get('SG_BOX_REFRESH_TOKEN') ?? null;
$backupGuard = SGConfig::get('SG_BACKUPGUARD_UPLOAD_ACCESS_TOKEN') ?? null;
?>
<div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            <h4 class="modal-title"><?php _backupGuardT('Import from') ?></h4>
        </div>
        <div class="modal-body sg-modal-body" id="sg-modal-inport-from">
            <div class="col-md-12" id="modal-import-1">
                <div class="form-group">
                    <table class="table table-striped paginated sg-backup-table">
                        <tbody>
                        <tr>
                            <td class="file-select-radio"><input name="storage-radio" type="radio" value="local-pc"
                                                                 checked></td>
                            <td></td>
                            <td><?php _backupGuardT('Local PC') ?></td>
                        </tr>
                        <?php if (SGBoot::isFeatureAvailable('DOWNLOAD_FROM_CLOUD')) : ?>

                        <?php if ($ftp) : ?>
                            <tr>
                                <td class="file-select-radio"><input name="storage-radio" type="radio"
                                                                     value="<?php echo SG_STORAGE_FTP ?>" <?php echo empty($ftp) ? 'disabled="disabled"' : '' ?>>
                                </td>
                                <td><span class="btn-xs sg-status-icon sg-status-31 active">&nbsp;</span></td>
                                <td><?php echo 'FTP' ?></td>
                            </tr>
                        <?php endif; ?>


						<?php if ($dropbox) : ?>
                            <tr>
                                <td class="file-select-radio"><input name="storage-radio" type="radio"
                                                                     value="<?php echo SG_STORAGE_DROPBOX ?>" <?php echo empty($dropbox) ? 'disabled="disabled"' : '' ?>>
                                </td>
                                <td><span class="btn-xs sg-status-icon sg-status-32 active">&nbsp;</span></td>
                                <td><?php echo 'Dropbox' ?></td>
                            </tr>

                        <?php endif; ?>



						<?php if ($gdrive) : ?>
                            <tr>
                                <td class="file-select-radio"><input name="storage-radio" type="radio"
                                                                     value="<?php echo SG_STORAGE_GOOGLE_DRIVE ?>" <?php echo empty($gdrive) ? 'disabled="disabled"' : '' ?>>
                                </td>
                                <td><span class="btn-xs sg-status-icon sg-status-33 active">&nbsp;</span></td>
                                <td><?php echo 'Google Drive' ?></td>
                            </tr>

						<?php endif; ?>



						<?php if ($amazon) : ?>
                            <tr>
                                <td class="file-select-radio"><input name="storage-radio" type="radio"
                                                                     value="<?php echo SG_STORAGE_AMAZON ?>" <?php echo empty($amazon) ? 'disabled="disabled"' : '' ?>>
                                </td>
                                <td><span class="btn-xs sg-status-icon sg-status-34 active">&nbsp;</span></td>
                                <td><?php echo (backupGuardIsAccountGold() ? 'Amazon ' : '') . 'S3' ?></td>
                            </tr>

						<?php endif; ?>

						<?php if ($oneDrive) : ?>
                            <tr>
                                <td class="file-select-radio"><input name="storage-radio" type="radio"
                                                                     value="<?php echo SG_STORAGE_ONE_DRIVE ?>" <?php echo empty($oneDrive) ? 'disabled="disabled"' : '' ?>>
                                </td>
                                <td><span class="btn-xs sg-status-icon sg-status-35 active">&nbsp;</span></td>
                                <td><?php echo 'One Drive' ?></td>
                            </tr>
						<?php endif; ?>


						<?php if ($pCloud) : ?>
                           <tr>
                                <td class="file-select-radio"><input name="storage-radio" type="radio"
                                                                     value="<?php echo SG_STORAGE_P_CLOUD ?>" <?php echo empty($pCloud) ? 'disabled="disabled"' : '' ?>>
                                </td>
                                <td><span class="btn-xs sg-status-icon sg-status-37 active">&nbsp;</span></td>
                                <td><?php echo 'pCloud' ?></td>
                            </tr>
						<?php endif; ?>

						<?php if ($box) : ?>
                            <tr>
                                <td class="file-select-radio"><input name="storage-radio" type="radio"
                                                                     value="<?php echo SG_STORAGE_BOX ?>" <?php echo empty($box) ? 'disabled="disabled"' : '' ?>>
                                </td>
                                <td><span class="btn-xs sg-status-icon sg-status-38 active">&nbsp;</span></td>
                                <td><?php echo 'box.com' ?></td>
                            </tr>
							<?php endif; ?>

                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-12" id="modal-import-2">
                <div class="form-group import-modal-popup-content">
                    <div class="col-md-9">
                        <input type="text" id="sg-import-file-name" class="form-control sg-backup-input"
                               placeholder="<?php _backupGuardT('SGBP file') ?>" readonly>
                    </div>
                    <div class="col-lg-3">
                        <span class="input-group-btn">
                            <span class="btn btn-primary btn-file backup-browse-btn">
                                <?php _backupGuardT('Browse') ?>&hellip; <input class="sg-backup-upload-input"
                                                                                type="file" name="files[]"
                                                                                data-url="<?php echo admin_url('admin-ajax.php') . "?action=backup_guard_importBackup&token=" . wp_create_nonce('backupGuardAjaxNonce') ?>"
                                                                                data-max-file-size="<?php echo backupGuardConvertToBytes($maxUploadSize . 'B'); ?>"
                                                                                accept=".sgbp">
                            </span>
                        </span>
                    </div>
                </div>
            </div>
            <?php if (SGBoot::isFeatureAvailable('DOWNLOAD_FROM_CLOUD')) : ?>
                <div class="col-md-12" id="modal-import-3">
                    <table class="table table-striped paginated sg-backup-table" id="sg-archive-list-table">
                        <thead>
                        <tr>
                            <th></th>
                            <th><?php _backupGuardT('Filename') ?></th>
                            <th><?php _backupGuardT('Size') ?></th>
                            <th><?php _backupGuardT('Date') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="clearfix"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="pull-left btn btn-primary" id="switch-modal-import-pages-back"
                    onclick="sgBackup.previousPage()"><?php _backupGuardT('Back') ?></button>
            <span class="modal-close-button" id="sg-close-modal-import"
                  data-dismiss="modal"><?php _backupGuardT("Close") ?></span>
            <button type="button" class="btn btn-success" id="switch-modal-import-pages-next" data-remote="importBackup"
                    onclick="sgBackup.nextPage()"><?php _backupGuardT('Next') ?></button>
            <button type="button" data-remote="importBackup" id="uploadSgbpFile"
                    class="btn btn-success"><?php _backupGuardT('Import') ?></button>
        </div>
    </div>
</div>
