<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once SG_BACKUP_PATH . 'SGBackupSchedule.php';

$error = array();
$success = array('success' => 1);

if (backupGuardIsAjax() && isset($_POST['cancel'])) {
    SGConfig::set('SG_NOTIFICATIONS_ENABLED', '0');
    SGConfig::set('SG_NOTIFICATIONS_EMAIL_ADDRESS', '');

    die(json_encode($success));
}

if (isset($_POST['sg_clean_backups']) && $_POST['sg_clean_backups'] == 1 ) {

	$sgbackup = new SGBackup();
	$sgbackup->dropActionsList();
	die(json_encode($success));

}

if (isset($_POST['sg_clean_schedules']) && $_POST['sg_clean_schedules'] == 1 ) {

	$sgbackup = new SGBackup();
	$sgbackup->dropSchedules();
	die(json_encode($success));
}

if (isset($_POST['sg_clean_cron']) && $_POST['sg_clean_cron'] == 1 ) {

	SGConfig::set('SG_CRONTAB_ADDED', false);
	$cron = new CronTab();
	$cron->removeCrontab();
	die(json_encode($success));
}


if (isset($_POST['sg_add_cron']) && $_POST['sg_add_cron'] == 1 ) {

	SGConfig::set('SG_CRONTAB_ADDED', false);
	$cron = new CronTab();
	$cron->AddCrontab();
	die(json_encode($success));
}



if (backupGuardIsAjax() && count($_POST)) {
    $_POST = backupGuardRemoveSlashes($_POST);
    $_POST = backupGuardSanitizeTextField($_POST);

    $amountOfBackupsToKeep = (int)@$_POST['amount-of-backups-to-keep'];
    if ($amountOfBackupsToKeep <= 0) {
        $amountOfBackupsToKeep = SG_NUMBER_OF_BACKUPS_TO_KEEP;
    }
    SGConfig::set('SG_AMOUNT_OF_BACKUPS_TO_KEEP', $amountOfBackupsToKeep);

    SGConfig::set('SG_NOTIFICATIONS_ENABLED', '0');
    $emails = '';
    if (isset($_POST['sgIsEmailNotification'])) {
        $emails = @$_POST['sgUserEmail'];
        $emailsArray = explode(',', $emails);

        if (empty($emails)) {
            $error['error'] = _backupGuardT('Email is required.', true);
        }

        foreach ($emailsArray as $email) {
            $email = sanitize_email(trim($email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error[] = _backupGuardT('Invalid email address.', true);
            }
        }

        SGConfig::set('SG_NOTIFICATIONS_ENABLED', '1');
    }
    $ajaxInterval = (int)$_POST['ajaxInterval'];

    if (count($error)) {
        die(json_encode($error));
    }

    if (isset($_POST['sg-hide-ads'])) {
        SGConfig::set('SG_DISABLE_ADS', '1');
    } else {
        SGConfig::set('SG_DISABLE_ADS', '0');
    }

    if (isset($_POST['sg-download-mode'])) {
        SGConfig::set('SG_DOWNLOAD_MODE', (int)$_POST['sg-download-mode']);
    }

    if (isset($_POST['sg-timezone'])) {
        $currentTimezone = SGConfig::get('SG_TIMEZONE') ?: SG_DEFAULT_TIMEZONE;
        SGConfig::set('SG_TIMEZONE', sanitize_text_field($_POST['sg-timezone']));

        if ($currentTimezone != $_POST['sg-timezone']) {
            modifyCronJobsByTimezone();
        }
    }

    if (isset($_POST['sg-background-reload-method'])) {
        SGConfig::set('SG_BACKGROUND_RELOAD_METHOD', (int)$_POST['sg-background-reload-method']);
    } else {
        SGConfig::set('SG_BACKGROUND_RELOAD_METHOD', SG_RELOAD_METHOD_CURL);
    }

    if (isset($_POST['delete-backup-after-upload'])) {
        SGConfig::set('SG_DELETE_BACKUP_AFTER_UPLOAD', '1');
    } else {
        SGConfig::set('SG_DELETE_BACKUP_AFTER_UPLOAD', '0');
    }

    if (isset($_POST['delete-backup-from-cloud'])) {
        SGConfig::set('SG_DELETE_BACKUP_FROM_CLOUD', '1');
    } else {
        SGConfig::set('SG_DELETE_BACKUP_FROM_CLOUD', '0');
    }

    if (isset($_POST['alert-before-update'])) {
        SGConfig::set('SG_ALERT_BEFORE_UPDATE', '1');
    } else {
        SGConfig::set('SG_ALERT_BEFORE_UPDATE', '0');
    }

    if (isset($_POST['show-statistics-widget'])) {
        SGConfig::set('SG_SHOW_STATISTICS_WIDGET', '1');
    } else {
        SGConfig::set('SG_SHOW_STATISTICS_WIDGET', '0');
    }

    if (isset($_POST['ftp-passive-mode'])) {
        SGConfig::set('SG_FTP_PASSIVE_MODE', '1');
    } else {
        SGConfig::set('SG_FTP_PASSIVE_MODE', '0');
    }

    if (isset($_POST['sg-number-of-rows-to-backup'])) {
        SGConfig::set('SG_BACKUP_DATABASE_INSERT_LIMIT', (int)$_POST['sg-number-of-rows-to-backup']);
    } else {
        SGConfig::set('SG_BACKUP_DATABASE_INSERT_LIMIT', SG_BACKUP_DATABASE_INSERT_LIMIT);
    }

    $backupFileName = SG_BACKUP_FILE_NAME_DEFAULT_PREFIX;
    if (isset($_POST['backup-file-name'])) {
        $backupFileName = sanitize_text_field($_POST['backup-file-name']);
    }

    $isReloadingsEnabled = 0;
    if (isset($_POST['backup-with-reloadings'])) {
        $isReloadingsEnabled = 1;
    }

    if (isset($_POST['sg-paths-to-exclude'])) {

		$_paths = sanitize_text_field($_POST['sg-paths-to-exclude']);
		$_paths = rtrim($_paths, ',');
        SGConfig::set('SG_PATHS_TO_EXCLUDE', $_paths);

    } else {
        SGConfig::set('SG_PATHS_TO_EXCLUDE', '');
    }

    if (isset($_POST['sg-tables-to-exclude'])) {

		$_tables = sanitize_text_field($_POST['sg-tables-to-exclude']);
		$_tables = rtrim($_tables, ',');

		SGConfig::set('SG_TABLES_TO_EXCLUDE', $_tables);
    } else {
        SGConfig::set('SG_TABLES_TO_EXCLUDE', '');
    }

	if (isset($_POST['php-cli-location']))  {

		$phpcli = $_POST['php-cli-location'] ? trim($_POST['php-cli-location']) : null;

		if (!$phpcli) {
			SGConfig::set('SG_PHP_CLI_LOCATION', null );
		} else {

			if ( preg_match('/[\s`$"&\'|*?(){}\[\]\\><!~;\r\n]+/', $phpcli, $matches) ) {

				$found = $matches[0] ?? null;
				$error['error'] = _backupGuardT("Error - Usage of un allowed characters {$found} in php-cli field", true);
				die(json_encode($error));

			}

			$phpcli = sanitize_text_field ($phpcli);
			$phpcli = escapeshellcmd($phpcli);

			if (!is_executable($phpcli)) {

				$error['error'] = _backupGuardT("Error - Provided PHP Path {$phpcli} doesn't exist or not accessible", true);
				die(json_encode($error));

			}

			$Execute = new Execute();
			$cmd = "$phpcli -r 'print_r(phpversion());'";
			$res = $Execute->runCommand($cmd, null, true);

			if ( isset($res['code']) && $res['code'] == 1) {

				$cli_err_msg = $res['output']['0'] ?? null;

				$error['error'] = _backupGuardT("Error - Couldn't communicate with provided {$phpcli}, Error: $cli_err_msg", true);
				die(json_encode($error));


			}

			$cron = new CronTab();
			$remove = $cron->removeCrontab();
			SGConfig::set('SG_PHP_CLI_LOCATION', sanitize_text_field ($phpcli) );
			$add = $cron->AddCrontab();
			die(json_encode($success));

		}


	}

	if (isset($_POST['sg-php-memory-limit'])) {

		SGConfig::set('SG_PHP_MEMORY_LIMIT', sanitize_text_field ($_POST['sg-php-memory-limit']) );

	} else {

		SGConfig::set('SG_PHP_MEMORY_LIMIT', '512');
	}

    if (isset($_POST['sg-upload-cloud-chunk-size'])) {
        SGConfig::set('SG_BACKUP_CLOUD_UPLOAD_CHUNK_SIZE', intval($_POST['sg-upload-cloud-chunk-size']));
    } else {
        SGConfig::set('SG_BACKUP_CLOUD_UPLOAD_CHUNK_SIZE', '');
    }

    SGConfig::set('SG_BACKUP_WITH_RELOADINGS', $isReloadingsEnabled);
    SGConfig::set('SG_BACKUP_FILE_NAME_PREFIX', $backupFileName);
    SGConfig::set('SG_AJAX_REQUEST_FREQUENCY', $ajaxInterval);
    SGConfig::set('SG_NOTIFICATIONS_EMAIL_ADDRESS', $emails);
    die(json_encode($success));
}

if (backupGuardIsAjax() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET["type"]) && $_GET["type"] == "updateSetting") {
        //disable alert-before-update from updates page
        if (isset($_GET["alert-before-update"])) {
            SGConfig::set('SG_ALERT_BEFORE_UPDATE', $_GET["alert-before-update"]);
        }
    }
}
