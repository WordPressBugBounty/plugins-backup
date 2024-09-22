<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once SG_BACKUP_PATH . 'SGBackupSchedule.php';

$error = array();
$success = array('success' => 1);

if (backupGuardIsAjax() && count($_POST)) {
	$options = backupGuardRemoveSlashes($_POST);
	$scheduleIntervalDay = '';
	$scheduleIntervalMonth = '';

	$SGBackupSchedule = new SGBackupSchedule();
	$SGBackupSchedule->is_remove($options, $success);

    //Check if cron available
    if (!SGSchedule::isCronAvailable()) {
        $error[] = _backupGuardT('Cron is not available on your hosting.', true);
        die(json_encode($error));
    }

    $cronOptions = $SGBackupSchedule->cron_options();
    $cronLabel = $SGBackupSchedule->verify_name($options);

    //If background mode
    $cronOptions['SG_BACKUP_IN_BACKGROUND_MODE'] = isset($options['backgroundMode']) ? 1 : 0;
	$clouds = $SGBackupSchedule->is_cloud($options);
	if ($clouds)  $cronOptions['SG_BACKUP_UPLOAD_TO_STORAGES'] = implode(',', $clouds);
	$cronOptions['SG_BACKUP_TYPE'] = $options['backupType'];

   switch ($options['backupType']) {
	   case SG_BACKUP_TYPE_FULL:
		   $cronOptions['SG_BACKUP_FILE_PATHS_EXCLUDE'] = SG_BACKUP_FILE_PATHS_EXCLUDE;
		   $cronOptions['SG_BACKUP_FILE_PATHS'] = 'wp-content';
		   $cronOptions['SG_ACTION_BACKUP_DATABASE_AVAILABLE'] = 1;
		   $cronOptions['SG_ACTION_BACKUP_FILES_AVAILABLE'] = 1;

		   break;

	   case SG_BACKUP_TYPE_CUSTOM:
		   $cronOptions['SG_ACTION_BACKUP_DATABASE_AVAILABLE'] = isset($options['backupDatabase']) ? 1 : 0;

		   // If db backup
		   if (!empty($options['backupDBType'])) {
			   $tablesToBackup = implode(',', $options['table']);
			   $backupOptions['SG_BACKUP_TABLES_TO_BACKUP'] = $tablesToBackup;
		   }

		   // If files backup
		   if (isset($options['backupFiles']) && count($options['directory'])) {
			   $backupFiles = explode(',', SG_BACKUP_FILE_PATHS);
			   $options['directory'] = backupGuardSanitizeTextField($options['directory']);
			   $filesToExclude = @array_diff($backupFiles, $options['directory']);

			   if (in_array('wp-content', $options['directory'])) {
				   $options['directory'] = array('wp-content');
			   } else {
				   $filesToExclude = array_diff($filesToExclude, array('wp-content'));
			   }

			   $filesToExclude = implode(',', $filesToExclude);
			   if (strlen($filesToExclude)) {
				   $filesToExclude = ',' . $filesToExclude;
			   }

			   $cronOptions['SG_BACKUP_FILE_PATHS_EXCLUDE'] = SG_BACKUP_FILE_PATHS_EXCLUDE . $filesToExclude;
			   $cronOptions['SG_ACTION_BACKUP_FILES_AVAILABLE'] = 1;
			   $cronOptions['SG_BACKUP_FILE_PATHS'] = implode(',', $options['directory']);
		   } else {
			   $cronOptions['SG_ACTION_BACKUP_FILES_AVAILABLE'] = 0;
			   $cronOptions['SG_BACKUP_FILE_PATHS'] = 0;
		   }

		   break;

	   default:
		   $error[] = _backupGuardT('Invalid backup type', true);
		   die(json_encode($error));
   }

   switch ($options['scheduleInterval']) {
	   case BG_SCHEDULE_INTERVAL_WEEKLY:
		   $scheduleIntervalDay = (int)$options['sg-schedule-day-of-week'] ?? null;
		   break;

	   case BG_SCHEDULE_INTERVAL_MONTHLY:
		   $scheduleIntervalDay = (int)$options['sg-schedule-day-of-month'] ?? null;
		   break;

	   case BG_SCHEDULE_INTERVAL_YEARLY:
		   $scheduleIntervalDay = (int)$options['sg-schedule-day-of-month'] ?? null;
		   $scheduleIntervalMonth = (int)$options['sg-schedule-month-of-year'] ?? null;
		   break;
   }

	$cronTab = $SGBackupSchedule->return_crontab($scheduleIntervalMonth, $scheduleIntervalDay, $options);
	if (isset($options['sg-schedule-id'])) SGBackupSchedule::remove($options['sg-schedule-id']);

	SGBackupSchedule::create($cronTab, $cronOptions, $cronLabel);

	die(json_encode($success));
}
