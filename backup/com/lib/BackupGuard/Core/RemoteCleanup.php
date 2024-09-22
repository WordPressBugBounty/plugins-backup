<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

include_once(SG_LIB_PATH . 'BackupGuard/Core/Timing.php');

class RemoteCleanup {

	private array $_config;
	private $_now;

	public function __construct() {

		$this->_config = SGConfig::getAll();
		$this->setNow();
	}

	private function setNow () {

		$Timing = new Timing();
		$this->_now = $Timing->EpochUTC();

	}

	private function setLastRun () {

		SGConfig::set('SG_REMOTE_CLEANUP_LAST_RUN', $this->_now);
		return $this->_now;

	}

	private function getLastRun ()
	{

			return $this->_config['SG_REMOTE_CLEANUP_LAST_RUN'] ?? $this->setLastRun();

	}

	private function listDestinations (): array
	{
		// Creates a list of the destination ID and the relevant settings
		// If the settings is set, the destination is active

		return array (
			SG_STORAGE_FTP => 'SG_STORAGE_FTP_CONNECTED',
			SG_STORAGE_DROPBOX => 'SG_DROPBOX_ACCESS_TOKEN',
			SG_STORAGE_GOOGLE_DRIVE => 'SG_GOOGLE_DRIVE_REFRESH_TOKEN',
			SG_STORAGE_AMAZON => 'SG_AMAZON_KEY',
			SG_STORAGE_ONE_DRIVE => 'SG_ONE_DRIVE_REFRESH_TOKEN',
			SG_STORAGE_P_CLOUD => 'SG_P_CLOUD_ACCESS_TOKEN',
			SG_STORAGE_BOX => 'SG_BOX_REFRESH_TOKEN'
		);
	}

	private function activeDestinations (): array
	{

		$destinations = $this->listDestinations();
		$settings = $this->_config;
		$active = array();

		foreach ($destinations as $id => $name) {

			if (isset($settings[$name]) && $settings[$name]) $active[$id] = $name;

		}

		return $active;

	}

	private function canDelete () {

		return $this->_config['SG_DELETE_BACKUP_FROM_CLOUD'] ?? null;

	}

	private function sortByDate ($backups)
	{

		if (empty($backups)) return false;

		$array = array();

		foreach ($backups as $backup) {

			$array[strtotime($backup['date'])] = $backup;

		}

		krsort($array);
		return $array;

	}

	public function doCleanup ($force = false) {


		// If current time is bigger then last run + 24h we should run
		if ( $force || $this->_now > ($this->getLastRun() + 86400 ) ) {

			$this->setLastRun();

			$destinations = $this->activeDestinations();
			$retention = $this->_config['SG_AMOUNT_OF_BACKUPS_TO_KEEP'] ?? null;

			if (empty($destinations)) return false;
			if (!$this->canDelete()) return false;
			if (!$retention) return false;
			if ($retention <= 0) return false;

			$sgbgBackup = new SGBackup();


			foreach ($destinations as $id => $name) {

				$backups = $this->sortByDate($sgbgBackup->listStorage($id));

				if (empty($backups)) continue;

				$count = sizeof($backups);
				$toDelete = $count - $retention;
				if ($toDelete <= 0) continue;

				$cleanup = array();

				while ( $toDelete > 0 ) {

					$lastKey = array_key_last($backups);
					$cleanup[$lastKey] = $backups[$lastKey];
					array_pop($backups);
					$toDelete--;

				}

				if (empty($cleanup)) continue;

				// This is the final list of backups to be deleted from the remote storage
				foreach ($cleanup as $remove) {

					$sgbgBackup->deleteBackupFromStorage($id, $remove['name']);

				}
			}

		}
	}

}
