<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once dirname(__FILE__) . '/boot.php';
require_once SG_PUBLIC_INCLUDE_PATH . '/header.php';
$isNotificationEnabled            = SGConfig::get('SG_NOTIFICATIONS_ENABLED');
$userEmail                        = SGConfig::get('SG_NOTIFICATIONS_EMAIL_ADDRESS');
$isDeleteBackupAfterUploadEnabled = SGConfig::get('SG_DELETE_BACKUP_AFTER_UPLOAD');
$isDeleteBackupFromCloudEnabled   = SGConfig::get('SG_DELETE_BACKUP_FROM_CLOUD');
$isDisabelAdsEnabled              = SGConfig::get('SG_DISABLE_ADS');
$isDownloadMode                   = SGConfig::get('SG_DOWNLOAD_MODE');
$isAlertBeforeUpdateEnabled       = SGConfig::get('SG_ALERT_BEFORE_UPDATE');
$isShowStatisticsWidgetEnabled    = SGConfig::get('SG_SHOW_STATISTICS_WIDGET');
$isReloadingsEnabled              = SGConfig::get('SG_BACKUP_WITH_RELOADINGS');

$homepath = function_exists('get_home_path') ? get_home_path() : ABSPATH;
$public_dir = basename($homepath);
$secure_dir = dirname($homepath ,1);
$alternate_backup_folder = SGConfig::get('SG_SECURE_BACKUP_FOLDER') ? SGConfig::get('SG_SECURE_BACKUP_FOLDER') : null;

$momoryLimit = SGConfig::get('SG_PHP_MEMORY_LIMIT') ?? '512';
$memoryLimitValues = array('512', '1024', '2048', '0');

$intervalSelectElement            = array(
    '1000'  => '1 second',
    '2000'  => '2 seconds',
    '3000'  => '3 seconds',
    '5000'  => '5 seconds',
    '7000'  => '7 seconds',
    '10000' => '10 seconds'
);
$selectedInterval                 = (int) SGConfig::get('SG_AJAX_REQUEST_FREQUENCY') ? (int) SGConfig::get('SG_AJAX_REQUEST_FREQUENCY') : SG_AJAX_DEFAULT_REQUEST_FREQUENCY;

$backupFileNamePrefix = SGConfig::get('SG_BACKUP_FILE_NAME_PREFIX') ? SGConfig::get('SG_BACKUP_FILE_NAME_PREFIX') : SG_BACKUP_FILE_NAME_DEFAULT_PREFIX;
$backupFileNamePrefix = esc_attr($backupFileNamePrefix);
$phpCliLocation = SGConfig::get('SG_PHP_CLI_LOCATION') ? SGConfig::get('SG_PHP_CLI_LOCATION') : null;
$sgBackgroundReloadMethod  = SGConfig::get('SG_BACKGROUND_RELOAD_METHOD');
$ftpPassiveMode            = SGConfig::get('SG_FTP_PASSIVE_MODE');
$contentClassName          = esc_attr(getBackupPageContentClassName('settings'));
$savedCloudUploadChunkSize = getCloudUploadChunkSize();
$timezones                 = getAllTimezones();
$timezone                  = SGConfig::get('SG_TIMEZONE') ? : SG_DEFAULT_TIMEZONE;
$licenseKey                = esc_html(substr(SGConfig::get('SG_LICENSE_KEY', true) ?? '', -7));
$pluginCapabilities        = backupGuardGetCapabilities();

?>
<div id="sg-backup-page-content-settings" class="sg-backup-page-content <?php echo $contentClassName; ?>">
    <div class="row sg-settings-container">
        <div class="col-md-12">
            <form class="form-horizontal" method="post" data-sgform="ajax" data-type="sgsettings">
                <fieldset>
                    <div><h1 class="sg-backup-page-title"><?php _backupGuardT('General settings') ?></h1></div>

                    <?php /* if (!$alternate_backup_folder) { ?>
                    <div class="alert alert-danger" role="alert" style="background: #ce2252;">
                        Your current backup folder is set to the default location: <span style="font-style: italic;"><?php echo SG_BACKUP_DIRECTORY; ?></span><br /><br />
                        We strongly recommend selecting an alternative, secure location outside of your public domain's root directory <span style="font-style: italic;">(<?php echo $homepath; ?>)</span> to enhance security and data integrity.
                    </div>

                    <?php } */ ?>

                    <!--
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" style="font-weight: bold;">
							<?php //  _backupGuardT('Secure backup folder') ?><span
                                    class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                            <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('It is recommended to use a backup folder outside of your homedir') ?></span>
                        </label>
                        <div class="col-md-5 text-left">
                            <input id="secure-backup-location" name="secure-backup-location" type="text"
                                   class="form-control input-md sg-backup-input"
                                   placeholder="<?php //echo $secure_dir.'/mybackups' ?>"
                                   value="<?php //echo $alternate_backup_folder ?>">
                        </div>
                    </div>

                    -->

					<?php if ($pluginCapabilities != BACKUP_GUARD_CAPABILITIES_FREE && $licenseKey): ?>
						<div class="form-group">
							<label class="col-md-4"><?php _backupGuardT('License key'); ?></label>
							<div class="col-md-6">
								<span><?php echo '************'.$licenseKey ?></span>
							</div>
							<div class="col-md-2">
								<a class="text-danger" href="javascript:void(0)" onclick="sgBackup.logout()">
									<?php _backupGuardT('Clear license')?>
								</a>
							</div>
						</div>
					<?php endif; ?>

                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" for='sg-timezone'>
                            <?php _backupGuardT("Timezone") ?>
                        </label>
                        <div class="col-md-5 text-left">
                            <select class="form-control" id='sg-timezone' name='sg-timezone'>
                                <option value="UTC"<?php echo $timezone == 'UTC' ? ' selected' : '' ?>>(UTC+00:00) UTC
                                </option>
                                <?php foreach ($timezones as $region => $timezoneText) : ?>
                                    <option value="<?php echo $region ?>"<?php echo $timezone == $region ? ' selected' : '' ?>><?php echo $timezoneText[0] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if (SGBoot::isFeatureAvailable('NOTIFICATIONS')) : ?>
                        <div class="form-group">
                            <label class="col-md-4 sg-control-label">
                                <?php _backupGuardT('Email notifications'); ?><span
                                        class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                                <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Enable notifications to receive status updates about your backup/restore processes.'); ?></span>
                                <?php if (!empty($userEmail)) : ?>
                                    <br/><span
                                            class="text-muted sg-user-email sg-helper-block"><?php echo esc_html($userEmail); ?></span>
                                <?php endif ?>
                            </label>
                            <div class="col-md-3 text-left">
                                <label class="sg-switch-container">
                                    <input type="checkbox" name="sgIsEmailNotification"
                                           class="sg-switch sg-email-switch"
                                           sgFeatureName="NOTIFICATIONS" <?php echo $isNotificationEnabled ? 'checked="checked"' : '' ?>
                                           data-remote="settings">
                                </label>
                            </div>
                        </div>
                        <div class="sg-general-settings">
                            <div class="form-group">
                                <label class="col-md-4 sg-control-label"
                                       for="sg-email"><?php _backupGuardT('Enter email') ?></label>
                                <div class="col-md-5">
                                    <input id="sg-email" name="sgUserEmail" type="text"
                                           placeholder="<?php _backupGuardT('You can enter multiple emails, just separate them with comma') ?>"
                                           class="form-control input-md sg-backup-input"
                                           value="<?php echo esc_attr($userEmail) ?>">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label">
                            <?php _backupGuardT('Reloads enabled'); ?><span
                                    class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                            <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Enable chunked backup/restore. Recommended to avoid execution timeout errors.') ?></span>
                        </label>
                        <div class="col-md-3 text-left">
                            <label class="sg-switch-container">
                                <input type="checkbox" name="backup-with-reloadings"
                                       class="sg-switch" <?php echo $isReloadingsEnabled ? 'checked="checked"' : '' ?>>
                            </label>
                        </div>
                    </div>
                    <?php if (SGBoot::isFeatureAvailable('DELETE_LOCAL_BACKUP_AFTER_UPLOAD')) : ?>
                        <div class="form-group">
                            <label class="col-md-4 sg-control-label">
                                <?php _backupGuardT('Delete local backup after upload'); ?><span
                                        class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                                <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Delete your local copy of backup once it is successfully uploaded to the connected cloud.') ?></span>
                            </label>
                            <div class="col-md-3 text-left">
                                <label class="sg-switch-container">
                                    <input type="checkbox" name="delete-backup-after-upload"
                                           sgFeatureName="DELETE_LOCAL_BACKUP_AFTER_UPLOAD"
                                           class="sg-switch" <?php echo $isDeleteBackupAfterUploadEnabled ? 'checked="checked"' : '' ?>>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (SGBoot::isFeatureAvailable('ALERT_BEFORE_UPDATE')) : ?>
                        <div class="form-group">
                            <label class="col-md-4 sg-control-label">
                                <?php _backupGuardT('Alert before update'); ?><span
                                        class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                                <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Receive an alert to backup you website prior to updating installed plugins.') ?></span>
                            </label>
                            <div class="col-md-3 text-left">
                                <label class="sg-switch-container">
                                    <input type="checkbox" name="alert-before-update"
                                           sgFeatureName="ALERT_BEFORE_UPDATE"
                                           class="sg-switch" <?php echo $isAlertBeforeUpdateEnabled ? 'checked="checked"' : '' ?>>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (SGBoot::isFeatureAvailable('BACKUP_DELETION_WILL_ALSO_DELETE_FROM_CLOUD')) : ?>
                        <div class="form-group">
                            <label class="col-md-4 sg-control-label">
                                <?php _backupGuardT('Retention cleanup will also delete from remote destinations'); ?><span
                                        class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                                <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Remote backups will be rotated based on  the retention limit, so if your retention is set to 5 it will always keep the latest 5 (if you generate a new backup, it will delete the oldest)') ?></span>
                            </label>
                            <div class="col-md-3 text-left">
                                <label class="sg-switch-container">
                                    <input type="checkbox" name="delete-backup-from-cloud"
                                           sgFeatureName="BACKUP_DELETION_WILL_ALSO_DELETE_FROM_CLOUD"
                                           class="sg-switch" <?php echo $isDeleteBackupFromCloudEnabled ? 'checked="checked"' : '' ?>>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label">
                            <?php _backupGuardT('Show statistics'); ?><span
                                    class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                            <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Backup statistics available in the dashboard.') ?></span>
                        </label>
                        <div class="col-md-3 text-left">
                            <label class="sg-switch-container">
                                <input type="checkbox" name="show-statistics-widget"
                                       class="sg-switch" <?php echo $isShowStatisticsWidgetEnabled ? 'checked="checked"' : '' ?>>
                            </label>
                        </div>
                    </div>
                    <?php if (SGBoot::isFeatureAvailable('FTP')) : ?>
                        <div class="form-group">
                            <label class="col-md-4 sg-control-label">
                                <?php _backupGuardT('FTP passive mode'); ?>
                            </label>
                            <div class="col-md-3 text-left">
                                <label class="sg-switch-container">
                                    <input type="checkbox" name="ftp-passive-mode" sgFeatureName="FTP"
                                           class="sg-switch" <?php echo $ftpPassiveMode ? 'checked="checked"' : '' ?>>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (SGBoot::isFeatureAvailable('MULTI_SCHEDULE')) : ?>
                        <div class="form-group">
                            <label class="col-md-4 sg-control-label">
                                <?php _backupGuardT('Disable ads'); ?><span
                                        class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                                <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Disable advertisements inside the plugin (e.g. banners)') ?></span>
                            </label>
                            <div class="col-md-3 text-left">
                                <label class="sg-switch-container">
                                    <input type="checkbox" name="sg-hide-ads" sgFeatureName="HIDE_ADS"
                                           class="sg-switch" <?php echo $isDisabelAdsEnabled ? 'checked="checked"' : '' ?>>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" for='sg-download-mode'>
                            <?php _backupGuardT("Download mode") ?><span
                                    class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                            <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Select what technique to use for downloading the backup files.') ?></span>
                        </label>
                        <div class="col-md-5 text-left">
                            <select class="form-control" id='sg-download-mode' name='sg-download-mode'>
                                <?php if (backupGuardCheckOS() !== 'windows') : ?>
                                    <option value="0" <?php echo $isDownloadMode === BACKUP_GUARD_DOWNLOAD_MODE_LINK ? "selected" : "" ?> >
                                        Hard link
                                    </option>
                                <?php endif; ?>
                                <option value="1" <?php echo $isDownloadMode == BACKUP_GUARD_DOWNLOAD_MODE_PHP ? "selected" : "" ?> >
                                    Via PHP
                                </option>
                                <option value="2" <?php echo $isDownloadMode == BACKUP_GUARD_DOWNLOAD_MODE_SYMLINK ? "selected" : "" ?> >
                                    Symlink
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" for='sg-paths-to-exclude'>
                            <?php _backupGuardT("Exclude paths (separated by commas)") ?>
                        </label>
                        <div class="col-md-5 text-left">
                            <input class="form-control sg-backup-input" id='sg-paths-to-exclude'
                                   name='sg-paths-to-exclude' type="text"
                                   value="<?php echo SGConfig::get('SG_PATHS_TO_EXCLUDE') ? esc_attr(SGConfig::get('SG_PATHS_TO_EXCLUDE')) : '' ?>"
                                   placeholder="e.g. wp-content/cache, wp-content/w3tc-cache">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" for='sg-tables-to-exclude'>
                            <?php _backupGuardT("Tables to exclude (separated by commas)") ?>
                        </label>
                        <div class="col-md-5 text-left">
                            <input class="form-control sg-backup-input" id='sg-tables-to-exclude'
                                   name='sg-tables-to-exclude' type="text"
                                   value="<?php echo SGConfig::get('SG_TABLES_TO_EXCLUDE') ? esc_attr(SGConfig::get('SG_TABLES_TO_EXCLUDE')) : '' ?>"
                                   placeholder="e.g. wp_comments, wp_commentmeta">
                        </div>
                    </div>
                    <?php if (SGBoot::isFeatureAvailable('NUMBER_OF_BACKUPS_TO_KEEP')) : ?>
                        <div class="form-group">
                            <label class="col-md-4 sg-control-label" for='amount-of-backups-to-keep'>
                                <?php _backupGuardT("Backup retention") ?><span
                                        class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                                <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Choose number of backups to keep on the website. Each additional backup will replace the oldest backup file') ?></span>
                            </label>
                            <div class="col-md-5 text-left">
                                <input class="form-control sg-backup-input" id='amount-of-backups-to-keep'
                                       name='amount-of-backups-to-keep' type="text"
                                       value="<?php echo (int) SGConfig::get('SG_AMOUNT_OF_BACKUPS_TO_KEEP') ? (int) SGConfig::get('SG_AMOUNT_OF_BACKUPS_TO_KEEP') : SG_NUMBER_OF_BACKUPS_TO_KEEP ?>" <?php echo (!SGBoot::isFeatureAvailable('NUMBER_OF_BACKUPS_TO_KEEP')) ? 'disabled' : '' ?>>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" for='sg-number-of-rows-to-backup'>
                            <?php _backupGuardT("Number of rows to backup at once") ?><span
                                    class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                            <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Choose the number of row during the Databases backup in order not to overload your RAM.') ?></span>
                        </label>
                        <div class="col-md-5 text-left">
                            <input class="form-control sg-backup-input" id='sg-number-of-rows-to-backup'
                                   name='sg-number-of-rows-to-backup' type="text"
                                   value="<?php echo (int) SGConfig::get('SG_BACKUP_DATABASE_INSERT_LIMIT') ? (int) SGConfig::get('SG_BACKUP_DATABASE_INSERT_LIMIT') : SG_BACKUP_DATABASE_INSERT_LIMIT ?>">
                        </div>
                    </div>
					<?php if (SGBoot::isFeatureAvailable('STORAGE')) : ?>
						<div class="form-group">
							<label class="col-md-4 sg-control-label" for='sg-number-of-rows-to-backup'>
								<?php _backupGuardT("Upload to cloud chunk size") ?><span
										class="dashicons dashicons-editor-help sgbg-info-icon"></span>
								<span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Change the size of the chunk upload during backup to cloud(s).') ?></span>
							</label>
							<div class="col-md-5 text-left">
								<select class="form-control" id='sg-upload-cloud-chunk-szie'
										name='sg-upload-cloud-chunk-size'>
									<option value="4" <?php echo $savedCloudUploadChunkSize == 4 ? "selected" : "" ?> >4MB
									</option>
									<option value="8" <?php echo $savedCloudUploadChunkSize == 8 ? "selected" : "" ?> >8MB
									</option>
									<option value="16" <?php echo $savedCloudUploadChunkSize == 16 ? "selected" : "" ?> >
										16MB
									</option>
									<option value="32" <?php echo $savedCloudUploadChunkSize == 32 ? "selected" : "" ?> >
										32MB
									</option>
								</select>
							</div>
						</div>
					<?php endif; ?>
                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" for='sg-background-reload-method'>
                            <?php _backupGuardT("Reload method") ?><span
                                    class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                            <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('Choose the right PHP Library for reloads') ?></span>
                        </label>
                        <div class="col-md-5 text-left">
                            <select class="form-control" id='sg-background-reload-method'
                                    name='sg-background-reload-method'>
                                <option value="<?php echo SG_RELOAD_METHOD_CURL ?>" <?php echo $sgBackgroundReloadMethod == SG_RELOAD_METHOD_CURL ? "selected" : "" ?> >
                                    Curl
                                </option>
                                <option value="<?php echo SG_RELOAD_METHOD_STREAM ?>" <?php echo $sgBackgroundReloadMethod == SG_RELOAD_METHOD_STREAM ? "selected" : "" ?> >
                                    Stream
                                </option>
                                <option value="<?php echo SG_RELOAD_METHOD_SOCKET ?>" <?php echo $sgBackgroundReloadMethod == SG_RELOAD_METHOD_SOCKET ? "selected" : "" ?> >
                                    Socket
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-4 sg-control-label">
							<?php _backupGuardT('PHP CLI Binary location') ?><span
                                    class="dashicons dashicons-editor-help sgbg-info-icon"></span>
                            <span class="infoSelectRepeat samefontStyle sgbg-info-text"><?php _backupGuardT('JetBackup relies on PHP CLI for background operations') ?></span>
                        </label>
                        <div class="col-md-5 text-left">
                            <input id="php-cli-location" name="php-cli-location" type="text"
                                   class="form-control input-md sg-backup-input"
                                   placeholder="/opt/cpanel/ea-php82/root/bin/php"
                                   value="<?php echo $phpCliLocation ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-4 sg-control-label" for="sg-email">
                            <?php _backupGuardT('Request frequency') ?>
                        </label>
                        <div class="col-md-5">
                            <?php echo selectElement($intervalSelectElement, array('id'    => 'sg-ajax-interval',
                                                                                   'name'  => 'ajaxInterval',
                                                                                   'class' => 'form-control'
                            ), '', $selectedInterval); ?>
                        </div>
                    </div>



                    <hr />

                    <div class="form-group">
                        <label class="col-md-4"><?php _backupGuardT('PHP Memory Limit'); ?></label>
                        <div class="col-md-6 text-left">

                            <select class="form-control" id='sg-php-memory-limit' name='sg-php-memory-limit'>

                                <?php
                                    $selected = null;
                                    foreach ($memoryLimitValues as $value) {
                                        if ($value == $momoryLimit) {
                                            if ($value != 0) {
                                                echo " <option value='" . $value . "' selected> " . $value . "MB</option> ";
                                            } else {
                                                echo " <option value='".$value."' selected>DO NOT CHANGE</option> ";
                                            }
                                        } else if ($value == 0) {
                                            echo " <option value='".$value."'>DO NOT CHANGE</option> ";
                                        } else {
                                            echo " <option value='".$value."'> ".$value."MB</option> ";
                                        }
                                    }
                                ?>

                            </select>
                        </div>
                    </div>

                    <hr />
                    <div class="form-group">

                        <div class="col-md-3">
                            <a id="sg-clean-backups" class="btn btn-primary sg-backup-action-buttons" style="padding: 10px 0px 10px 7px; text-align: center;">
								<?php _backupGuardT('Clear DB Actions Table') ?>
                            </a>


                        </div>

                        <?php

						$sg_crontab_added = SGConfig::get('SG_CRONTAB_ADDED') ?? null;
                        if (!$sg_crontab_added) {

                            ?>
                        <div class="col-md-3">
                            <a id="sg-add-cron" class="btn btn-primary sg-backup-action-buttons" style="padding: 10px 0px 10px 7px; text-align: center;">
								<?php _backupGuardT('Add Cron') ?>
                            </a>
                        </div>
                        <?php } else { ?>
                            <div class="col-md-3">
                                <a id="sg-clean-cron" class="btn btn-primary sg-backup-action-buttons" style="padding: 10px 0px 10px 7px; text-align: center;">
									<?php _backupGuardT('Remove Cron') ?>
                                </a>
                            </div>
                        <?php } ?>

                        <div class="col-md-3">
                            <a id="sg-clean-schedules" class="btn btn-primary sg-backup-action-buttons" style="padding: 10px 0px 10px 7px; text-align: center;">
								<?php _backupGuardT('Clear DB Schedules') ?>
                            </a>
                        </div>

                        <div class="col-md-5 text-right">
                            <button type="button" id="sg-save-settings" class="btn btn-success"
                                    onclick="sgBackup.sgsettings();"><?php _backupGuardT('Save') ?></button>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12 d-flex justify-content-center">
                            <div class="jet-error"></div>
                        </div>
                    </div>



                </fieldset>
            </form>
        </div>
    </div>
</div>
