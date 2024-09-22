<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(SG_RESTORE_PATH . 'SGExternalRestore.php');
require_once(SG_LIB_PATH . 'SGMysqldump.php');
require_once(SG_LIB_PATH . 'SGCharsetHandler.php');
@include_once(SG_LIB_PATH . 'SGMigrate.php');
require_once(SG_BACKUP_PATH . 'SGBackupStorage.php');
@include_once(SG_BACKUP_PATH . 'SGBackupMailNotification.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGArchive.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGLog.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGTask.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGStateFile.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGOffsetFile.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGArchiveHelper.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGDirectoryTreeFile.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBLock.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'SGBGStateJson.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'Log.php');
require_once(SG_LIB_PATH . 'BackupGuard'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'RemoteCleanup.php');

class SGBackup implements ISGArchiveDelegate, SGIMysqldumpDelegate
{
	private $_backupFilePath = null;
	private $_actionId = null;
	private $_filesBackupAvailable = false;
	private $_databaseBackupAvailable = false;
	private $_isManual = true;
	private $_actionStartTs = 0;
	private $_fileName = '';
	private $_filesBackupPath = '';
	private $_databaseBackupPath = '';
	private $_backgroundMode = false;
	private $_pendingStorageUploads = array();
	private $_token = '';
	private $_options = array();
	private $_excludeFilePaths = array();
	private $cacheSize = 16 * 1024 * 1024;
	private $_cacheTimeOut = 10;
	private $_filesCount;
	private $_rowsCount;
	private $_stateFile;
	private $_treeFile;
	private $_logFile;
	private $_archive;
	private $_sgdb;
	private $_logEnabled = true;
	private $_totalRowCount = 0;
	private $_progressUpdateInterval;
	private $_currentUploadChunksCount = 0;
	private $_totalUploadChunksCount = 0;
	private $_nextProgressUpdate = 0;
	private $_migrationAvailable = null;
	private $_backedUpTables = null;
	private $_newTableNames = null;
	private $_oldDbPrefix = null;
	private $_migrateObj = null;
	private $_charsetHandler = null;
	private $_databaseBackupOldPath = null;
	private $_restoreMode = SG_RESTORE_MODE_FULL;
	private $_currentRowCount;
	private $offsetFile;
	private $processID = '';
	private $_backupLogPath;
	private $_warningsFound;

	public function __construct()
	{
		$this->_progressUpdateInterval = SGConfig::get('SG_ACTION_PROGRESS_UPDATE_INTERVAL');
		$this->_sgdb = SGDatabase::getInstance();
	}

	public function getFilesCount()
	{
		return $this->_filesCount;
	}

	public function setFilesCount($filesCount)
	{
		$this->_filesCount = $filesCount;
	}

	public function setRowsCount($rowsCount)
	{
		$this->_rowsCount = $rowsCount;
	}

	public function getStateFile()
	{
		return $this->_stateFile;
	}

	public function setStateFile($stateFile)
	{
		$this->_stateFile = $stateFile;
	}

	public function setOffsetFile($offsetFile)
	{
		$this->offsetFile = $offsetFile;
	}

	public function getOffsetFile()
	{
		return $this->offsetFile;
	}

	public function getTreeFile()
	{
		return $this->_treeFile;
	}

	public function setTreeFile()
	{
		clearstatcache();
		$this->_treeFile = new SGBGDirectoryTreeFile(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_FILES);

		if (!empty($this->_options['SG_BACKUP_FILE_PATHS'])) {
			$addPaths = $this->_options['SG_BACKUP_FILE_PATHS'];
			$this->_treeFile->setAddedFilePaths(explode(',', $addPaths));
		} else {
			$this->_treeFile->setAddedFilePaths(array());
		}

		if (!empty($this->_options['SG_BACKUP_FILE_PATHS_EXCLUDE'])) {
			$excludePaths = $this->_options['SG_BACKUP_FILE_PATHS_EXCLUDE'];
			$userCustomExcludes = SGConfig::get('SG_PATHS_TO_EXCLUDE');
			if (!empty($userCustomExcludes)) {
				$excludePaths .= ',' . $userCustomExcludes;
			}
			$this->_treeFile->setExcludedFilePaths(explode(',', $excludePaths));
		} else {
			$this->_treeFile->setExcludedFilePaths(array());
		}
	}

	public function getLogFile()
	{
		return $this->_logFile;
	}

	public function setLogFile($logFilePath)
	{
		$this->_logFile = $logFilePath;
	}

	public function getCache() {} // backward compatibility


	public function log($logData, $forceWrite = false)
	{
		$Log = new Log($this->getLogFile());
		$Log->write($logData);
	}

	public function logException($exception, $forceWrite = false)
	{
		$logData = $exception . ': ' . $exception->getMessage() . ' ';
		$logData .= '[File: ' . $exception->getFile() . ', Line: ' . $exception->getLine() . ']';
		$this->log($logData);
	}

	public function setLogEnabled($logEnabled)
	{
		$this->_logEnabled = $logEnabled;
	}

	public function getLogEnabled(): bool
	{
		return $this->_logEnabled;
	}

	public function getArchive()
	{
		return $this->_archive;
	}

	public function setArchive($task, $logEnabled)
	{
		$this->_archive = new SGBGArchive(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . $this->_fileName . '.sgbp');
		$this->_archive->setDelegate($this);
		$this->_archive->setTask($task);
		$this->_archive->setLogEnabled($logEnabled);
		$this->_archive->setLogFile($this->getLogFile());
		$this->_archive->getCache()->setCacheMode(SGBGCache::CACHE_MODE_TIMEOUT | SGBGCache::CACHE_MODE_SIZE);
		$this->_archive->getCache()->setCacheTimeout($this->_cacheTimeOut);
		$this->_archive->getCache()->setCacheSize($this->cacheSize);
		if (!empty($this->_options['SG_BACKUP_FILE_PATHS_EXCLUDE']) && strlen($this->_options['SG_BACKUP_FILE_PATHS_EXCLUDE'])) {
			$excludePaths = $this->_options['SG_BACKUP_FILE_PATHS_EXCLUDE'];
			$userCustomExcludes = SGConfig::get('SG_PATHS_TO_EXCLUDE');
			if (!empty($userCustomExcludes)) {
				$excludePaths .= ',' . $userCustomExcludes;
			}
			$this->_archive->setExcludePaths(explode(',', $excludePaths));
		} else {
			$this->_archive->setExcludePaths(array());
		}
		$this->_archive->setOptions($this->_options);
	}

	public function setFileName($name)
	{
		$this->_fileName = $name;
	}

	public function getFileName()
	{
		return $this->_fileName;
	}

	public function setExcludeFilePaths($paths)
	{
		$this->_excludeFilePaths = $paths;
	}

	public function getExcludeFilePaths()
	{
		return $this->_excludeFilePaths;
	}

	public function setOptions($options)
	{
		$this->_options = $options;

		$this->_filesBackupAvailable = $options['SG_ACTION_BACKUP_FILES_AVAILABLE'] ?? false;
		$this->_databaseBackupAvailable = $options['SG_ACTION_BACKUP_DATABASE_AVAILABLE'] ?? false;
		$this->_backgroundMode = $options['SG_BACKUP_IN_BACKGROUND_MODE'] ?? false;
		if (!empty($options['SG_BACKUP_UPLOAD_TO_STORAGES'])) {
			$this->_pendingStorageUploads = explode(',', $options['SG_BACKUP_UPLOAD_TO_STORAGES']);
		}
	}

	public function getOptions()
	{
		return $this->_options;
	}

	public function getScheduleParamsById($id)
	{
		$sgdb = SGDatabase::getInstance();
		$res = $sgdb->query('SELECT * FROM ' . SG_SCHEDULE_TABLE_NAME . ' WHERE id=%d', array($id));
		if (empty($res)) {
			return '';
		}

		return $res[0];
	}

	public function listStorage($storage)
	{
		if (SGBoot::isFeatureAvailable('DOWNLOAD_FROM_CLOUD')) {
			return SGBackupStorage::getInstance()->listStorage($storage);
		}

		return array();
	}

	public function downloadBackupArchiveFromCloud($archive, $storage, $size, $backupId = null)
	{
		$result = false;
		if (SGBoot::isFeatureAvailable('DOWNLOAD_FROM_CLOUD')) {
			$result = SGBackupStorage::getInstance()->downloadBackupArchiveFromCloud($storage, $archive, $size, $backupId);
		}

		return $result;
	}

	public function getToken()
	{
		return $this->_token;
	}

	private function cleanUpDirectoryState()
	{
		if (file_exists(SG_BACKUP_DIRECTORY . JBWP_DIRECTORY_STATE_FILE_NAME)) {
			unlink(SG_BACKUP_DIRECTORY . JBWP_DIRECTORY_STATE_FILE_NAME);
		}
	}

	private function setCronJobForReloading()
	{
		wp_schedule_event(time() + JBWP_CRON_RELOAD_INTERVAL, 'sixty_seconds', JBWP_RELOAD_SCHEDULE_ACTION);
	}

	private function removeCronJobForReloading()
	{
		wp_clear_scheduled_hook(JBWP_RELOAD_SCHEDULE_ACTION);
	}

	public function lock($action = '')
	{
		$lockOptions = [
			'lock_time' => time(),
			'lock_interval' => 60
		];
		if ($action) {
			$lockOptions['action_name'] = $action;
		}
		update_option('_jetAppsBackupLock', $lockOptions);
	}

	public function verify_pid()
	{
		$pid_file = SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_PROCESS_ID_FILE;
		if (!file_exists($pid_file)) return;

		$pid = file_get_contents($pid_file);

		if ($pid != $this->processID) {
			$this->log('process killed: ' . $this->processID, true);
			die;
		}
	}

	public function set_process_id()
	{
		$this->processID = md5(time());
		file_put_contents(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_PROCESS_ID_FILE, $this->processID);
		return $this->processID;
	}

	/**
	 * @throws SGExceptionMethodNotAllowed
	 * @throws SGExceptionForbidden
	 * @throws SGExceptionDatabaseError
	 */
	private function setActions($options = null)
	{
		$actions = self::getRunningActions();
		if (!isset($actions[0]) && $options) $this->noActions($options);

		$actions = self::getRunningActions();
		return $actions[0] ?? null;
	}

	/**
	 * @throws SGExceptionMethodNotAllowed
	 * @throws SGExceptionForbidden
	 * @throws SGExceptionDatabaseError
	 */
	private function noActions($options)
	{
		$this->setOptions($options);
		$this->_fileName = $this->getBackupFileName();
		$this->_token = backupGuardGenerateToken();
		$this->cleanUpDirectoryState();
		$this->prepareForBackup();
		// in case if previous backup cron was not cleared
		$this->removeCronJobForReloading();
	}

	private function getServerLoad()
	{
		if (function_exists('sys_getloadavg') && sys_getloadavg() !== false) {
			$load = sys_getloadavg();
			return $load[0] ?? null;
		}

		return null;
	}

	/**
	 * @throws SGExceptionDatabaseError
	 * @throws SGException
	 */
	private function pushDataBaseBackup($action, $task)
	{
		if (!isset($action['status'])) return;
		if ($action['status'] != SG_ACTION_STATUS_IN_PROGRESS_DB) return;

		$this->_fileName = $action['name'];
		$this->_actionId = $action['id'];

		$db_lock = SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_DB_LOCK;
		file_put_contents($db_lock, 1);

		$this->resetBackupProgress();
		$this->setRowsCount($this->_totalRowCount);
		$task->start($this->_totalRowCount);
		$task->getStateFile()->setAction(SG_STATE_ACTION_PREPARING_STATE_FILE);
		$task->getStateFile()->setOffset(0);
		$task->getStateFile()->setAction(SG_STATE_ACTION_PREPARING_STATE_FILE);
		$task->getStateFile()->setType(SG_STATE_TYPE_DB);
		$task->getStateFile()->setActionId($this->_actionId);
		$task->getStateFile()->setStartTs($this->_actionStartTs);
		$task->getStateFile()->setBackupFileName($this->_fileName);
		$task->getStateFile()->setPendingStorageUploads($this->_pendingStorageUploads);
		$task->getStateFile()->setBackedUpTables(array());
		$tablesToBackup = empty($this->_options['SG_BACKUP_TABLES_TO_BACKUP']) ? [] : $this->_options['SG_BACKUP_TABLES_TO_BACKUP'];
		$task->getStateFile()->setTablesToBackup($tablesToBackup);
		$this->startBackupDB($task);
		$task->getStateFile()->setCount(0);
		$task->getStateFile()->setOffset(0);
		$task->end(false);
		$this->setFilesCount(0);
		@unlink($db_lock);

		self::changeActionStatus($this->_actionId, SG_ACTION_STATUS_IN_PROGRESS_FILES);
	}

	/* Backup implementation */
	public function backup($options, $logEnabled = false)
	{
		$task = new SGBGTask();
		$CronTab = new CronTab();

		$this->setLogEnabled($logEnabled);
		try {

			prepareBackupDir();

			$CronTab->init();

			$_cron_lock = SG_BACKUP_DIRECTORY . 'cron.lock';
			if (!SGBLock::LockFile($_cron_lock)) return;

			$this->clearCache();
			$action = $this->setActions($options);

			//if (!$action) throw new SGExceptionNotFound ('Actions for backup are empty');
			$this->_fileName = $action['name'];
			$this->_actionId = $action['id'];
			$_tree_done = SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_GENERATOR_DONE;
			$_fork_lock = SG_BACKUP_DIRECTORY . 'fork.lock';
			$db_lock = SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_DB_LOCK;

			$_tree_file_count = backupGuardRemoveSlashes(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_FILE_COUNT);
			$_process_id_file = backupGuardRemoveSlashes(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_PROCESS_ID_FILE);
			$_offset_all_file = backupGuardRemoveSlashes(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_OFFSET_ALL_POS_FILE);
			$_dir_offset_file = backupGuardRemoveSlashes(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_DIR_OFFSET);
			$_action_id_file = backupGuardRemoveSlashes(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_ACTION_ID_FILE);
			$_tree_done_file = backupGuardRemoveSlashes(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_GENERATOR_DONE);
			$_tree_files_file = backupGuardRemoveSlashes(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_FILES);


			@file_put_contents(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . '.htaccess', 'deny from all');
			@file_put_contents(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . 'index.php', "<?php\n// Silence is golden");
			@file_put_contents(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . 'index.html', "");

			if (file_exists($_fork_lock)) unlink($_fork_lock);

			$options = json_decode($action['options'], 1);
			$this->setOptions($options);
			$this->setLogFile(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . $this->_fileName . '_backup.log');
			$this->setBackupPaths();

			$task->prepare(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_STATE_FILE_NAME);
			$this->setStateFile($task->getStateFile());
			$this->setFilesCount($this->getStateFile()->getCount());
			$this->set_process_id();
			if(!file_exists($db_lock)) $this->pushDataBaseBackup($action, $task);
			$this->setTreeFile();

			if (isset($action['type']) && $action['type'] == SG_ACTION_TYPE_BACKUP) {
				$this->log('Inside ->backup() | action type: ' . $action['type'], true);
				$this->getStateFile()->setPendingStorageUploads($this->_pendingStorageUploads);

				if (!file_exists($_tree_done)) {
					// We don't have tree file yet
					$this->log('Generating Tree...', true);
					self::changeActionStatus($this->_actionId, SG_ACTION_STATUS_TREE);

					$this->getTreeFile()->getCache()->setCacheMode(SGBGCache::CACHE_MODE_TIMEOUT | SGBGCache::CACHE_MODE_SIZE);
					$this->getTreeFile()->getCache()->setCacheTimeout($this->_cacheTimeOut);
					$this->getTreeFile()->getCache()->setCacheSize($this->cacheSize);
					$this->getTreeFile()->setRootPath(rtrim(SGConfig::get('SG_APP_ROOT_DIRECTORY'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
					if ($this->_databaseBackupAvailable) $this->getTreeFile()->addDontExclude($this->_databaseBackupPath);
					$this->getTreeFile()->save($this);
					//$this->setFilesCount();
				}

				if (file_exists($_tree_done)) {
					// Tree generation is DONE, we can now backup file based on the tree
					$treeLines = (int)file_get_contents($_tree_done);
					self::changeActionStatus($this->_actionId, SG_ACTION_STATUS_IN_PROGRESS_FILES);

					$this->log('Root path ' . $this->_filesBackupPath, true);
					$this->log('Number of files to backup ' . $this->getFilesCount(), true);
					$this->log('Start backup ' . $treeLines .  ' files ', true);

					$task->prepareOffsetFile(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_OFFSET_ALL_POS_FILE);
					$this->setOffsetFile($task->getOffsetFile());

					$this->setArchive($task, $logEnabled);
					$this->getArchive()->open('w');
					$task->start($this->getFilesCount());
					$this->startBackupFiles($task, $treeLines);
					$this->getArchive()->finalize();

					$this->log('offset: ' . $this->getStateFile()->getOffset() . ' Files Count: ' . $this->getFilesCount(), true);
					$this->didFinishBackup();

					$task->end(false);
				}
			}

			$this->backupUploadToStorages();
			SGBLock::UnlockFile($_cron_lock);

			@unlink ($_offset_all_file);
			@unlink ($_tree_file_count);
			@unlink ($_process_id_file);
			@unlink ($_dir_offset_file);
			@unlink ($_action_id_file);
			@unlink ($_tree_done_file);
			@unlink ($_tree_files_file);


		} catch (Exception $e) {

			if (SGBoot::isFeatureAvailable('NOTIFICATIONS')) {
				//Writing backup status to report file

				@file_put_contents(dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Backup failed' . "\n", FILE_APPEND);
				@file_put_contents(dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Error captured - ' . $e->getMessage(), FILE_APPEND);

				SGBackupMailNotification::sendBackupNotification(
					SG_ACTION_STATUS_ERROR,
					array(
						'flowFilePath' => dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME,
						'archiveName' => $this->_fileName
					)
				);
			}

			self::changeActionStatus($this->_actionId, SG_ACTION_STATUS_ERROR);
			$this->removeCronJobForReloading();

			@unlink ($_offset_all_file);
			@unlink ($_tree_file_count);
			@unlink ($_process_id_file);
			@unlink ($_dir_offset_file);
			@unlink ($_action_id_file);
			@unlink ($_tree_done_file);
			@unlink ($_tree_files_file);


		}
	}

	private function prepareBackupFolder($backupPath)
	{
		if (!is_writable(SG_BACKUP_DIRECTORY)) {
			throw new SGExceptionForbidden('Permission denied. Directory is not writable: ' . $backupPath);
		}

		//create backup folder
		if (!file_exists($backupPath) && !@mkdir($backupPath)) {
			throw new SGExceptionMethodNotAllowed('Cannot create folder: ' . $backupPath);
		}

		if (!is_writable($backupPath)) {
			throw new SGExceptionForbidden('Permission denied. Directory is not writable: ' . $backupPath);
		}

		//create backup log file
		$this->prepareBackupLogFile($backupPath);
	}

	public function clearCache()
	{
		if (function_exists('opcache_reset')) @opcache_reset();
	}

	private function getLastOffset(): int
	{
		$subdata = $this->getOffsetFile()->read_file(2);
		return (int)$subdata[0] ?? 0;
	}

	/**
	 * @throws SGExceptionForbidden
	 * @throws SGExceptionDatabaseError
	 * @throws SGExceptionMethodNotAllowed
	 */
	private function prepareForBackup()
	{
		$this->clearCache();
		$this->prepareBackupFolder(SG_BACKUP_DIRECTORY . $this->_fileName);
		$this->setLogFile(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . $this->_fileName . '_backup.log');

		if (file_exists(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_ACTION_ID_FILE)) return;

		//start logging
		$this->log('Start backup', true);

		//save timestamp for future use
		$this->_actionStartTs = time();

		//create action inside db
		$status = $this->_databaseBackupAvailable ? SG_ACTION_STATUS_IN_PROGRESS_DB : SG_ACTION_STATUS_IN_PROGRESS_FILES;
		$this->_actionId = self::createAction($this->_fileName, SG_ACTION_TYPE_BACKUP, $status, 0, json_encode($this->_options));

		//set paths
		$this->setBackupPaths();

		//additional configuration
		$this->prepareAdditionalConfigurations();

		//check if upload to storages is needed
		$this->prepareUploadToStorages($this->_options);
	}

	public function GetBackupFolderName()
	{
		return $this->_fileName;
	}

	private function startBackupFiles($task, $TreeLines)
	{
		$this->clearCache();
		$fileOffsetItem = $this->getLastOffset();
		//$_current_file_count = SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_FILE_COUNT;
		//$TreeLines = file_exists($_current_file_count) ? (int) file_get_contents($_current_file_count) : 0;

		$this->log('Treelines ' . $TreeLines, true);
		$this->log('offset string ' . $fileOffsetItem, true);

		for ($i = $fileOffsetItem; $i <= $TreeLines; $i++) {

			$this->log('Tree Lines:  ' . $i, true);
			$this->verify_pid();
			if ($this->getStateFile()->getData('is_resume')) $i++;
			$file = $this->getTreeFile()->getFileAtIndex($i);
			$relativePath = $this->pathWithoutRootDirectory($file);


			if (substr($file, -1) != DIRECTORY_SEPARATOR) {

				$this->getArchive()->addFileFromPath($relativePath, $file, $i);

			} else {

				if (SGBGArchiveHelper::is_dir_empty($relativePath)) {
					$this->getArchive()->addEmptyDirectory($relativePath);
				}

			}

			if ($i % 500 == 0 && $TreeLines != 0) { // prevent DivisionByZeroError

				$percent = intval($i / $TreeLines * 100);
				self::changeActionProgress($this->_actionId, $percent);

			}

			$this->getOffsetFile()->add_offset(intval($i + 1) . "\n");
			$task->endChunk($i + 1);

		}
	}

	private function didFinishBackup()
	{
		if (SGConfig::get('SG_REVIEW_POPUP_STATE') != SG_NEVER_SHOW_REVIEW_POPUP) {
			SGConfig::set('SG_REVIEW_POPUP_STATE', SG_SHOW_REVIEW_POPUP);
		}

		$action = $this->didFindWarnings() ? SG_ACTION_STATUS_FINISHED_WARNINGS : SG_ACTION_STATUS_FINISHED;
		self::changeActionStatus($this->_actionId, $action);

		$report = $this->didFindWarnings() ? 'completed with warnings' : 'completed';

		//Writing backup status to report file
		file_put_contents(dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Backup: ' . $report . "\n", FILE_APPEND);

		if (SGBoot::isFeatureAvailable('NOTIFICATIONS') && !count($this->_pendingStorageUploads)) {
			SGBackupMailNotification::sendBackupNotification(
				$action,
				array(
					'flowFilePath' => dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME,
					'archiveName' => $this->_fileName
				)
			);
		}

		$this->log('End backup files', true);
		$this->log('Backup ' . $report, true);
		$this->log('Total duration ' . backupGuardFormattedDuration($this->getStateFile()->getStartTs(), time()), true);
		$this->log('Memory peak usage ' . (memory_get_peak_usage(true) / 1024 / 1024) . 'MB', true);
		if (function_exists('sys_getloadavg') && sys_getloadavg() !== false) {
			$this->log('CPU usage ' . implode(' / ', sys_getloadavg()), true);
		}

		$archiveSizeInBytes = backupGuardRealFilesize($this->_filesBackupPath);
		$archiveSize = convertToReadableSize($archiveSizeInBytes);
		$this->log("Archive size " . $archiveSize . " (" . $archiveSizeInBytes . " bytes)", true);

		$this->cleanUp();
	}

	private function pathWithoutRootDirectory($path)
	{
		return substr($path, strlen(rtrim(SGConfig::get('SG_APP_ROOT_DIRECTORY'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
	}

	/**
	 * @throws SGExceptionDatabaseError
	 * @throws SGException
	 * @throws Exception
	 */
	private function startBackupDB($task)
	{
		$this->clearCache();
		$this->log('Start backup database', true);
		$this->log('Total tables to backup ' . count($this->getTables()), true);
		$this->log('Total rows to backup ' . $this->_totalRowCount, true);

		$actionStartTs = $task->getStateFile()->getStartTs();
		$customTablesToExclude = !empty(SGConfig::get('SG_TABLES_TO_EXCLUDE')) ? ',' . str_replace(' ', '', SGConfig::get('SG_TABLES_TO_EXCLUDE')) : '';
		$tablesToExclude = explode(',', SGConfig::get('SG_BACKUP_DATABASE_EXCLUDE') . $customTablesToExclude);
		$tablesToBackup = $task->getStateFile()->getTablesToBackup() ? explode(',', $task->getStateFile()->getTablesToBackup()) : array();

		$dump = new SGMysqldump(
			SGDatabase::getInstance(),
			SG_DB_NAME,
			'mysql',
			array(
				'exclude-tables' => $tablesToExclude,
				'include-tables' => $tablesToBackup,
				'skip-dump-date' => true,
				'skip-comments' => true,
				'skip-tz-utz' => true,
				'add-drop-table' => true,
				'no-autocommit' => false,
				'single-transaction' => false,
				'lock-tables' => false,
				'default-character-set' => SG_DB_CHARSET,
				'add-locks' => false
			)
		);
		$dump->setDelegate($this);
		$this->setLogFile(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . $this->_fileName . '_backup.log');
		$dump->start($this->_databaseBackupPath, $task);

		$this->log('End backup database', true);
		$this->log('Backup database total duration ' . backupGuardFormattedDuration($actionStartTs, time()), true);
	}

	private function prepareBackupReport()
	{
		file_put_contents(dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Report for: ' . SG_SITE_URL . "\n", FILE_APPEND);
	}

	private function shouldDeleteBackupAfterUpload()
	{
		return SGConfig::get('SG_DELETE_BACKUP_AFTER_UPLOAD') ? true : false;
	}

	private function backupUploadToStorages()
	{
		//check list of storages to upload if any
		$uploadToStorages = count($this->_pendingStorageUploads) ? true : false;

		if (SGBoot::isFeatureAvailable('STORAGE') && $uploadToStorages) {

			while (count($this->_pendingStorageUploads) > 0) {

				$task = new SGBGTask();
				$task->prepare(SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . 'state_upload.json');

				if (!empty($task->getStateFile()->getPendingStorageUploads())) {
					$this->_pendingStorageUploads = $task->getStateFile()->getPendingStorageUploads();
				}

				$sgBackupStorage = SGBackupStorage::getInstance();
				$storageId = $this->_pendingStorageUploads[0];
				$storageInfo = $sgBackupStorage->getStorageInfoById($storageId);

				if (empty($storageInfo['isConnected'])) {
					$this->log($storageInfo['storageName'] . ' stopped', true);
					array_shift($this->_pendingStorageUploads);
					continue;
				}

				$actions = self::getRunningActions();

				if ($storageId == 0) {
					return;
				}


				if (!count($actions)) {

					$this->_actionId = SGBackupStorage::queueBackupForUpload($this->_fileName, $storageId, $this->_options);
				} else {

					$this->_actionId = $actions[0]['id'];
				}

				$this->startUploadByActionId($task, $this->_actionId);
				array_shift($this->_pendingStorageUploads);
				$task->getStateFile()->setPendingStorageUploads($this->_pendingStorageUploads);
				$task->getStateFile()->save(true);
				$task->end(true);
			}

			$this->didFinishUpload();
			//$this->updateUploadProgress();
		}
	}

	private function didFinishUpload()
	{
		$this->log('Inside didFinishUpload', true);

		//check if option is enabled
		$isDeleteLocalBackupFeatureAvailable = SGBoot::isFeatureAvailable('DELETE_LOCAL_BACKUP_AFTER_UPLOAD');

		$this->log('isDeleteLocalBackupFeatureAvailable: ' . $isDeleteLocalBackupFeatureAvailable, true);

		if (SGBoot::isFeatureAvailable('NOTIFICATIONS')) {

			$this->log('Inside notifications: ' , true);

			SGBackupMailNotification::sendBackupNotification(
				SG_ACTION_STATUS_FINISHED,
				array(
					'flowFilePath' => dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME,
					'archiveName' => $this->_fileName
				)
			);
		}

		$status = SGBackup::getActionStatus($this->_actionId);

		$this->log('getActionStatus: ' . $status, true);

		if ($this->shouldDeleteBackupAfterUpload() && $isDeleteLocalBackupFeatureAvailable && $status == SG_ACTION_STATUS_FINISHED) {

			$this->log('Inside shouldDeleteBackupAfterUpload if', true);
			$this->log('File to be deleted: ' . SG_BACKUP_DIRECTORY . backupGuardRemoveSlashes($this->_fileName) . DIRECTORY_SEPARATOR . backupGuardRemoveSlashes($this->_fileName) . '.' . SGBP_EXT  , true);

			if (unlink(SG_BACKUP_DIRECTORY . backupGuardRemoveSlashes($this->_fileName) . DIRECTORY_SEPARATOR . backupGuardRemoveSlashes($this->_fileName) . '.' . SGBP_EXT)) {

				$this->log('Local file removed successfully', true);

			} else {

				$this->log('There was an error when trying to remove local file after upload', true);

			}
		}

		$this->log('Upload process completed', true);

		$RemoteCleanup = new RemoteCleanup();
		$RemoteCleanup->doCleanup(true);

		$this->log('Triggering retention cleanup', true);
	}

	private function clear()
	{
		@unlink(dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME);
		SGConfig::set("SG_CUSTOM_BACKUP_NAME", '');
	}

	private function cleanUp()
	{
		//delete sql file
		if ($this->_databaseBackupAvailable && file_exists($this->_databaseBackupPath)) {
			unlink($this->_databaseBackupPath);
		}

		$this->cleanUpDirectoryState();

	}

	private function getBackupFileName()
	{

		$backupName = SGConfig::get("SG_CUSTOM_BACKUP_NAME") ? backupGuardRemoveSlashes(SGConfig::get("SG_CUSTOM_BACKUP_NAME")) : null;
		if ($backupName && !file_exists(SG_BACKUP_DIRECTORY . $backupName)) return $backupName;

		$sgBackupPrefix = SG_BACKUP_FILE_NAME_DEFAULT_PREFIX;
		if (function_exists('backupGuardGetCustomPrefix')) {
			$sgBackupPrefix = backupGuardGetCustomPrefix();
		}

		$sgBackupPrefix .= backupGuardGetFilenameOptions($this->_options);

		$date = backupGuardConvertDateTimezone(@date('YmdHis'), true, 'YmdHis');
		$hash = md5(uniqid(rand(), true));
		return $sgBackupPrefix . '-' . ($date) .'-' . $hash;

	}

	private function extendLogFileHeader($content)
	{

		$mode = $this->getIsManual() ? 'Manual' : 'Schedule';
		$content .= 'Backup mode: ' . $mode . ' ' . PHP_EOL;

		return $content;
	}

	private function prepareBackupLogFile($backupPath)
	{
		$file = $backupPath . DIRECTORY_SEPARATOR . $this->_fileName . '_backup.log';
		$this->_backupLogPath = $file;

		$isUpload = $this->getIsUploadStorage();

		$content = self::getLogFileHeader(SG_ACTION_TYPE_BACKUP, $this->_fileName, $isUpload);
		$content = $this->extendLogFileHeader($content);

		$types = array();
		if ($this->_filesBackupAvailable) {
			$types[] = 'files';
		}
		if ($this->_databaseBackupAvailable) {
			$types[] = 'database';
		}
		if (function_exists('sys_getloadavg') && sys_getloadavg() !== false) {
			$content .= 'CPU load at backup start: ' . implode(' / ', sys_getloadavg()) . PHP_EOL;
		}

		$content .= 'Backup type: ' . implode(',', $types) . PHP_EOL . PHP_EOL;

		if (!file_put_contents($file, $content)) {
			throw new SGExceptionMethodNotAllowed('Cannot create backup log file: ' . $file);
		}
	}

	private function setBackupPaths()
	{
		$this->_filesBackupPath = SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . $this->_fileName . '.sgbp';
		$this->_databaseBackupPath = SG_BACKUP_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . $this->_fileName . '.sql';
	}

	private function prepareUploadToStorages($options)
	{
		$uploadToStorages = $options['SG_BACKUP_UPLOAD_TO_STORAGES'];

		if (SGBoot::isFeatureAvailable('STORAGE') && $uploadToStorages) {
			$this->_pendingStorageUploads = explode(',', $uploadToStorages);
		}
	}

	private function prepareAdditionalConfigurations()
	{
		SGConfig::set('SG_RUNNING_ACTION', 1, true);
	}

	public function cancel()
	{
		$dir = SG_BACKUP_DIRECTORY . $this->_fileName;

		if (SGBoot::isFeatureAvailable('NOTIFICATIONS')) {
			//Writing backup status to report file
			file_put_contents($dir . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Backup: canceled', FILE_APPEND);
			SGBackupMailNotification::sendBackupNotification(
				SG_ACTION_STATUS_CANCELLED,
				array(
					'flowFilePath' => dirname($this->_filesBackupPath) . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME,
					'archiveName' => $this->_fileName
				)
			);
		}

		if ($dir != SG_BACKUP_DIRECTORY) {
			backupGuardDeleteDirectory($dir);
		}

		$this->clear();
		throw new SGExceptionSkip();
	}

	public function handleMigrationErrors($exception)
	{
		SGConfig::set('SG_BACKUP_SHOW_MIGRATION_ERROR', 1);
		SGConfig::set('SG_BACKUP_MIGRATION_ERROR', (string)$exception);
	}

	public function getActionId()
	{
		return $this->_actionId;
	}

	public function extract($backupName, $id = null)
	{
		try {
			$this->clearCache();
			$backupName = backupGuardRemoveSlashes($backupName);
			$task = new SGBGTask();
			$task->prepare(SG_BACKUP_DIRECTORY . $backupName . DIRECTORY_SEPARATOR . SG_RESTORE_STATE_FILE_NAME);
			$stateFile = $task->getStateFile();
			$this->setStateFile($stateFile);
			$this->_fileName = $backupName;

			if (
				$this->getCurrentActionStatus() == SG_ACTION_STATUS_IN_PROGRESS_DB
				|| $stateFile->getType() == SG_STATE_TYPE_DB
			) {
				die('busy');
			}

			if ($stateFile->getStatus() == SGBGStateFile::STATUS_READY) {
				$this->prepareForRestore($backupName, $task, $id);
//                if (SGExternalRestore::isEnabled()) {
//                    $this->log('Start maintenance mode', true);
//                }
				$this->log('Start extract', true);

				$stateFile->setBackupFileName($this->_fileName);
				$stateFile->setBackedUpTables(array());
				$stateFile->setAction(SG_STATE_ACTION_RESTORING_FILES);
				$stateFile->setType(SG_STATE_TYPE_FILE);
				$stateFile->setActionId($this->_actionId);
				$stateFile->setStartTs($this->_actionStartTs);
				$stateFile->setOffset(0);
				$stateFile->save(true);
			} else if ($stateFile->getStatus() == SGBGStateFile::STATUS_RESUME) {
				$restorePath = SG_BACKUP_DIRECTORY . $this->_fileName;
				$this->_filesBackupPath = $restorePath . DIRECTORY_SEPARATOR . $this->_fileName . '.sgbp';
				$this->_databaseBackupPath = $restorePath . DIRECTORY_SEPARATOR . $this->_fileName . '.sql';
				$this->_databaseBackupOldPath = SG_BACKUP_OLD_DIRECTORY . $this->_fileName . DIRECTORY_SEPARATOR . $this->_fileName . '.sql';
				$this->prepareRestoreLogFile($restorePath, true);
				$this->_actionId = $stateFile->getActionId();
				$this->_actionStartTs = $stateFile->getStartTs();

				$this->log('Resume restore', true);
			} else {
				die('busy');
			}

			$this->backupExtractArchive($this->_filesBackupPath, $task);
			$this->getLogFile()->getCache()->flush();
			$task->end();
		} catch (SGException $exception) {
			if (!$exception instanceof SGExceptionSkip) {
				$this->logException($exception, true);

				if ($exception instanceof SGExceptionMigrationError) {
					$this->handleMigrationErrors($exception);
				}

				if (SGBoot::isFeatureAvailable('NOTIFICATIONS')) {
					SGBackupMailNotification::sendRestoreNotification(false);
				}

				self::changeActionStatus($this->_actionId, SG_ACTION_STATUS_FINISHED_WARNINGS);
				$this->getStateFile()->setStatus(SGBGStateFile::STATUS_WARNINGS);
			} else {
				self::changeActionStatus($this->_actionId, SG_ACTION_STATUS_CANCELLED);
			}
		}
	}

	public function scanBackupsFolderForSqlFile($backupFolderPath)
	{
		try {
			$directory = new \RecursiveDirectoryIterator(
				$backupFolderPath,
				FilesystemIterator::FOLLOW_SYMLINKS | FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
			);
		} catch (Throwable $e) {
			return null;
		}

		$iterator = new \RecursiveIteratorIterator(
			$directory,
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		$iterator = new \LimitIterator($iterator, 0);
		foreach ($iterator as $info) {
			if (strpos($info->getFilename(), '.sql')) {
				return $info->getPathname();
			}
		}

		return null;
	}

	/* General methods */
	public static function getLogFileHeader($actionType, $fileName, $isUpload = false)
	{
		$pluginCapabilities = backupGuardGetCapabilities();
		$timezone = SGConfig::get('SG_TIMEZONE') ?: SG_DEFAULT_TIMEZONE;

		$confs = array();
		$confs['sg_backup_guard_version'] = SG_BACKUP_GUARD_VERSION;
		$confs['sg_archive_version'] = SG_ARCHIVE_VERSION;
		$confs['sg_user_mode'] = ($pluginCapabilities != BACKUP_GUARD_CAPABILITIES_FREE) ? 'pro' : 'free'; // Check if user is pro or free
		$confs['os'] = PHP_OS;
		$confs['php_version'] = PHP_VERSION;
		$confs['sapi'] = PHP_SAPI;
		$confs['mysql_version'] = SG_MYSQL_VERSION;
		$confs['int_size'] = PHP_INT_SIZE;
		$confs['method'] = backupGuardIsReloadEnabled() ? 'ON' : 'OFF';
		$confs['dbprefix'] = SG_ENV_DB_PREFIX;
		$confs['siteurl'] = SG_SITE_URL;
		$confs['homeurl'] = SG_HOME_URL;
		$confs['uploadspath'] = SG_UPLOAD_PATH;
		$confs['installation'] = SG_SITE_TYPE;
		$freeSpace = backupGuardDiskFreeSize(SG_APP_ROOT_DIRECTORY);
		$confs['free_space'] = $freeSpace == false ? 'unknown' : $freeSpace;
		$isCurlAvailable = function_exists('curl_version');
		$confs['curl_available'] = $isCurlAvailable ? 'Yes' : 'No';
		$confs['email_notifications'] = SGConfig::get('SG_NOTIFICATIONS_ENABLED') ? 'ON' : 'OFF';
		$confs['ftp_passive_mode'] = SGConfig::get('SG_FTP_PASSIVE_MODE') ? 'ON' : 'OFF';

		if (extension_loaded('gmp')) {
			$lib = 'gmp';
		} else if (extension_loaded('bcmath')) {
			$lib = 'bcmath';
		} else {
			$lib = 'BigInteger';
		}

		$confs['int_lib'] = $lib;
		$confs['memory_limit'] = SGBoot::$memoryLimit;
		$confs['max_execution_time'] = SGBoot::$executionTimeLimit;
		$confs['env'] = SG_ENV_ADAPTER . ' ' . SG_ENV_VERSION;
		$content = 'Date: ' . backupGuardConvertDateTimezone(@date('Y-m-d H:i'), true) . ' ' . $timezone . PHP_EOL;
		$content .= 'Reloads: ' . $confs['method'] . PHP_EOL;

		if ($actionType == SG_ACTION_TYPE_RESTORE) {
			$confs['restore_method'] = SGExternalRestore::isEnabled() ? 'external' : 'standard';
			$content .= 'Restore Method: ' . $confs['restore_method'] . PHP_EOL;
		}

		$content .= 'User mode: ' . backupGuardGetProductName() . PHP_EOL;
		$content .= 'JetBackup version: ' . $confs['sg_backup_guard_version'] . PHP_EOL;
		$content .= 'Supported archive version: ' . $confs['sg_archive_version'] . PHP_EOL;
		$content .= 'Database prefix: ' . $confs['dbprefix'] . PHP_EOL;
		$content .= 'Site URL: ' . $confs['siteurl'] . PHP_EOL;
		$content .= 'Home URL: ' . $confs['homeurl'] . PHP_EOL;
		$content .= 'Uploads path: ' . $confs['uploadspath'] . PHP_EOL;
		$content .= 'Site installation: ' . $confs['installation'] . PHP_EOL;
		$content .= 'OS: ' . $confs['os'] . PHP_EOL;
		$content .= 'PHP version: ' . $confs['php_version'] . PHP_EOL;
		$content .= 'MySQL version: ' . $confs['mysql_version'] . PHP_EOL;
		$content .= 'Int size: ' . $confs['int_size'] . PHP_EOL;
		$content .= 'Int lib: ' . $confs['int_lib'] . PHP_EOL;
		$content .= 'Memory limit: ' . $confs['memory_limit'] . PHP_EOL;
		$content .= 'Max execution time: ' . $confs['max_execution_time'] . PHP_EOL;
		$content .= 'Disk free space: ' . $confs['free_space'] . PHP_EOL;
		$content .= 'CURL available: ' . $confs['curl_available'] . PHP_EOL;
		$content .= 'Openssl version: ' . OPENSSL_VERSION_TEXT . PHP_EOL;

		if ($isCurlAvailable) {
			$cv = curl_version();
			$curlVersionText = $cv['version'] . ' / SSL: ' . $cv['ssl_version'] . ' / libz: ' . $cv['libz_version'];
			$content .= 'CURL version: ' . $curlVersionText . PHP_EOL;
		}

		$content .= 'Email notifications: ' . $confs['email_notifications'] . PHP_EOL;
		$content .= 'FTP passive mode: ' . $confs['ftp_passive_mode'] . PHP_EOL;
		$content .= 'Exclude paths: ' . SGConfig::get('SG_PATHS_TO_EXCLUDE') . PHP_EOL;
		$content .= 'Tables to exclude: ' . SGConfig::get('SG_TABLES_TO_EXCLUDE') . PHP_EOL;
		$content .= 'Number of rows to backup: ' . (int)SGConfig::get('SG_BACKUP_DATABASE_INSERT_LIMIT') . PHP_EOL;
		$content .= 'AJAX request frequency: ' . SGConfig::get('SG_AJAX_REQUEST_FREQUENCY') . PHP_EOL;

		if ($actionType == SG_ACTION_TYPE_BACKUP && $isUpload) {
			$content .= 'Upload chunk size: ' . SGConfig::get('SG_BACKUP_CLOUD_UPLOAD_CHUNK_SIZE') . 'MB' . PHP_EOL;
		}

		if ($actionType == SG_ACTION_TYPE_RESTORE) {
			$archivePath = SG_BACKUP_DIRECTORY . $fileName . DIRECTORY_SEPARATOR . $fileName . '.sgbp';
			$archiveSizeInBytes = backupGuardRealFilesize($archivePath);
			$confs['archiveSize'] = convertToReadableSize($archiveSizeInBytes);
			$content .= 'Archive Size: ' . $confs['archiveSize'] . ' (' . $archiveSizeInBytes . ' bytes)' . PHP_EOL;
		}

		$content .= 'Environment: ' . $confs['env'] . PHP_EOL;

		return $content;
	}

	private function didFindWarnings()
	{
		$warningsDatabase = false;
		//$warningsDatabase = $this->_databaseBackupAvailable ? $this->_backupDatabase->didFindWarnings() : false;
		$warningsFiles = $this->getArchive()->didFindWarnings();

		return ($warningsFiles || $warningsDatabase);
	}

	/**
	 * @throws SGExceptionDatabaseError
	 */
	public static function createAction($name, $type, $status, $subtype = 0, $options = '')
	{
		$sgdb = SGDatabase::getInstance();

		$date = backupGuardConvertDateTimezone(@date('Y-m-d H:i:s'), true);
		$res = $sgdb->query('INSERT INTO ' . SG_ACTION_TABLE_NAME . ' (name, type, subtype, status, start_date, options) VALUES (%s, %d, %d, %d, %s, %s)', array(
			$name, $type, $subtype, $status, $date, $options
		));

		if (!$res) {
			throw new SGExceptionDatabaseError('Could not create action');
		}

		$lastInsertId = $sgdb->lastInsertId();
		file_put_contents( SG_BACKUP_DIRECTORY . $name . DIRECTORY_SEPARATOR . SG_BACKUP_ACTION_ID_FILE, $lastInsertId );

		return $lastInsertId;
	}

	private function getCurrentActionStatus()
	{
		return self::getActionStatus($this->_actionId);
	}

	private static function DoCancelCleanup($actionData)
	{
		$file = $actionData[0]['name'] ?? null;
		if (!$file) return;

		$dir = SG_BACKUP_DIRECTORY . $file;
		if (!is_dir($dir)) return;

		$it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
		$files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

		foreach ($files as $file) {

			if ($file->isDir()) {
				@rmdir($file->getRealPath());
			} else {
				unlink($file->getRealPath());
			}
		}

		@rmdir($dir);
	}

	private static function returnProgress($status): ?bool
	{
		$values = array(
			SG_ACTION_STATUS_FINISHED => 100,
			SG_ACTION_STATUS_FINISHED_WARNINGS => 100,
			SG_ACTION_STATUS_CREATED => 0,
			SG_ACTION_STATUS_IN_PROGRESS_FILES => 0,
			SG_ACTION_STATUS_IN_PROGRESS_DB => 0
		);

		return $values[$status] ?? null;
	}

	public static function changeActionStatus($actionId, $status)
	{
		$sgdb = SGDatabase::getInstance();
		$SGBGStateJson = new SGBGStateJson();
		$task = new SGBGTask();

		$actionData = $sgdb->query('SELECT * FROM ' . SG_ACTION_TABLE_NAME . ' WHERE id=%d', [$actionId]);
		if (!count($actionData)) return;
		$progressQuery = '';
		$progressNumber = SGBackup::returnProgress($status);
		if ($progressNumber) $progressQuery = ' progress=' . $progressNumber . ',';

		if ($status == SG_ACTION_STATUS_CANCELLING) SGBackup::DoCancelCleanup($actionData);
		$date = backupGuardConvertDateTimezone(@date('Y-m-d H:i:s'), true);

		$sgdb->query('UPDATE ' . SG_ACTION_TABLE_NAME . ' SET status=%d,' . $progressQuery . ' update_date=%s WHERE id=%d', array($status, $date, $actionId));

		$name = $actionData[0]['name'] ?? null;
		if (!$name) return false;

		$state_backup = SG_BACKUP_DIRECTORY . $name . DIRECTORY_SEPARATOR . SG_STATE_FILE_NAME;
		$task->prepare($state_backup);
		$stateFile = $task->getStateFile();
		$stateFile->setData('db_status', $status);
		$stateFile->save(true);


	}

	public static function changeActionProgress($actionId, $progress)
	{
		$sgdb = SGDatabase::getInstance();
		$date = backupGuardConvertDateTimezone(@date('Y-m-d H:i:s'), true);
		$sgdb->query('UPDATE ' . SG_ACTION_TABLE_NAME . ' SET progress=%d, update_date=%s WHERE id=%d', array(
			$progress, $date, $actionId
		));
	}


	private static function logreadLines($fp, $num)
	{
		$line_count = 0;
		$line = '';
		$pos = -1;
		$lines = array();
		$c = '';

		while ($line_count < $num) {
			$line = $c . $line;
			fseek($fp, $pos--, SEEK_END);
			$c = fgetc($fp);
			if ($c == "\n") {
				$line_count++;
				$lines[] = $line;
				$line = '';
				$c = '';
			}
		}
		return $lines;
	}

	private static function getLines($log)
	{
		if (!file_exists($log)) return null;

		$output = null;

		$fp = @fopen($log, "r");
		$lines = SGBackup::logreadLines($fp, 2);
		if ($lines) array_shift($lines);
		$line = $lines[0] ?? null;
		$output = $line;
		fclose($fp);

		return $output;
	}

	/* Methods for frontend use */
	public static function getAction($actionId)
	{
		$sgdb = SGDatabase::getInstance();
		$res = $sgdb->query('SELECT * FROM ' . SG_ACTION_TABLE_NAME . ' WHERE id=%d', array($actionId));
		if (!isset($res[0])) return false;

		$_tree_done = SG_BACKUP_DIRECTORY . $res[0]['name'] . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_GENERATOR_DONE;
		$_current_file_count = SG_BACKUP_DIRECTORY . $res[0]['name'] . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_FILE_COUNT;
		$filesCount = file_exists($_current_file_count) && filesize($_current_file_count) ? (int) file_get_contents($_current_file_count) : 0;
		$_backup_log = SG_BACKUP_DIRECTORY . $res[0]['name'] . DIRECTORY_SEPARATOR . $res[0]['name'].'_backup.log';
		$_restore_log = SG_BACKUP_DIRECTORY . $res[0]['name'] . DIRECTORY_SEPARATOR . $res[0]['name'].'_restore.log';
		$_extract_log = SG_BACKUP_DIRECTORY . $res[0]['name'] . DIRECTORY_SEPARATOR . $res[0]['name'].'_extract.log';

		$res[0]['tree_procesing'] = true;
		$res[0]['tree_files'] = $filesCount;
		$res[0]['log_lines'] = SGBackup::getLines($_backup_log);
		if (file_exists($_restore_log)) $res[0]['restore_lines'] = SGBackup::getLines($_restore_log);
		if (file_exists($_extract_log)) $res[0]['restore_lines'] = SGBackup::getLines($_extract_log);

		if (file_exists($_tree_done) && filesize($_tree_done)) $res[0]['tree_procesing'] = false;

		return $res[0];
	}

	public static function getActionByName($name)
	{
		$sgdb = SGDatabase::getInstance();
		$res = $sgdb->query('SELECT * FROM ' . SG_ACTION_TABLE_NAME . ' WHERE name=%s', array($name));
		if (empty($res)) {
			return false;
		}

		return $res[0];
	}

	public static function getActionProgress($actionId)
	{
		$sgdb = SGDatabase::getInstance();
		$res = $sgdb->query('SELECT progress FROM ' . SG_ACTION_TABLE_NAME . ' WHERE id=%d', array($actionId));
		if (empty($res)) {
			return false;
		}

		return (int)$res[0]['progress'];
	}

	public static function getActionStatus($actionId)
	{
		$sgdb = SGDatabase::getInstance();
		$res = $sgdb->query('SELECT status FROM ' . SG_ACTION_TABLE_NAME . ' WHERE id=%d', array($actionId));
		if (empty($res)) {
			return false;
		}

		return (int)$res[0]['status'];
	}

	public static function deleteActionById($actionId)
	{
		$sgdb = SGDatabase::getInstance();
		$res = $sgdb->query('DELETE FROM ' . SG_ACTION_TABLE_NAME . ' WHERE id=%d', array($actionId));

		return $res;
	}

	public static function cleanRunningActions($runningActions)
	{
		if (empty($runningActions)) {
			return false;
		}
		foreach ($runningActions as $action) {
			if (empty($action)) {
				continue;
			}
			if ($action['status'] == SG_ACTION_STATUS_IN_PROGRESS_FILES || $action['status'] == SG_ACTION_STATUS_IN_PROGRESS_DB) {
				$id = $action['id'];
				SGBackup::deleteActionById($id);
			}
		}

		return true;
	}

	public static function getRunningActions()
	{
		$sgdb = SGDatabase::getInstance();
		return $sgdb->query('SELECT * FROM ' . SG_ACTION_TABLE_NAME . ' WHERE status < ' . SG_ACTION_STATUS_FINISHED . ' OR status = ' . SG_ACTION_STATUS_TREE);
		//return $sgdb->query('SELECT * FROM ' . SG_ACTION_TABLE_NAME . ' WHERE progress != 100 ');
	}


	public static function dropSchedules()
	{
		$sgdb = SGDatabase::getInstance();
		$sgdb->query('DELETE FROM ' . SG_SCHEDULE_TABLE_NAME . ' WHERE 1=1');
	}

	public static function dropActionsList()
	{
		$sgdb = SGDatabase::getInstance();
		$sgdb->query('DELETE FROM ' . SG_ACTION_TABLE_NAME . ' WHERE 1=1');
		if (function_exists('opcache_reset')) opcache_reset();
	}

	public static function getBackupFileInfo($file)
	{
		return pathinfo(SG_BACKUP_DIRECTORY . $file);
	}

	public static function autodetectBackups()
	{

		$path = SG_BACKUP_DIRECTORY;
		$files = scandir(SG_BACKUP_DIRECTORY);

		$backupLogPostfix = "_backup.log";
		$restoreLogPostfix = "_restore.log";
		$extractLogPostfix = "_extract.log";

		foreach ($files as $file) {
			$fileInfo = self::getBackupFileInfo($file);

			if (!empty($fileInfo['extension']) && $fileInfo['extension'] == SGBP_EXT) {
				@mkdir($path . $fileInfo['filename'], 0777);

				if (file_exists($path . $fileInfo['filename'])) {
					rename($path . $file, $path . $fileInfo['filename'] . DIRECTORY_SEPARATOR . $file);
					file_put_contents($path . $fileInfo['filename'] . DIRECTORY_SEPARATOR . 'imported.flag', 1);
				}

				if (file_exists($path . $fileInfo['filename'] . $backupLogPostfix)) {
					rename($path . $fileInfo['filename'] . $backupLogPostfix, $path . $fileInfo['filename'] . DIRECTORY_SEPARATOR . $fileInfo['filename'] . $backupLogPostfix);
				}

				if (file_exists($path . $fileInfo['filename'] . $restoreLogPostfix)) {
					rename($path . $fileInfo['filename'] . $restoreLogPostfix, $path . $fileInfo['filename'] . DIRECTORY_SEPARATOR . $fileInfo['filename'] . $restoreLogPostfix);
				}

				if (file_exists($path . $fileInfo['filename'] . $extractLogPostfix)) {
					rename($path . $fileInfo['filename'] . $extractLogPostfix, $path . $fileInfo['filename'] . DIRECTORY_SEPARATOR . $fileInfo['filename'] . $extractLogPostfix);
				}
			}
		}
	}

	public static function getAllBackups()
	{
		$backups = array();

		$path = SG_BACKUP_DIRECTORY;

		self::autodetectBackups();
		clearstatcache();

		if (
			SGBoot::isFeatureAvailable('NUMBER_OF_BACKUPS_TO_KEEP')
			&& !count(self::getRunningActions())
			&& function_exists('backupGuardOutdatedBackupsCleanup')
		) {
			backupGuardOutdatedBackupsCleanup($path);
		}

		// remove external restore file
		SGExternalRestore::getInstance()->cleanup();

		if ($handle = @opendir($path)) {

			$sgdb = SGDatabase::getInstance();
			$data = $sgdb->query('SELECT id, name, type, subtype, status, progress, update_date, options FROM ' . SG_ACTION_TABLE_NAME);

			$allBackups = array();
			foreach ($data as $row) {
				$allBackups[$row['name']] = $row;
			}

			while (($entry = readdir($handle)) !== false) {

				if ($entry === '.' || $entry === '..' || !is_dir($path . $entry)) continue;

				$status = $allBackups[$entry]['status'] ?? null;
				$type = $allBackups[$entry]['type'] ?? null;
				$subtype = $allBackups[$entry]['subtype'] ?? null;
				$progress = $allBackups[$entry]['progress'] ?? null;
				$id = $allBackups[$entry]['id'] ?? null;

				$db_options = $allBackups[$entry]['options'] ?? null;
				$json_options = file_exists($path . $entry . DIRECTORY_SEPARATOR . 'state.json') ? json_decode(file_get_contents($path . $entry . DIRECTORY_SEPARATOR . 'state.json')) : null;
				$imported = file_exists($path . $entry . DIRECTORY_SEPARATOR . 'imported.flag');

				$backup = array();
				$backup['name'] = $entry;
				$backup['files'] = file_exists($path . $entry . DIRECTORY_SEPARATOR . $entry . '.sgbp') ? 1 : 0;
				$backup['backup_log'] = file_exists($path . $entry . DIRECTORY_SEPARATOR . $entry . '_backup.log') ? 1 : 0;
				$backup['restore_log'] = file_exists($path . $entry . DIRECTORY_SEPARATOR . $entry . '_restore.log') ? 1 : 0;
				$backup['extarct_log'] = file_exists($path . $entry . DIRECTORY_SEPARATOR . $entry . '_extarct.log') ? 1 : 0;
				$backup['options'] = $db_options;
				if (!$db_options) $backup['options'] = $json_options;

				$backup['status'] = $status ?? $backup['options']->data->db_status ?? $backup['options']->status ?? null;

				// If status is not in the DB, and lower then 2, then it's a "fake" in progress
				if (!$db_options && $backup['status'] <= 2) $backup['status'] = SG_ACTION_STATUS_NULL;

				$backup['type'] = $type;
				if (!$type) $backup['type'] = $json_options->type ?? null;

				$backup['subtype'] = $subtype;
				if (!$subtype) $backup['subtype'] = $json_options->subtype ?? null;

				$backup['progress'] = $progress;
				if (!$progress) $backup['progress'] = $json_options->progress ?? null;

				$backup['id'] = $id;
				if (!$id) $backup['id'] = $json_options->id ?? null;

				if (!$backup['files'] && !$backup['backup_log'] && !$backup['restore_log']) continue;

				$backup['active'] = 0;
				if ($backup['status'] == SG_ACTION_STATUS_IN_PROGRESS_FILES || $backup['status'] == SG_ACTION_STATUS_IN_PROGRESS_DB) $backup['active'] = 1;

				$size = '';
				//$file = backupGuardRemoveSlashes($path . $entry . DIRECTORY_SEPARATOR . $entry . '.sgbp');
				if ($backup['files']) $size = number_format(backupGuardRealFilesize($path . $entry . DIRECTORY_SEPARATOR . $entry . '.sgbp') / 1000.0 / 1000.0, 2, '.', '') . ' MB';
				$backup['size'] = $size;
				//$backup['size'] = jb_convert_size(filesize($file));
				if (!$json_options && !$status) $backup['status'] = SG_ACTION_STATUS_NULL;

				if ($imported) $backup['status'] = SG_ACTION_STATUS_IMPORTED;

				$modifiedTime = filemtime($path . $entry . DIRECTORY_SEPARATOR . '.');
				$date = backupGuardConvertDateTimezone(@date('Y-m-d H:i', $modifiedTime));
				$backup['date'] = $date;
				$backup['modifiedTime'] = $modifiedTime;
				$backups[] = $backup;
			}

			closedir($handle);
		}

		usort($backups, array('SGBackup', 'sort'));

//		echo "<pre>";
		//print_r($backups);
		//exit;
		return array_values($backups);
	}

	public static function sort($arg1, $arg2)
	{
		return $arg1['modifiedTime'] > $arg2['modifiedTime'] ? -1 : 1;
	}

	public static function deleteBackup($backupName, $deleteAction = true)
	{
		$isDeleteBackupFromCloudEnabled = SGConfig::get('SG_DELETE_BACKUP_FROM_CLOUD');
		if ($isDeleteBackupFromCloudEnabled) {
			$backupRow = self::getActionByName($backupName);
			if ($backupRow) {
				$options = $backupRow['options'];
				if ($options) {
					$options = json_decode($options, true);

					if (!empty($options['SG_BACKUP_UPLOAD_TO_STORAGES'])) {
						$storages = explode(',', $options['SG_BACKUP_UPLOAD_TO_STORAGES']);
						self::deleteBackupFromCloud($storages, $backupName);
					}
				}
			}
		}

		backupGuardDeleteDirectory(SG_BACKUP_DIRECTORY . $backupName);

		if ($deleteAction) {
			$sgdb = SGDatabase::getInstance();
			$sgdb->query('DELETE FROM ' . SG_ACTION_TABLE_NAME . ' WHERE name=%s', array($backupName));
		}
	}

	private static function deleteBackupFromCloud($storages, $backupName)
	{
		foreach ($storages as $storage) {
			$storage = (int)$storage;

			$sgBackupStorage = SGBackupStorage::getInstance();
			$sgBackupStorage->deleteBackupFromStorage($storage, $backupName);
		}
	}

	public static function cancelAction($actionId)
	{
		self::changeActionStatus($actionId, SG_ACTION_STATUS_CANCELLING);
	}

	public static function importKeyFile($sgSshKeyFile)
	{
		$filename = $sgSshKeyFile['name'];
		$uploadPath = SG_BACKUP_DIRECTORY . SG_SSH_KEY_FILE_FOLDER_NAME;
		$filename = $uploadPath . $filename;

		if (!@file_exists($uploadPath)) {
			if (!@mkdir($uploadPath)) {
				throw new SGExceptionForbidden('SSH key file folder is not accessible');
			}
		}

		if (!empty($sgSshKeyFile) && $sgSshKeyFile['name'] != '') {
			if (!@move_uploaded_file($sgSshKeyFile['tmp_name'], $filename)) {
				throw new SGExceptionForbidden('Error while uploading ssh key file');
			}
		}
	}

	public static function upload($filesUploadSgbp)
	{
		$filename = str_replace('.sgbp', '', $filesUploadSgbp['name']);
		$backupDirectory = $filename . DIRECTORY_SEPARATOR;
		$uploadPath = SG_BACKUP_DIRECTORY . $backupDirectory;
		$filename = $uploadPath . $filename;

		if (!@file_exists($uploadPath)) {
			if (!@mkdir($uploadPath)) {
				throw new SGExceptionForbidden('Upload folder is not accessible');
			}
		}

		if (!empty($filesUploadSgbp) && $filesUploadSgbp['name'] != '') {
			if ($filesUploadSgbp['type'] != 'application/octet-stream') {
				throw new SGExceptionBadRequest('Not a valid backup file');
			}
			if (!@move_uploaded_file($filesUploadSgbp['tmp_name'], $filename . '.sgbp')) {
				throw new SGExceptionForbidden('Error while uploading file');
			}
		}
	}

	public static function download($filename, $type)
	{
		$backupDirectory = SG_BACKUP_DIRECTORY . $filename . DIRECTORY_SEPARATOR;
		$downloadMode = SGConfig::get('SG_DOWNLOAD_MODE');

		switch ($type) {
			case SG_BACKUP_DOWNLOAD_TYPE_SGBP:
				$filename .= '.sgbp';
				if ($downloadMode == 1) {
					backupGuardDownloadFile($backupDirectory . $filename);
				} else {
					backupGuardDownloadFileViaFunction($backupDirectory, $filename, $downloadMode);
				}
				break;
			case SG_BACKUP_DOWNLOAD_TYPE_BACKUP_LOG:
				$filename .= '_backup.log';
				backupGuardDownloadFile($backupDirectory . $filename, 'text/plain');
				break;
			case SG_BACKUP_DOWNLOAD_TYPE_RESTORE_LOG:
				$filename .= '_restore.log';
				backupGuardDownloadFile($backupDirectory . $filename, 'text/plain');
				break;
		}

		exit;
	}


	public function didUpdateProgress($progress)
	{
		$progress = max($progress, 0);
		$progress = min($progress, 100);

		self::changeActionProgress($this->_actionId, $progress);
	}

	public function isBackgroundMode()
	{
		return $this->_backgroundMode;
	}

	public function setIsManual($isManual)
	{
		$this->_isManual = $isManual;
	}

	public function getIsManual()
	{
		return $this->_isManual;
	}

	public function getIsUploadStorage()
	{
		if (empty($this->_options['SG_BACKUP_UPLOAD_TO_STORAGES'])) {
			return false;
		}

		$uploadToStoragesString = $this->_options['SG_BACKUP_UPLOAD_TO_STORAGES'];

		$uploadToStorages = explode(',', $uploadToStoragesString);
		if (count($uploadToStorages)) {
			return true;
		}

		return false;
	}

	public function willAddFile($filename)
	{
		return true;
	}

	public function didAddFile($filename)
	{
		return true;
	}

	public function getCorrectCdrFilename($filename)
	{
		return $filename;
	}

	public function didCountFilesInsideArchive($count)
	{
	}

	public function shouldExtractFile($filePath)
	{
		if ($this->_restoreMode == SG_RESTORE_MODE_DB && !strpos($filePath, DIRECTORY_SEPARATOR . SG_BACKUP_DEFAULT_FOLDER_NAME . DIRECTORY_SEPARATOR) && !strpos($filePath, DIRECTORY_SEPARATOR . SG_BACKUP_OLD_FOLDER_NAME . DIRECTORY_SEPARATOR)) {
			return false;
		} else if ($this->_restoreMode == SG_RESTORE_MODE_FILES && ($filePath == $this->_databaseBackupPath || $filePath == $this->_databaseBackupOldPath)) {
			return false;
		}

		return true;
	}

	public function willExtractFile($filePath)
	{
	}

	public function didExtractFile($filePath)
	{
	}

	public function didFindExtractError($error)
	{
	}

	public function didExtractArchiveHeaders($version, $extra)
	{
		SGConfig::set('SG_OLD_SITE_URL', $extra['siteUrl']);
		SGConfig::set('SG_OLD_DB_PREFIX', $extra['dbPrefix']);

		if (isset($extra['phpVersion'])) SGConfig::set('SG_OLD_PHP_VERSION', $extra['phpVersion']);

		SGConfig::set('SG_BACKUPED_TABLES', json_encode($extra['tables']));
		SGConfig::set('SG_BACKUP_TYPE', $extra['method']);

		SGConfig::set('SG_MULTISITE_OLD_PATH', $extra['multisitePath']);
		SGConfig::set('SG_MULTISITE_OLD_DOMAIN', $extra['multisiteDomain']);
	}

	public function willAddFileChunk($filename)
	{
		return true;
	}

	public function didAddFileChunk($filename, $chunk)
	{
		return true;
	}

	private function resetBackupProgress()
	{
		$this->_totalRowCount = 0;
		$this->_currentRowCount = 0;
		$tableNames = $this->getTables();
		foreach ($tableNames as $table) {
			$this->_totalRowCount += $this->getTableRowsCount($table);
		}
	}

	private function getTables()
	{
		$tableNames = array();
		$tables = $this->_sgdb->query('SHOW TABLES FROM `' . SG_DB_NAME . '`');
		if (!$tables) {
			throw new SGExceptionDatabaseError('Could not get tables of database: ' . SG_DB_NAME);
		}
		foreach ($tables as $table) {
			$tableName = $table['Tables_in_' . SG_DB_NAME];
			$tablesToExclude = explode(',', SGConfig::get('SG_BACKUP_DATABASE_EXCLUDE'));
			if (in_array($tableName, $tablesToExclude)) {
				continue;
			}
			$tableNames[] = $tableName;
		}

		return $tableNames;
	}

	private function getTableRowsCount($tableName)
	{
		$count = 0;
		$tableRowsNum = $this->_sgdb->query('SELECT COUNT(*) AS total FROM ' . $tableName);
		$count = @$tableRowsNum[0]['total'];

		return $count;
	}

	public function addDontExclude($ex)
	{
		$this->_dontExclude[] = $ex;
	}

	public function startUploadByActionId($task, $actionId, $storageName = '')
	{
		$this->setStateFile($task->getStateFile());
		$this->setRowsCount(1);
		$task->start(1);
		$task->getStateFile()->getCache()->flush();

		if (!$task->getStateFile()->getAction()) {
			$task->getStateFile()->setAction(SG_STATE_ACTION_PREPARING_STATE_FILE);
		}

		$res = $this->_sgdb->query('SELECT * FROM ' . SG_ACTION_TABLE_NAME . ' WHERE id=%d LIMIT 1', array($actionId));
		$row = $res[0];

		if (!count($res)) {
			return false;
		}

		if ($row['type'] != SG_ACTION_TYPE_UPLOAD) {
			return false;
		}

		$this->_actionId = $actionId;
		$type = $row['subtype'];
		$backupName = $row['name'];

		if ($this->getStateFile()->getAction() != SG_STATE_ACTION_PREPARING_STATE_FILE) {

			$this->_nextProgressUpdate = $this->getStateFile()->getProgress() ? $this->getStateFile()->getProgress() : $this->_progressUpdateInterval;
			$this->_actionId = $this->getStateFile()->getActionId();
			$this->_currentUploadChunksCount = $this->getStateFile()->getCurrentUploadChunksCount();

		}

		$storage = $this->storageObjectById($type, $storageName);

		$this->startBackupUpload($task, $backupName, $storage, $storageName);
		return true;
	}

	private function storageObjectById($storageId, &$storageName = '')
	{
		$res = $this->getStorageInfoById($storageId);
		$storageName = $res['storageName'];
		$storageClassName = $res['storageClassName'];

		if (!$storageClassName) {
			throw new SGExceptionNotFound('Unknown storage');
		}

		return new $storageClassName();
	}

	public function getStorageInfoById($storageId)
	{
		$storageName = '';
		$storageClassName = '';
		$storageId = (int)$storageId;
		$isConnected = true;

		switch ($storageId) {
			case SG_STORAGE_FTP:
				if (SGBoot::isFeatureAvailable('FTP')) {
					$connectionMethod = SGConfig::get('SG_STORAGE_CONNECTION_METHOD');

					if ($connectionMethod == 'ftp') {
						$storageName = 'FTP';
					} else {
						$storageName = 'SFTP';
					}
					$isFtpConnected = SGConfig::get('SG_STORAGE_FTP_CONNECTED');

					if (empty($isFtpConnected)) {
						$isConnected = false;
					}
					$storageClassName = "SGFTPManager";
				}
				break;
			case SG_STORAGE_DROPBOX:
				if (SGBoot::isFeatureAvailable('DROPBOX')) {
					$storageName = 'Dropbox';
					$storageClassName = "SGDropboxStorage";
				}
				$isDropboxConnected = SGConfig::get('SG_DROPBOX_ACCESS_TOKEN');

				if (empty($isDropboxConnected)) {
					$isConnected = false;
				}
				break;
			case SG_STORAGE_GOOGLE_DRIVE:
				if (SGBoot::isFeatureAvailable('GOOGLE_DRIVE')) {
					$storageName = 'Google Drive';
					$storageClassName = "SGGoogleDriveStorage";
				}
				$isGdriveConnected = SGConfig::get('SG_GOOGLE_DRIVE_REFRESH_TOKEN');

				if (empty($isGdriveConnected)) {
					$isConnected = false;
				}
				break;
			case SG_STORAGE_AMAZON:
				if (SGBoot::isFeatureAvailable('AMAZON')) {
					$storageName = 'Amazon S3';
					$storageClassName = "SGAmazonStorage";
				}
				$isAmazonConnected = SGConfig::get('SG_STORAGE_AMAZON_CONNECTED');

				if (empty($isAmazonConnected)) {
					$isConnected = false;
				}
				break;
			case SG_STORAGE_ONE_DRIVE:
				if (SGBoot::isFeatureAvailable('ONE_DRIVE')) {
					$storageName = 'One Drive';
					$storageClassName = "SGOneDriveStorage";
				}
				$isOneDriveConnected = SGConfig::get('SG_ONE_DRIVE_REFRESH_TOKEN');

				if (empty($isOneDriveConnected)) {
					$isConnected = false;
				}
				break;
			case SG_STORAGE_P_CLOUD:
				if (SGBoot::isFeatureAvailable('P_CLOUD')) {
					$storageName = 'pCloud';
					$storageClassName = "SGPCloudStorage";
				}

				$isPCloudConnected = SGConfig::get('SG_P_CLOUD_ACCESS_TOKEN');

				if (empty($isPCloudConnected)) {
					$isConnected = false;
				}
				break;
			case SG_STORAGE_BOX:
				if (SGBoot::isFeatureAvailable('BOX')) {
					$storageName = 'box.com';
					$storageClassName = "SGBoxStorage";
				}

				$isBoxConnected = SGConfig::get('SG_BOX_REFRESH_TOKEN');

				if (empty($isBoxConnected)) {
					$isConnected = false;
				}
				break;
		}

		$res = array(
			'storageName' => $storageName,
			'storageClassName' => $storageClassName,
			'isConnected' => $isConnected,
		);

		return $res;
	}

	/**
	 * @throws SGExceptionNotFound
	 */
	private function startBackupUpload($task, $backupName, SGStorage $storage, $storageName)
	{
		$state = $task->getStateFile();

		if ($task->getStateFile()->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
			$actionStartTs = time();
		} else {
			$actionStartTs = $task->getStateFile()->getStartTs();
		}

		$backupPath = SG_BACKUP_DIRECTORY . $backupName;
		$filesBackupPath = $backupPath . DIRECTORY_SEPARATOR . $backupName . '.sgbp';

		if (!is_readable($filesBackupPath)) {
			SGBackup::changeActionStatus($this->_actionId, SG_ACTION_STATUS_ERROR);
			throw new SGExceptionNotFound('Backup not found');
		}

		try {
			//@session_write_close();

			if ($task->getStateFile()->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {

				SGBackup::changeActionStatus($this->_actionId, SG_ACTION_STATUS_IN_PROGRESS_FILES);

				$this->log('Start upload to ' . $storageName, true);
				$this->log('Authenticating', true);
			}

			$storage->setDelegate($this);
			$storage->connectOffline();

			//get backups container folder
			$backupsFolder = $task->getStateFile()->getActiveDirectory();

			if ($task->getStateFile()->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
				$this->log('Preparing folder', true);

				$folderTree = SG_BACKUP_DEFAULT_FOLDER_NAME;

				if (SGBoot::isFeatureAvailable('SUBDIRECTORIES')) {
					$folderTree = SGConfig::get('SG_STORAGE_BACKUPS_FOLDER_NAME');
				}

				//create backups container folder, if needed
				$backupsFolder = $storage->createFolder($folderTree);
			}

			$storage->setActiveDirectory($backupsFolder);

			if ($task->getStateFile()->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
				$this->log('Uploading file', true);
			}
			$storage->uploadFile($filesBackupPath, $task, $this->getLogFile());


			$this->log('Upload to ' . $storageName . ' end', true);

			//Writing upload status to report file
			file_put_contents($backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Uploaded to ' . $storageName . ": completed\n", FILE_APPEND);
			$this->log('Total duration: ' . backupGuardFormattedDuration($actionStartTs, time()), true);

			SGBackup::changeActionStatus($this->_actionId, SG_ACTION_STATUS_FINISHED);
		} catch (Exception $exception) {

			if ($exception instanceof SGExceptionSkip) {
				SGBackup::changeActionStatus($this->_actionId, SG_ACTION_STATUS_CANCELLED);
				//Writing upload status to report file

				file_put_contents($backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Uploaded to ' . $storageName . ': canceled' . "\n", FILE_APPEND);
				file_put_contents($backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Extra details - ' . $exception->getMessage(), FILE_APPEND);


				SGBackupMailNotification::sendBackupNotification(
					SG_ACTION_STATUS_CANCELLED,
					array(
						'flowFilePath' => $backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME,
						'archiveName' => $backupName
					)
				);
			} else {
				SGBackup::changeActionStatus($this->_actionId, SG_ACTION_STATUS_FINISHED_WARNINGS);

				if (!$exception instanceof SGExceptionExecutionTimeError) {//to prevent log duplication for timeout exception
					$this->logException($exception, true);
				}

				if (SGBoot::isFeatureAvailable('NOTIFICATIONS')) {
					//Writing upload status to report file
					file_put_contents($backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Uploaded to ' . $storageName . ': failed' . "\n", FILE_APPEND);
					file_put_contents($backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME, 'Extra details - ' . $exception->getMessage(), FILE_APPEND);

					SGBackupMailNotification::sendBackupNotification(
						SG_ACTION_STATUS_ERROR,
						array(
							'flowFilePath' => $backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME,
							'archiveName' => $backupName
						)
					);
				}
			}

			//delete file inside storage
			$storageId = $state->getStorageType();
			$this->deleteBackupFromStorage($storageId, $backupName);

			//delete report file in case of error
			@unlink($backupPath . DIRECTORY_SEPARATOR . SG_REPORT_FILE_NAME);
		}
	}

	public function deleteBackupFromStorage($storageId, $backupName)
	{

		try {

			$uploadFolder = trim(SGConfig::get('SG_STORAGE_BACKUPS_FOLDER_NAME'), DIRECTORY_SEPARATOR);
			$storage = $this->storageObjectById($storageId);
			$path = "/" . $uploadFolder . "/" . $backupName;
			if (strpos($backupName, '.sgbp') === false) $path = "/" . $uploadFolder . "/" . $backupName . ".sgbp";
			if ($storage) $storage->deleteFile($path);

		} catch (Exception $e) {

		}
	}

	public function willStartUpload($chunksCount)
	{
		$this->_totalUploadChunksCount = $chunksCount;

		if ($this->getStateFile()->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
			$this->resetUploadProgress();
		}
	}

	public function shouldUploadNextChunk()
	{
		$this->_currentUploadChunksCount++;

		if ($this->updateUploadProgress()) {
			$this->checkCancellation();
		}

		return true;
	}

	private function updateUploadProgress($progress = null)
	{

		if (!$progress && $this->_totalUploadChunksCount > 0) {
			$progress = (int)ceil($this->_currentUploadChunksCount * 100.0 / $this->_totalUploadChunksCount);
		}

		if ($progress >= $this->_nextProgressUpdate) {
			$this->_nextProgressUpdate += $this->_progressUpdateInterval;

			$progress = max($progress, 0);
			$progress = min($progress, 100);
			SGBackup::changeActionProgress($this->_actionId, $progress);
			return true;
		}

		return false;
	}

	public function updateUploadProgressManually($progress)
	{
		if ($this->updateUploadProgress($progress)) {
			$this->checkCancellation();
		}

		return true;
	}

	private function resetUploadProgress()
	{
		$this->_currentUploadChunksCount = 0;
		$this->_nextProgressUpdate = $this->_progressUpdateInterval;
	}

	private function checkCancellation()
	{
		$status = SGBackup::getActionStatus($this->_actionId);

		if ($status == SG_ACTION_STATUS_CANCELLING) {
			$this->log('Upload cancelled', true);
			throw new SGExceptionSkip();
		} else if ($status == SG_ACTION_STATUS_ERROR) {
			$this->log('Upload timeout error', true);
			throw new SGExceptionExecutionTimeError();
		}
	}

	public function getPendingStorageUploads()
	{
		return $this->_pendingStorageUploads;
	}

	public function getCurrentUploadChunksCount()
	{
		return $this->_currentUploadChunksCount;
	}

	public function getProgress()
	{
		return $this->getStateFile()->getProgress();
	}

	public function saveCurrentUser()
	{
		if (SG_ENV_ADAPTER != SG_ENV_WORDPRESS) {
			return;
		}

		$user = wp_get_current_user();

		$currentUser = serialize(
			array(
				'login' => $user->user_login,
				'pass' => $user->user_pass,
				'email' => $user->user_email,
			)
		);

		SGConfig::set('SG_CURRENT_USER', $currentUser, true, false);
	}

	public function startRestore($filePath, $task)
	{
		$this->_warningsFound = false;

		$this->extractArchive($filePath, $task);
	}

	private function extractArchive($filePath, $task)
	{
		$rootDirectory = rtrim(SGConfig::get('SG_APP_ROOT_DIRECTORY'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$restorePath = $rootDirectory;

		$archive = new SGBGArchive($filePath);
		$archive->setTask($task);
		$archive->setDelegate($this);
		$archive->setLogEnabled(false);
		$archive->setLogFile($this->getLogFile());
		$archive->getCache()->setCacheMode(SGBGCache::CACHE_MODE_TIMEOUT | SGBGCache::CACHE_MODE_SIZE);
		$archive->getCache()->setCacheTimeout(5);
		$archive->getCache()->setCacheSize(4000000);

		$this->_archive = $archive;

		$archive->open('r');
		$archive->extractTo($restorePath);
	}

	private function backupExtractArchive($filePath, $task)
	{
		$rootDirectory = rtrim(SGConfig::get('SG_APP_ROOT_DIRECTORY'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$restorePath = $rootDirectory . 'test/';

		$archive = new SGBGArchive($filePath);
		$archive->setTask($task);
		$archive->setDelegate($this);
		$archive->setLogEnabled(false);
		$archive->setLogFile($this->getLogFile());
		$archive->getCache()->setCacheMode(SGBGCache::CACHE_MODE_TIMEOUT | SGBGCache::CACHE_MODE_SIZE);
		$archive->getCache()->setCacheTimeout(5);
		$archive->getCache()->setCacheSize(4000000);

		$this->_archive = $archive;

		$archive->open('r');
		$archive->extractTo($restorePath);
	}


	public function getDatabaseHeaders(): string
	{
		return "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;/*SGEnd*/" . PHP_EOL .
			"/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;/*SGEnd*/" . PHP_EOL .
			"/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;/*SGEnd*/" . PHP_EOL .
			"/*!40101 SET NAMES " . SG_DB_CHARSET . " */;/*SGEnd*/" . PHP_EOL .
			"/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;/*SGEnd*/" . PHP_EOL .
			"/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;/*SGEnd*/" . PHP_EOL .
			"/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;/*SGEnd*/" . PHP_EOL .
			"/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;/*SGEnd*/" . PHP_EOL;
	}

	public function warn($message)
	{
		$this->_warningsFound = true;
		$this->log('Warning։ ' . $message, true);
	}


	public function prepareQueryToExec($query)
	{
		$query = $this->replaceInvalidCharacters($query);
		$query = $this->replaceInvalidEngineTypeInQuery($query);

		/*
		if ($this->isMigrationAvailable()) {
			$tableNames    = $this->getBackedUpTables();
			$newTableNames = $this->getNewTableNames();
			$query         = $this->getMigrateObj()->replaceValuesInQuery($tableNames, $newTableNames, $query);
		}
		*/

		$query = $this->getCharsetHandler()->replaceInvalidCharsets($query);

		$query = rtrim(trim($query), "/*SGEnd*/");

		return $query;
	}

	private function replaceInvalidEngineTypeInQuery($query)
	{
		if (version_compare(SG_MYSQL_VERSION, '5.1', '>=')) {
			return str_replace("TYPE=InnoDB", "ENGINE=InnoDB", $query);
		} else {
			return str_replace("ENGINE=InnoDB", "TYPE=InnoDB", $query);
		}
	}

	private function replaceInvalidCharacters($str)
	{
		return $str;
	}

	private function updateDBProgress()
	{
		$progress = round($this->_currentRowCount * 100.0 / $this->_totalRowCount);

		if ($progress >= $this->_nextProgressUpdate) {
			$this->_nextProgressUpdate += $this->_progressUpdateInterval;

			$this->didUpdateProgress($progress);

			return true;
		}

		return false;
	}

	/**
	 * @throws SGExceptionForbidden
	 */
	private function getFileLinesCount($filePath)
	{
		$fileHandle = @fopen($filePath, 'rb');
		if (!is_resource($fileHandle)) {
			throw new SGExceptionForbidden('Could not open file: ' . $filePath);
		}

		$linecount = 0;
		while (!feof($fileHandle)) {
			$linecount += substr_count(fread($fileHandle, 8192), "\n");
		}

		@fclose($fileHandle);

		return $linecount;
	}

	public function getBackedUpTables()
	{
		if ($this->_backedUpTables === null) {
			$tableNames = backupGuardRemoveSlashes(SGConfig::get('SG_BACKUPED_TABLES'));
			if ($tableNames) {
				$tableNames = json_decode($tableNames, true);
				if (is_string($tableNames)) {
					$tableNames = json_decode($tableNames, true);
				}
			} else {
				$tableNames = array();
			}
			$this->_backedUpTables = $tableNames;
		}

		return $this->_backedUpTables;
	}

	public function getNewTableNames()
	{
		if ($this->_newTableNames === null) {
			$oldDbPrefix = $this->getOldDbPrefix();
			$tableNames = $this->getBackedUpTables();

			if (empty($tableNames)) return null;

			$newTableNames = array();
			foreach ($tableNames as $tableName) {
				$newTableNames[] = str_replace($oldDbPrefix, SG_ENV_DB_PREFIX, $tableName);
			}
			$this->_newTableNames = $newTableNames;
		}

		return $this->_newTableNames;
	}

	public function getOldDbPrefix()
	{
		if ($this->_oldDbPrefix === null) {
			$this->_oldDbPrefix = SGConfig::get('SG_OLD_DB_PREFIX');
		}

		return $this->_oldDbPrefix;
	}

	public function getMigrateObj()
	{
		if ($this->_migrateObj === null) {
			$this->_migrateObj = new SGMigrate();
		}

		return $this->_migrateObj;
	}

	public function getCharsetHandler()
	{
		if ($this->_charsetHandler === null) {
			$this->_charsetHandler = new SGCharsetHandler();
		}

		return $this->_charsetHandler;
	}

	public function getArchiveExtraData()
	{
		$tables = SGConfig::get('SG_BACKUPED_TABLES');

		if ($tables) {
			$tables = json_encode($tables);
		} else {
			$tables = "";
		}

		$multisitePath = "";
		$multisiteDomain = "";

		if (SG_ENV_ADAPTER == SG_ENV_WORDPRESS) {
			// in case of multisite save old path and domain for later usage
			if (is_multisite()) {
				$multisitePath = PATH_CURRENT_SITE;
				$multisiteDomain = DOMAIN_CURRENT_SITE;
			}
		}

		//save db prefix, site and home url for later use
		return json_encode(
			array(
				'siteUrl' => get_site_url(),
				'home' => get_home_url(),
				'dbPrefix' => SG_ENV_DB_PREFIX,
				'tables' => $tables,
				'method' => SGConfig::get('SG_BACKUP_TYPE'),
				'multisitePath' => $multisitePath,
				'multisiteDomain' => $multisiteDomain,
				'selectivRestoreable' => true,
				'phpVersion' => phpversion()
			)
		);
	}

	public function cleanUpRestoreState($backupName)
	{
		if (file_exists(SG_BACKUP_DIRECTORY . $backupName . DIRECTORY_SEPARATOR . SG_RESTORE_STATE_FILE_NAME)) {
			unlink(SG_BACKUP_DIRECTORY . $backupName . DIRECTORY_SEPARATOR . SG_RESTORE_STATE_FILE_NAME);
		}
	}

	public function cleanUpExtractState($backupName)
	{
		if (file_exists(SG_BACKUP_DIRECTORY . $backupName . DIRECTORY_SEPARATOR . SG_EXTRACT_STATE_FILE_NAME)) {
			unlink(SG_BACKUP_DIRECTORY . $backupName . DIRECTORY_SEPARATOR . SG_EXTRACT_STATE_FILE_NAME);
		}
		if (file_exists(SG_BACKUP_DIRECTORY . $backupName . DIRECTORY_SEPARATOR . SG_BACKUP_ACTION_ID_FILE)) {
			unlink(SG_BACKUP_DIRECTORY . $backupName . DIRECTORY_SEPARATOR . SG_BACKUP_ACTION_ID_FILE);
		}
	}
}