<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once SG_CORE_PATH . 'backup/SGBackup.php';


class SGBGChunks {

	public function run_chunk() {

		$SGBackup = new SGBackup();
		$SGBGStateJson = new SGBGStateJson();
		$task = new SGBGTask();

		$actions = $SGBackup->getRunningActions();

		if (!count($actions)) return;

		foreach ($actions as $action) {

			$offsetAll = SG_BACKUP_DIRECTORY . $action['name'] . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_FILES;
			$db_lock = SG_BACKUP_DIRECTORY . $action['name'] . DIRECTORY_SEPARATOR . SG_BACKUP_DB_LOCK;

			if (!file_exists($offsetAll) && !file_exists($db_lock)) die(1);

			$state_backup = SG_BACKUP_DIRECTORY . $action['name'] . DIRECTORY_SEPARATOR . SG_STATE_FILE_NAME;
			$task->prepare($state_backup);
			$stateFile = $task->getStateFile();
			$stateFile->setData('last_reload_ts', time());
			$stateFile->save();

			$options = $SGBGStateJson->DoJson('json_decode', $action['options']);
			$SGBackup->backup($options);


		} // foreach
	}


}