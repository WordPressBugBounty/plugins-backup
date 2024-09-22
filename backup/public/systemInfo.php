<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(SG_SCHEDULE_PATH . 'SGSchedule.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/Cron.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/BGTask.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/Execute.php');

$contentClassName = esc_attr(getBackupPageContentClassName('system_info'));

$openfiles = function_exists('posix_getrlimit') ? posix_getrlimit() : null;
$openfiles = $openfiles['hard openfiles'] ?? 'Cannot get value';

?>
<div id="sg-backup-page-content-system_info" class="sg-backup-page-content <?php echo $contentClassName; ?>">
    <div class="row">
        <div class="col-md-12">
            <form class="form-horizontal" method="post" data-sgform="ajax" data-type="sgsettings">
                <fieldset>
                    <div><h1 class="sg-backup-page-title"><?php _backupGuardT('System information') ?></h1></div>
                    <div class="form-group">
                        <label class="col-md-3 sg-control-label sg-user-info"><?php _backupGuardT('Disk free space'); ?></label>
                        <div class="col-md-3 text-left">
                            <label class="sg-control-label">
								<?php echo backupGuardDiskFreeSize(SG_APP_ROOT_DIRECTORY); ?>
                            </label>
                        </div>
                    </div>
                    <div class="form-group sg-info-wrapper">
                        <label class="col-md-3 sg-control-label sg-user-info"><?php _backupGuardT('Memory limit'); ?></label>
                        <div class="col-md-3 text-left">
                            <label class="sg-control-label"><?php echo SGBoot::$memoryLimit; ?></label>
                        </div>
                    </div>
                    <div class="form-group sg-info-wrapper">
                        <label class="col-md-3 sg-control-label sg-user-info">
							<?php _backupGuardT('Max execution time'); ?>
                        </label>
                        <div class="col-md-3 text-left">
                            <label class="sg-control-label"><?php echo SGBoot::$executionTimeLimit; ?></label>
                        </div>
                    </div>
                    <div class="form-group sg-info-wrapper">
                        <label class="col-md-3 sg-control-label sg-user-info">
							<?php _backupGuardT('Wordpress path'); ?>
                        </label>
                        <div class="col-md-3 text-left">
                            <label class="sg-control-label"><?php echo $_SERVER['DOCUMENT_ROOT']; ?></label>
                        </div>
                    </div>
                    <div class="form-group sg-info-wrapper">
                        <label class="col-md-3 sg-control-label sg-user-info">
							<?php _backupGuardT('PHP version'); ?>
                        </label>
                        <div class="col-md-3 text-left">
                            <label class="sg-control-label"><?php echo PHP_VERSION; ?></label>
                        </div>
                    </div>

                    <div class="form-group sg-info-wrapper">
                        <label class="col-md-3 sg-control-label sg-user-info">
							<?php _backupGuardT('PHP CLI version'); ?>
                        </label>

						<?php

						$Execute = new Execute();
						$phpcli = SGConfig::get('SG_PHP_CLI_LOCATION') ? SGConfig::get('SG_PHP_CLI_LOCATION') : 'php';
						$cmd = "$phpcli -r 'print_r(phpversion());'";
						$res = $Execute->runCommand($cmd, null, true);

						echo  '<div class="col-md-3 text-left">';

						if ($Execute->parseResultsCode($res) && isset($res['output'][0])) {
							echo   '<label class="sg-control-label">' . $res['output'][0] . '</label>';

						} else {
							echo    '<label class="sg-control-label">Cannot fetch cli version</label>';
						}
						echo '</div>';

						?>




                    </div>




                    <div class="form-group sg-info-wrapper">
                        <label class="col-md-3 sg-control-label sg-user-info"><?php _backupGuardT('MySQL version'); ?></label>
                        <div class="col-md-3 text-left">
                            <label class="sg-control-label"><?php echo SG_MYSQL_VERSION; ?></label>
                        </div>
                    </div>
                    <div class="form-group sg-info-wrapper">
                        <label class="col-md-3 sg-control-label sg-user-info">
							<?php _backupGuardT('Int size'); ?>
                        </label>
                        <div class="col-md-3 text-left">
							<?php echo '<label class="sg-control-label">' . PHP_INT_SIZE . '</label>'; ?>
							<?php
							if (PHP_INT_SIZE < 8) {
								echo '<label class="sg-control-label backup-guard-label-warning">Notice that archive size cannot be bigger than 2GB. This limitaion is comming from system.</label>';
							}
							?>
                        </div>
                    </div>
                    <div class="form-group sg-info-wrapper">
                        <div class="col-md-3 ">
                            <label class="sg-control-label sg-user-info"><?php _backupGuardT('Curl version'); ?></label>
                        </div>
                        <div class="col-md-8 text-left">
							<?php
							if (function_exists('curl_version') && function_exists('curl_exec')) {
								$cv = curl_version();
								echo '<label class="sg-control-label jb-orange-label">' . $cv['version'] . ' / SSL: ' . $cv['ssl_version'] . ' / libz: ' . $cv['libz_version'] . '</label>';
							} else {
								echo '<label class="sg-control-label backup-guard-label-warning">Curl required for JetBackup for better functioning.</label>';
							}
							?>
                        </div>
                    </div>


                    <div class="form-group sg-info-wrapper">

                        <div class="col-md-3 ">
                            <label class="sg-control-label sg-user-info"><?php _backupGuardT('Available Web Crons'); ?></label>
                        </div>
                        <div class="col-md-3 text-left">
							<?php

							$available = (new Cron)->getAvailable();
							$list = implode(', ',array_keys($available));
							$functions = (new BGTask)->listAvailableFunctions();

							if (!empty($available)) {
								echo "<label class='sg-control-label'><span style='font-family: monospace; font-weight: normal;'>{$list}</span></label>";
							} else {
								//echo '<label class="sg-control-label backup-guard-label-warning">Please consider enabling WP Cron in order to be able to setup schedules.</label>';
								echo "<label class='sg-control-label' style='color: red;'>None of the web cron functions is available - <br /> <span style='font-family: monospace; font-weight: normal;'>{$functions}</span>. <br /> Please see schdedule page for how to configure a manual cron job</label>";
							}
							?>
                        </div>


                    </div>

                    <div class="form-group sg-info-wrapper">

                        <div class="col-md-3 ">
                            <label class="sg-control-label sg-user-info"><?php _backupGuardT('Background cron installed?'); ?></label>
                        </div>
                        <div class="col-md-3 text-left">
							<?php

							$is_crontab = SGConfig::get('SG_CRONTAB_ADDED') || (new CronTab())->searchCron(false) ? 'Yes' : 'No / Cannot read crontab';
							echo "<label class='sg-control-label'>{$is_crontab}</label>";

							?>
                        </div>


                    </div>


                    <div class="form-group sg-info-wrapper">

                        <div class="col-md-3 ">
                            <label class="sg-control-label sg-user-info"><?php _backupGuardT('Crontab contents'); ?></label>
                        </div>
                        <div class="col-md-3 text-left">
							<?php

							$crontab = (new CronTab())->getCrontab(false);
							if ($crontab && count($crontab)) {
								$cron_lines = implode("\n",$crontab );
								echo "<label class='sg-control-label'><pre style='font-weight: normal;'>$cron_lines</pre></label>";

							} else {
								echo "<label class='sg-control-label'>Cannot read crontab</label>";

							}


							?>
                        </div>


                    </div>



                    <div class="form-group sg-info-wrapper">

                        <div class="col-md-3 ">
                            <label class="sg-control-label sg-user-info"><?php _backupGuardT('System open files limit'); ?></label>
                        </div>
                        <div class="col-md-3 text-left">
							<?php

							echo "<label class='sg-control-label'>{$openfiles}</label>";

							?>
                        </div>


                    </div>



                </fieldset>
            </form>
        </div>
    </div>
</div>