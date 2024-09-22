<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(SG_SCHEDULE_PATH . 'SGSchedule.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/Timing.php');

class SGBackupSchedule
{

	private function monthIntervals ($id) {

		$month = array (
			1 => 'first day of next month',
			2 => 15, // Middle of the month (15th),
			3 => 'last day of next month'

		);

		return $month[$id] ?? null;

	}

	private function weekDays ($id): ?string
	{

		$days = array (
			1 => 'Monday',
			2 => 'Tuesday',
			3 => 'Wednesday',
			4 => 'Thursday',
			5 => 'Friday',
			6 => 'Saturday',
			7 => 'Sunday'
		);

		return $days[$id] ?? null;

	}

    public function next_interval($options)
    {
        /*
        0 - BG_SCHEDULE_INTERVAL_HOURLY
        1 - BG_SCHEDULE_INTERVAL_DAILY
        2 - BG_SCHEDULE_INTERVAL_WEEKLY
        3 - BG_SCHEDULE_INTERVAL_MONTHLY
        4 - BG_SCHEDULE_INTERVAL_YEARLY

        $options -

							Array
					(
						[sg-schedule-id] => XXXX
						[sg-schedule-label] => XXXXX
						[scheduleInterval] => 1
						[sg-schedule-month-of-year] => 8
						[sg-schedule-day-of-month] => 1
						[sg-schedule-day-of-week] => 1
						[scheduleHour] => 17
						[backupType] => 2
						[backupDatabase] => on
						[backupDBType] => 0
						[directory] => Array
							(
								[0] => wp-content/plugins
								[1] => wp-content/themes
								[2] => wp-content/uploads
							)

						[token] => XXXXXX
						[action] => backup_guard_schedule
					)

        */

        $now = time();  // time always return UTC
        $nextFullHour = null;
        $interval = $options['interval'] ?? $options['scheduleInterval'] ?? null;
        $hour = isset($options['intervalHour']) ? $options['intervalHour'] . ":00" : 00;
        if (isset($options['scheduleHour'])) $hour = $options['scheduleHour'] ? $options['scheduleHour'] . ":00" : null;

		$runAfterSave = $options['runaftersave'] ?? null;
		if ($runAfterSave) return 315536400; // unix time stamp of 1980, just to be in history

		$newDateTime = new DateTime();
		$newDateTime->setTimezone( new DateTimeZone('UTC') ); // forcing UTC because DATE function will default to server time
		$today = $options['next_run'] ?? $newDateTime->format('d-m-Y ' . $hour);

		switch ($interval) {

            case BG_SCHEDULE_INTERVAL_HOURLY:

				$newDateTime->setTimestamp($now)->modify('next hour');
				$nextFullHour = $newDateTime->format('d-m-Y H:00');
                break;

            case BG_SCHEDULE_INTERVAL_DAILY:

				$newDateTime->setTimestamp($now)->modify('+1 day');
				$nextFullHour = $newDateTime->format('d-m-Y '.$hour.':00');

				break;

            case BG_SCHEDULE_INTERVAL_WEEKLY:

				$day = $options['sg-schedule-day-of-week'] ?? $options['dayOfInterval'];
				$newDateTime->setTimestamp($now)->modify('next week ' . $this->weekDays($day));
				$nextFullHour = $newDateTime->format('d-m-Y '.$hour.':00');

				break;

            case BG_SCHEDULE_INTERVAL_MONTHLY:


				$dayOfMonth = $options['sg-schedule-day-of-month'] ?? $options['dayOfInterval'];
				$dayOfMonth = $this->monthIntervals($dayOfMonth);
				$newDateTime->setTimestamp($now);


					if ($dayOfMonth == 15) {

						$newDateTime->setTimestamp($now)->modify('next month');
						$nextFullHour = $newDateTime->format($dayOfMonth.'-m-Y '.$hour.':00');

					} else {

						$newDateTime->setTimestamp($now)->modify($dayOfMonth);
						$nextFullHour = $newDateTime->format('d-m-Y '.$hour.':00');

					}

                break;

            case BG_SCHEDULE_INTERVAL_YEARLY:
				// Not used, keeping for backward compatability

				$newDateTime->setTimestamp($now)->modify('+1 year');
				$nextFullHour = $newDateTime->format('d-m-Y '.$hour.':00');
                break;
        }

        return strtotime($nextFullHour);
    }

    public function return_crontab($scheduleIntervalMonth, $scheduleIntervalDay, $options)
    {
        /*

          Hour -

          [intervalHour] => 0
            [interval] => 0

         Daily -

        [intervalHour] => 0
        [interval] => 1

        Weekly -

        [monthOfInterval] =>
        [dayOfInterval] => 1
        [intervalHour] => 0
        [interval] => 2

        Monthly -

        [monthOfInterval] =>
        [dayOfInterval] => 5
        [intervalHour] => 0
        [interval] => 3

        Yearly -

        [monthOfInterval] => 1
        [dayOfInterval] => 1
        [intervalHour] => 0
        [interval] => 4

         */
        $array['monthOfInterval'] = $scheduleIntervalMonth;
        $array['dayOfInterval'] = $scheduleIntervalDay;
        $array['intervalHour'] = $options['scheduleHour'] ?? '';
        $array['interval'] = $options['scheduleInterval'];
        $array['next_run'] = $this->next_interval($options);

        return $array;
    }

    public function is_cloud($options)
    {
        // If cloud backup
        if (isset($options['backupCloud']) && count($options['backupStorages'])) {

            return backupGuardSanitizeTextField($options['backupStorages']);

        }

        return false;
    }

    public function verify_name($options)
    {
        //Check if schedule name is not empaty
        if (isset($options['sg-schedule-label'])) {
            $label = trim($options['sg-schedule-label']);
            $label = backupGuardSanitizeTextField($label);

            if (empty($label)) {
                $error[] = _backupGuardT('Label field is required.', true);
                die(json_encode($error));
            } else {
                return $label;
            }
        } else {
            $error[] = _backupGuardT('Label field is required.', true);
            die(json_encode($error));
        }
    }

    public function cron_options(): array
    {
        return array(
            'SG_BACKUP_IN_BACKGROUND_MODE' => 0,
            'SG_BACKUP_UPLOAD_TO_STORAGES' => '',
            'SG_ACTION_BACKUP_DATABASE_AVAILABLE' => 0,
            'SG_ACTION_BACKUP_FILES_AVAILABLE' => '',
            'SG_BACKUP_FILE_PATHS_EXCLUDE' => '',
            'SG_BACKUP_FILE_PATHS' => '',
        );
    }

    public function is_remove($array, $res)
    {
        if (isset($array['remove'])) {
            if (isset($array['id'])) {
                SGBackupSchedule::remove((int)$array['id']);
            } else {
                SGBackupSchedule::remove();
            }

            die(json_encode($res));
        }
    }

    public static function create($cron, $options, $label)
    {
        $sgdb = SGDatabase::getInstance();
        $params = array();
        $query = '';

        if (!SGBoot::isFeatureAvailable('MULTI_SCHEDULE')) {
            self::remove();
            $query = 'INSERT INTO ' . SG_SCHEDULE_TABLE_NAME . ' (id, label, status, schedule_options, backup_options) VALUES (%d, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE label=%s, schedule_options=%s, backup_options=%s';

            $params = array(
                SG_SCHEDULER_DEFAULT_ID,
                $label,
                SG_SHCEDULE_STATUS_PENDING,
                json_encode($cron),
                json_encode($options),
                $label,
                json_encode($cron),
                json_encode($options)
            );
        } else {
            $query = 'INSERT INTO ' . SG_SCHEDULE_TABLE_NAME . ' (label, status, schedule_options, backup_options) VALUES (%s, %d, %s, %s)';

            $params = array(
                $label,
                SG_SHCEDULE_STATUS_PENDING,
                json_encode($cron),
                json_encode($options)
            );
        }

        $res = $sgdb->query($query, $params);

        if ($res) {
            $id = $sgdb->lastInsertId();
            SGSchedule::create($cron, $id);
        }
    }

    public static function remove($id = SG_SCHEDULER_DEFAULT_ID)
    {
        $sgdb = SGDatabase::getInstance();
        $sgdb->query('DELETE FROM ' . SG_SCHEDULE_TABLE_NAME . ' WHERE id=%d', array($id));
        SGSchedule::remove($id);
    }

    public static function getNextRun($cron)
    {
        $cron = json_decode($cron, true);

        if (isset($cron['next_run'])) return $cron['next_run'];

        return null;
    }

    public static function getCronExecutionData($cron)
    {
        $cron = json_decode($cron, true);

        return SGSchedule::getCronExecutionData($cron);
    }

    public function getScheduleOptions($id)
    {
        if (!$id) return null;

        $sgdb = SGDatabase::getInstance();
        $results = $sgdb->query('SELECT schedule_options FROM ' . SG_SCHEDULE_TABLE_NAME . ' WHERE `id` = ' . (int)$id);

        return $results[0]['schedule_options'] ? json_decode($results[0]['schedule_options'], true) : null;
    }

    public function UpdateScheduleOptions($id, $new_options)
    {
        if (!$id) return null;

        $sgdb = SGDatabase::getInstance();
        $sgdb->query("UPDATE `" . SG_SCHEDULE_TABLE_NAME . "` SET `schedule_options` = '" . $new_options . "' WHERE `id` = " . (int)$id);
    }

    public static function getAllSchedules($modifyData = true)
    {

		if (backupGuardGetCapabilities() == BACKUP_GUARD_CAPABILITIES_FREE) return [];

		$sgdb = SGDatabase::getInstance();
        $results = $sgdb->query('SELECT id, label, status, schedule_options, backup_options FROM ' . SG_SCHEDULE_TABLE_NAME);
        if (!$modifyData) {
            return $results;
        }

        $schedules = array();
        foreach ($results as $key => $row) {
            $schedules[$key]['id'] = $row['id'];
            $schedules[$key]['label'] = $row['label'];
            $schedules[$key]['status'] = $row['status'];

            $cronExecutionData = self::getCronExecutionData($row['schedule_options']);
            $schedules[$key]['recurrence'] = ucfirst($cronExecutionData['recurrence']);
            $schedules[$key]['executionDate'] = $cronExecutionData['time'];
            $schedules[$key]['next_run'] = self::getNextRun($row['schedule_options']);

            $schedules[$key]['backup_options'] = $row['backup_options'];
        }

        return $schedules;
    }
}
