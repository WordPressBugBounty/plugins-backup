<?php
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

define ('CRON_SG_ROOT', dirname(__FILE__, 3) );
define ('CRON_WP_ROOT', dirname(__FILE__, 6) );

require_once (CRON_WP_ROOT . '/wp-load.php');

$db_prefix = $table_prefix ?? null;
if (!$db_prefix) return;

if (!defined('SG_ENV_DB_PREFIX')) define ('SG_ENV_DB_PREFIX', $db_prefix);

$isWeb =  isset($_SERVER['HTTP_TE']) || isset($_SERVER['HTTP_COOKIE']) || isset($_SERVER['HTTP_ACCEPT']) ?? null;

if ($isWeb) {
	$key = SGConfig::get('SG_BACKUP_CURRENT_KEY', true);
	$token = $_GET['token'] ?? null;

	if ($key != $token || !$token) die(1);
}

require_once (CRON_SG_ROOT . '/com/lib/BackupGuard/Core/Cron.php');

$cron = new Cron();

require_once (CRON_SG_ROOT . '/com/config/config.php');
require_once (CRON_SG_ROOT . '/com/core/backup/SGBackup.php');
require_once (CRON_SG_ROOT . '/com/core/backup/SGBackupSchedule.php');
require_once (CRON_SG_ROOT . '/com/lib/BackupGuard/Core/SGBGChunks.php');
require_once (CRON_SG_ROOT . '/com/lib/BackupGuard/Core/SGBGStateJson.php');
require_once (CRON_SG_ROOT . '/com/lib/BackupGuard/Core/RemoteCleanup.php');
require_once (CRON_SG_ROOT . '/com/lib/BackupGuard/Core/Timing.php');


$SGBackup = new SGBackup();
$SGBGChunks = new SGBGChunks();
$sgSchedule = new SGBackupSchedule();
$SGBGStateJson = new SGBGStateJson();
$RemoteCleanup = new RemoteCleanup();
$Timing = new Timing();

$RemoteCleanup->doCleanup();
$actions = $SGBackup->getRunningActions();
$allSchedules = $sgSchedule->getAllSchedules(true);

$now = $Timing->EpochUTC();

if ($actions && count($actions)) $SGBGChunks->run_chunk();

if (!$actions || !count($actions) && $allSchedules && count($allSchedules)) {


	$SGBackupSchedule = new SGBackupSchedule();
	foreach ($allSchedules as $schedule) {
		$schedule_options = $SGBackupSchedule->getScheduleOptions($schedule['id']);
		$next_run = $schedule_options['next_run'] ?? $schedule['executionDate'];
		$next_run = $Timing->printTime(false, true, $next_run, false);

		if ( (int) $now >= (int) $next_run) {

			$schedule_options['next_run'] = $SGBackupSchedule->next_interval($schedule_options);
			$SGBackupSchedule->UpdateScheduleOptions($schedule['id'], json_encode($schedule_options));
			$options = $SGBGStateJson->DoJson('json_decode',$schedule['backup_options']);
			$SGBackup->setIsManual(false);
			$SGBackup->backup($options);

			return;
		}
	}
}

$cron->setCronLastTime(true);