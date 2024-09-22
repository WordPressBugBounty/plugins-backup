<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(SG_BACKUP_PATH . 'SGBackupSchedule.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/Cron.php');

$Timing = new Timing();

// $Timing->printTime(0, 0, time(), true )

$allSchedules = SGBackupSchedule::getAllSchedules();
$contentClassName = esc_attr(getBackupPageContentClassName('schedule'));

$cron = new Cron();

$cron_last_time = $cron->getCronLastTime();

$timezone_url = get_admin_url(). "/options-general.php";
$timezone = $Timing->getWPTimeZone();

$cron_display = $cron_last_time ? $Timing->printTime(1, 0, $cron_last_time) : '00:00:00';
$key = SGConfig::get('SG_BACKUP_CURRENT_KEY', true) ?? null;
$loc = dirname(__FILE__, 2);
$loc = basename($loc);
$url = $cron->getUrl();
$shell_command = $cron->getCommand();
$is_crontab = SGConfig::get('SG_CRONTAB_ADDED') || (new CronTab())->searchCron(false);
$system_info_admin_url =  network_admin_url('admin.php?page=backup_guard_system_info');
$systeminfo = '<a href = "' . $system_info_admin_url . '"> system info page </a>';
?>

<div id="sg-backup-page-content-schedule" class="sg-backup-page-content <?php echo $contentClassName; ?>">
    <div class="sg-schedule-container">
        <fieldset>
            <div><h1 class="sg-backup-page-title"><?php _backupGuardT('Schedule') ?></h1></div>

            <div style="text-align: left; width: 100%;">
                <p style="font-size: 14px;"><?php _backupGuardT('Scheduling your backups depends on our ability to execute cron jobs (scheduled tasks).') ?></p>
                <p style="font-size: 14px;"><?php _backupGuardT('It is recommended to setup a manual cron job through your hosting provider, you can select one of the following configurations  - ') ?></p>
                <p>&nbsp;</p>
                <p><?php _backupGuardT('(to be configured through your hosting control panel') ?></p>
                <pre style="with: 80%; margin: 0 auto; font-size: 12px;">* * * * * <?php echo $shell_command; ?> </pre>

                <?php if ($key) : ?>
                    <p>&nbsp;</p>
                    <p> <?php _backupGuardT('You can test the cron via the following link (not to be configured for automation, access token changes every job).') ?></p>

                    <pre style="with: 80%; margin: 0 auto; font-size: 12px;"><?php echo $url; ?></pre>
                    <p>&nbsp;</p>
                    <p><?php _backupGuardT('* JetBackup will automatically try to install background cron task once a backup job runs, you can verify the status in the ' . $systeminfo)   ?></p>
				<?php endif; ?>
            <p>&nbsp;</p>
            </div>

			<?php if (empty($allSchedules)) : ?>
            <button class="pull-left btn btn-success sg-backup-action-buttons" data-toggle="modal"
                    data-modal-name="create-schedule" data-remote="modalCreateSchedule">
                <span class="sg-backup-cross sg-backup-buttons-content"></span>
                <span class="sg-backup-buttons-text sg-backup-buttons-content"><?php _backupGuardT('Create schedule') ?></span>
            </button>
			<?php endif; ?>
            <div class="clearfix"></div>
            <br/>
            <table class="table table-striped sg-schedule paginated sg-backup-table">
                <thead>
                <tr>
                    <th><?php _backupGuardT('Label') ?></th>
                    <th><?php _backupGuardT('Recurrence') ?></th>
                    <th><?php _backupGuardT('Next Run (UTC)') ?></th>
                    <th><?php _backupGuardT('Backup options') ?></th>
                    <th><?php _backupGuardT('Upload to') ?></th>
                    <th><?php _backupGuardT('Status') ?></th>
                    <th><?php _backupGuardT('Actions') ?></th>
                </tr>
                </thead>
                <tbody>
				<?php if (empty($allSchedules)) : ?>
                    <tr>
                        <td colspan="7"><?php _backupGuardT('No schedules found.') ?></td>
                    </tr>
				<?php endif; ?>
				<?php foreach ($allSchedules as $schedule) :
					$backupOptions = backupGuardParseBackupOptions($schedule);
					$next_run = $schedule['next_run'] ?? $schedule['executionDate'];
					?>
                    <tr>
                        <td><?php echo esc_html($schedule['label']) ?></td>
                        <td><?php echo esc_html($schedule['recurrence']) ?></td>
                        <td>
                            <?php
							//echo '#' . $next_run .'#';
                                if ($next_run == 315536400) {
                                    echo '<strong>' . _backupGuardT('Immediately with next cron')  . '</strong>';
								} else {
									echo $Timing->printTime(0, 0, (int)$next_run, false);
								}
                            ?>
                        </td>
                        <td>
							<?php
							$showOptions = array();
							if (!$backupOptions['isCustomBackup']) {
								$showOptions[] = 'Full';
							} else {
								if ($backupOptions['isDatabaseSelected']) {
									$showOptions[] = 'DB';
								}
								if ($backupOptions['isFilesSelected']) {
									$selectedDirectories = str_replace('wp-content/', '', $backupOptions['selectedDirectories']);
									if (in_array('wp-content', $selectedDirectories)) {
										$showOptions[] = 'wp-content';
									} else {
										$showOptions = array_merge($showOptions, $selectedDirectories);
									}
								}
							}
							echo implode(', ', $showOptions);
							?>
                        </td>
                        <td>
							<?php
							foreach ($backupOptions['selectedClouds'] as $cloud) {
								echo '<span class="btn-xs sg-status-icon sg-status-3' . esc_attr($cloud) . '">&nbsp;</span> ';
							}
							?>
                        </td>
                        <td><?php echo (int)$schedule['status'] == SG_SHCEDULE_STATUS_PENDING ? '<span class="sg-schedule-pending">' . _backupGuardT('Pending', true) . '</span>' : '<span class="sg-schedule-inactive">' . _backupGuardT('Inactive', true) . '</span>' ?></td>
                        <td>
                            <a data-toggle="modal" data-modal-name="create-schedule" data-remote="modalCreateSchedule"
                               data-sgbp-params="<?php echo esc_attr($schedule['id']) ?>"
                               class="btn-xs sg-schedule-icon sg-schedule-edit" title="<?php _backupGuardT('Edit') ?>">&nbsp</a>
                            <a onclick="sgBackup.removeSchedule(<?php echo esc_attr($schedule['id']) ?>)"
                               class="btn-xs sg-schedule-icon sg-schedule-delete"
                               title="<?php _backupGuardT('Delete') ?>">&nbsp&nbsp;</a>
                        </td>
                    </tr>
				<?php endforeach; ?>
                </tbody>
            </table>
            <div class="text-right sg-schedule">
                <ul class="pagination"></ul>
            </div>
        </fieldset>

        <p style="font-size: 14px;"><?php _backupGuardT('* Only one online schedule can be active at a time. To create a new schedule, please delete the current one.') ?></p>


        <hr />
        <p style="text-align: center;"> <strong> <?php _backupGuardT('Wordpress Timezone :') ?></strong> <?php echo $timezone ?> | <strong><?php _backupGuardT('Server Current Time: '); ?> </strong> <?php echo $Timing->printTime(0, 0, time(), true ) ?> | <strong><?php _backupGuardT('UTC Current Time: '); ?> </strong> <?php echo $Timing->printTime(0, 0, null, false ) ?> |<strong> <?php _backupGuardT('Cron last run :') ?> </strong> <?php echo $cron_display ?>
        <hr />
        <p>* WordPress timezone can be set inside <a target="_blank" href="<?php echo $timezone_url; ?>">General Settings</a></p>

    </div>
</div>
