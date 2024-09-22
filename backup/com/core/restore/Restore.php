<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(SG_BACKUP_PATH . '/SGBackup.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGStateJson.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/Log.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGStateFile.php');

class Restore
{
	public $_key = null;
	public $_action = null;
	public $_params = null;
	public $_currentUser = null;
	public $_prefixFromBackup = null;
	public $_fileName = null; // SGB backup file name used to restore
	public $_restorePath = null;
	public $_filesBackupPath = null;
	public $_databaseBackupPath = null;
	public $_databaseBackupOldPath = null;
	public $_actionId = null;
	public $_filesBackupAvailable = null;
	public $_restoreLogPath = null;
	private $_actionStartTs;
	private $_logFile;
	private $_stateFile;
	private $_restoreMode = null;
	private $_archive;
	public $_databaseBackupAvailable = null;
	private $_sgdb;
	private $_warningsFound;

    /**
	 * @throws SGExceptionDatabaseError
	 */
	public function __construct()
	{
		if (!$this->_actionStartTs) $this->_actionStartTs = time();

		if (!isset($_key)) $this->setKey();
		if (!isset($_action)) $this->setAction();
		if (!isset($_params)) $this->setParams();
		if (!isset($_currentUser)) $this->setCurrentUser();
		if (!isset($_prefixFromBackup)) $this->setPrefixFromBackup();

		if (isset($_fileName)) {
			$this->setRestorePath();
			$this->setFilesBackupPath();
			$this->setDatabaseBackupPath();
			$this->setDatabaseBackupOldPath();
			$this->setRestoreLogPath();
            $this->setStateFile();

			if (!$this->_actionId) $this->setActionId();
		}

		$this->_sgdb = SGDatabase::getInstance();
	}

	public function init($bg_restore_key)
	{
		if ($this->getKey() != $bg_restore_key) die('Invalid key');
	}

	public function getCurrentUser () {

		return $this->_currentUser;
	}

	public function activePlugins()
	{
		$this->log('Handling active plugins');

		$wpdb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
		$wpdb->db_connect();

		$row = $wpdb->get_row(
			$wpdb->prepare('SELECT option_value FROM ' . SG_ENV_DB_PREFIX . 'options WHERE option_name = %s', 'active_plugins')
		);

		if (!isset($row->option_value) || !$row->option_value) return;

		$activePlugins = unserialize($row->option_value);
		$activePlugins[] = SG_PLUGIN_NAME . '/' . BACKUP_GUARD_TEXTDOMAIN . '.php';
		$activePluginsRow = serialize($activePlugins);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `" . SG_ENV_DB_PREFIX . "options` SET option_value = %s WHERE option_name = %s",
				$activePluginsRow,
				'active_plugins'
			)
		);
	}

	public function setCurrentUser()
	{
		$this->_currentUser = SGConfig::get('SG_CURRENT_USER');
	}

	public function getPrefixFromBackup()
	{
		return $this->_prefixFromBackup;
	}

	public function setPrefixFromBackup()
	{
		$this->_prefixFromBackup = SGConfig::get('SG_OLD_DB_PREFIX');
	}

	public function UpdateConfig()
	{
		$this->log('Entering updateconfig function (wp-config.php)');
		$this->log('DB Prefix from Backup: ' . $this->getPrefixFromBackup() );
		$this->log('DB Prefix from Current site: ' . SG_ENV_DB_PREFIX );

		if ($this->getPrefixFromBackup() != SG_ENV_DB_PREFIX) {

			$this->log('DB Prefix is different, will try to change wp-config settings now');


			$WPconfig = ABSPATH . 'wp-config.php';
			$WPconfigBackup = ABSPATH . '.jetbackup_' . rand() . '_wp-config.php';
			$PrefixFromBackup = $this->getPrefixFromBackup();
			$new_config = null;

			if (file_exists($WPconfig)) {

				$this->log('Found wp-config.php file at ' . $WPconfig);


				$handle = fopen($WPconfig, "r");
				if ($handle) {

					$this->log('We can read the file at ' . $WPconfig);


					while (($line = fgets($handle)) !== false) {

						preg_match("/\\\$table_prefix\s*\=\s*\'(.*)\'\s*;/i", $line, $matches);

						if (count($matches) == 2) {
							$this->log('Found syntax matching table_prefix ' . print_r($matches, true));

							$original_prefix = $matches[1];

							$this->log('Final prefix fetched: ' . $original_prefix);


							if ($original_prefix != $PrefixFromBackup) {

								$new_config .= "\n"; // Adding extra line
								$new_config .= "\$table_prefix='{$PrefixFromBackup}'; \n";
								$new_config .= "//Prefix updated by JetBackup migration, old prefix: " . $original_prefix ."\n";
								$new_config .= "\n"; // Adding extra line

							}
						} else {
							$new_config .= $line;
						}
					}

					fclose($handle);
				}

				@chmod($WPconfig, 0660);
				@copy($WPconfig, $WPconfigBackup);
				@file_put_contents($WPconfig, $new_config);
				@chmod($WPconfig, 0600);
				@chmod($WPconfigBackup, 0400);
			}
		}
	}

	public function updateUser()
	{
		$this->log('Updating wordpress admin user');

		$wpdb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
		$wpdb->db_connect();

		$user = unserialize($this->getCurrentUser());
		$dbuser = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->getPrefixFromBackup() . 'users WHERE `user_email` = %s', $user['email']));

		// User from SG_CURRENT_USER is the same as user from DB
		if (isset($dbuser->ID) && is_numeric($dbuser->ID)) return;

		// Not sure user, we need to inject current active admin so user can login after the switch
		$name = $user['login'];
		$email = $user['email'];
		$pass = $user['pass'];
		$now = date("Y-m-d H:i:s");

		$sql = "INSERT INTO `" . $this->getPrefixFromBackup() . "users`
          (`user_login`,`user_pass`,`user_nicename`,`user_email`,`user_url`,`user_registered`,`user_activation_key`,`user_status`,`display_name`) 
   values ('" . $name . "', '" . $pass . "', '" . $name . "', '" . $email . "', 'url', '" . $now . "', 'key', 0, '" . $name . "')";

		$wpdb->query($sql);
		$lastid = $wpdb->insert_id;

		$this->log('Wordpress insert ID : ' . $lastid);

		if ($lastid && is_numeric($lastid)) {
			$user_id = $lastid;
			$meta_key = $this->getPrefixFromBackup() . 'capabilities';
			$meta_value = 'a:1:{s:13:"administrator";s:1:"1";}';

			$sql = "INSERT INTO `" . $this->getPrefixFromBackup() . "usermeta`
          (`user_id`,`meta_key`,`meta_value`) 
   values ('" . $user_id . "', '" . $meta_key . "', '" . $meta_value . "')";
			$wpdb->query($sql);

			$meta_key = $this->getPrefixFromBackup() . 'user_level';
			$meta_value = '10';

			$sql = "INSERT INTO `" . $this->getPrefixFromBackup() . "usermeta`
          (`user_id`,`meta_key`,`meta_value`) 
   values ('" . $user_id . "', '" . $meta_key . "', '" . $meta_value . "')";
			$wpdb->query($sql);
		}
	}

	public function quit($die = false)
	{
		$SGBackup = new SGBackup();
		$SGBackup->changeActionStatus($this->getActionId(), SG_ACTION_STATUS_FINISHED);
		if ($die) die(1);
	}

	public function maintenanceMode($active = false)
	{
		$file = ABSPATH . '.maintenance';

		if ($active) {
			$this->log('Activating maintenance mode');
			file_put_contents($file, '<?php $upgrading = ' . time() . '; ?>');
		} else {
			$this->log('Disabling maintenance mode');
			if (file_exists($file)) unlink($file);
		}
	}

	public function setBackupName($backupName)
	{
		$name = !empty($backupName) ? $backupName : $this->getParams()['name'];
		$this->_fileName = backupGuardRemoveSlashes($name);
	}

	public function getBackupName()
	{
		if ($this->_fileName != null) return $this->_fileName;

		$params = $this->getParams();
		return $params['name'] ? backupGuardRemoveSlashes($params['name']) : null;
	}

	public function setRestorePath()
	{
		$this->_restorePath = SG_BACKUP_DIRECTORY . $this->getBackupName();
	}

	public function setFilesBackupPath()
	{
		$this->_filesBackupPath = $this->getRestorePath() . '/' . $this->getBackupName() . '.sgbp';
	}

	public function getFilesBackupPath()
	{
		return $this->_filesBackupPath;
	}

	public function setDatabaseBackupPath()
	{
		$this->_databaseBackupPath = $this->getRestorePath() . '/' . $this->getBackupName() . '.sql';
	}

	public function getDatabaseBackupPath()
	{
		return backupGuardRemoveSlashes($this->_databaseBackupPath);
	}

	public function getRestorePath()
	{
		return $this->_restorePath;
	}

	public function setDatabaseBackupOldPath()
	{
		$this->_databaseBackupOldPath = SG_BACKUP_OLD_DIRECTORY . $this->getBackupName() . '/' . $this->getBackupName() . '.sql';
	}

	public function getDatabaseBackupOldPath()
	{
		return $this->_databaseBackupOldPath;
	}

	/**
	 * @throws SGExceptionDatabaseError
	 */
	public function setActionId()
	{
		$SGBGStateJson = new SGBGStateJson();


		$array['mode'] = $this->getRestoreMode();
		$options = $SGBGStateJson->DoJson('json_encode', $array);
		if (!$this->_actionId) {

			$this->_actionId = SGBackup::createAction($this->getBackupName(), SG_ACTION_TYPE_RESTORE, SG_ACTION_STATUS_IN_PROGRESS_FILES, null, $options);
			$this->log('Inside setActionId . ' . $this->_actionId);
			$backtrace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]['function'];
			$this->log('Backtrace ' . $backtrace);

		}

	}

	public function getActionId()
	{
		$_actionId_file = SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_BACKUP_ACTION_ID_FILE;
		$id = file_exists($_actionId_file) ? file_get_contents($_actionId_file) : $this->_actionId;
		$this->log('Inside getActionId . ' . $id);
		$backtrace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]['function'];
		$this->log('Backtrace ' . $backtrace);

		return $id;
	}

	public function setRestoreLogPath()
	{
		$this->_restoreLogPath = $this->getRestorePath() . '/' . $this->getBackupName() . '_restore.log';
	}

	public function getRestoreLogPath()
	{
		return $this->_restoreLogPath;
	}

	public function getLogFile()
	{
		return $this->_restoreLogPath;
	}

	public function setLogFile()
	{
		$this->setRestoreLogPath();
	}

	/**
	 * @throws SGExceptionMethodNotAllowed
	 */
	public function prepareRestoreLogFile()
	{
		if (!file_exists($this->getRestoreLogPath())) {
			$content = SGBackup::getLogFileHeader(SG_ACTION_TYPE_RESTORE, $this->getBackupName()) . PHP_EOL;

			if (!file_put_contents($this->getRestoreLogPath(), $content)) {
				throw new SGExceptionMethodNotAllowed('Cannot create restore log file: ' . $this->getRestoreLogPath());
			}
		}
	}

	public function getDatabaseBackupAvailable()
	{
		return $this->_databaseBackupAvailable; // returns the file name to restore
	}

	public function setDatabaseBackupAvailable()
	{
		$SGBackup = new SGBackup();
		$this->_databaseBackupAvailable = null;
		if ($this->getDatabaseBackupPath()) $this->_databaseBackupAvailable = file_exists($this->getDatabaseBackupPath()) ? $this->getDatabaseBackupPath() : null;

		if (!$this->_databaseBackupAvailable) {
			if($this->getDatabaseBackupOldPath()) $this->_databaseBackupAvailable = file_exists($this->getDatabaseBackupOldPath()) ? $this->getDatabaseBackupOldPath() : null;
		}
		if (!$this->_databaseBackupAvailable) $this->_databaseBackupAvailable = $SGBackup->scanBackupsFolderForSqlFile($this->getRestorePath());
		if (!$this->_databaseBackupAvailable) $this->_databaseBackupAvailable = $SGBackup->scanBackupsFolderForSqlFile(SG_BACKUP_OLD_DIRECTORY . $this->getBackupName() . '/');
	}

	public function setRestoreMode($restoreMode)
	{
		$this->_restoreMode = $restoreMode;
	}

	public function UpdateProgress() {
		$SGBGStateJson = new SGBGStateJson();
		$state = file_exists($this->getStateFile()) ? file_get_contents($this->getStateFile()) : null;
		if ($state) {
			$state = $SGBGStateJson->DoJson('json_decode', $state);

			$current = $state['offset'] ?? null;
			$total = $state['count'] ?? null;

            if ($current && $total) {
                $progress = round($current * 100.0 / $total);
                $this->didUpdateProgress($progress);
            }
		}
	}

	public function didUpdateProgress($progress) {
		$progress = max($progress, 0);
		$progress = min($progress, 100);

		if ($this->getActionId() && is_numeric($this->getActionId())) SGBackup::changeActionProgress($this->getActionId(), $progress);
	}

	public function getRestoreMode()
	{
		if ($this->_restoreMode != null) return $this->_restoreMode;

		if ($this->getActionId()) {
			$SGBGStateJson = new SGBGStateJson();
			$params = $this->getParams();

			$mode = $params['options'] ? $SGBGStateJson->DoJson('json_decode', $params['options']) : null;

			return $mode['mode'] ?? null;
		}

		return null;
	}

	/**
	 * @throws SGExceptionDatabaseError
	 * @throws SGExceptionForbidden
	 * @throws SGExceptionMethodNotAllowed
	 */
	public function prepare($backupName, $restoreMode, $status)
	{
		$SGBackup = new SGBackup();

		$this->setRestoreMode($restoreMode);
		$this->setBackupName($backupName);
		$this->setRestorePath();
		$this->SetFilesBackupPath();
		$this->setDatabaseBackupPath();

		if ($status === SG_ACTION_STATUS_CREATED) $this->setActionId();

		SGExternalRestore::getInstance()->prepare($this->getActionId());
		$SGBackup->saveCurrentUser();
		$this->prepareRestoreFolder();
		$this->setFilesBackupAvailable();
		$this->setRestoreLogPath();
		$this->prepareRestoreLogFile();
		$this->setLogFile();
		SGConfig::set('SG_RUNNING_ACTION', 1, true);
		$this->setDatabaseBackupAvailable();
	}

	public function log($logData)
	{
		$Log = new Log($this->getRestoreLogPath());
		$Log->write($logData);
	}

	public function setFilesBackupAvailable()
	{
		$this->_filesBackupAvailable = file_exists($this->getFilesBackupPath());
	}

	/**
	 * @throws SGExceptionForbidden
	 */
	public function prepareRestoreFolder()
	{
		if (!is_writable($this->getRestorePath())) {
			SGConfig::set('SG_BACKUP_NOT_WRITABLE_DIR_PATH', $this->getRestorePath());
			SGConfig::set('SG_BACKUP_SHOW_NOT_WRITABLE_ERROR', 1);
			throw new SGExceptionForbidden('Permission denied. Directory is not writable: ' . $this->getRestorePath());
		}
	}

	public function getStateFile() {
		if (!$this->_stateFile) $this->setStateFile();
		return $this->_stateFile;
	}

	public function setStateFile() {
		if (!$this->_stateFile && $this->getBackupName()) $this->_stateFile = SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_RESTORE_STATE_FILE_NAME;
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

	private function processDBMigration()
	{
		$this->log('Start migration', true);
		$sgMigrate = new SGMigrate($this->_sgdb);
		$this->log('After importing class SGMigrate', true);
		$sgMigrate->setDelegate($this);
		$this->log('After setDelegate', true);
		$tables = $this->getTables();
		$this->log('After getTables', true);
		$oldSiteUrl = SGConfig::get('SG_OLD_SITE_URL');
		// Find and replace old urls with new ones
		$this->log('Old site url: ' . $oldSiteUrl, true);
		$this->log('Current site url: ' . SG_SITE_URL, true);
		if ($oldSiteUrl != SG_SITE_URL) {

			$this->log('Site URL Mismatch, entering migration', true);
			$sgMigrate->migrate($oldSiteUrl, SG_SITE_URL, $tables);

		} else {

			$this->log('Skipping URL migration', true);

		}

		$this->log('After migrate', true);
		// Find and replace old db prefixes with new ones
		//$sgMigrate->migrateDBPrefix();

		$isMultisite = backupGuardIsMultisite();
		if ($isMultisite) {

			$this->log('Inside isMultisite', true);

			$tables = explode(',', SG_MULTISITE_TABLES_TO_MIGRATE);

			$oldPath = SGConfig::get('SG_MULTISITE_OLD_PATH');
			$newPath = PATH_CURRENT_SITE;
			$newDomain = DOMAIN_CURRENT_SITE;

			$sgMigrate->migrateMultisite($newDomain, $newPath, $oldPath, $tables);
		}

		$this->log('End migration', true);
	}


	private function importDB($db, $task)
	{

		$sg_action = SG_ENV_DB_PREFIX.'sg_action';
		$tableName = null;
		$backtrace = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]['function'];
		$this->log('Entering importDB ');
		$this->log('Backtrace ' . $backtrace);
		$this->log('SG_ACTION Table: ' . $sg_action);

		$SGBackup = new SGBackup();

		$fileHandle = @fopen($db, 'r');
		if (!is_resource($fileHandle)) {
			throw new SGExceptionForbidden('Could not open file: ' . $db);
		}

		$importQuery = $SGBackup->getDatabaseHeaders();

		$this->log('Database Headers: ' . $importQuery);

		//while (($row = @fgets($fileHandle)) !== false && strpos($row, '_sg_action') === false) {
		while (($row = @fgets($fileHandle)) !== false) {

			$importQuery .= $row;
			$trimmedRow = trim($row);

				if (strpos($trimmedRow, 'CREATE TABLE') !== false) {
					$strLength = strlen($trimmedRow);
					$strCtLength = strlen('CREATE TABLE ');
					$length = $strLength - $strCtLength - 2;
					$tableName = substr($trimmedRow, $strCtLength, $length);
					$this->log('Importing table ' . $tableName, true);
				}



				if ($trimmedRow && substr($trimmedRow, -9) == "/*SGEnd*/") {
					$queries = explode("/*SGEnd*/" . PHP_EOL, $importQuery);
					foreach ($queries as $query) {
						if (!$query) {
							continue;
						}



						if ($sg_action != $tableName) {

							$importQuery = $SGBackup->prepareQueryToExec($query);
							$res = $this->_sgdb->execRaw($importQuery);

							if ($res === false) {
								//continue restoring database if any query fails
								//we will just show a warning inside the log
								$this->_warningsFound = true;

								if($tableName) $this->log('Could not import table: ' . $tableName);
								$this->log('Query: ' . $importQuery);
								$this->log($this->_sgdb->getLastError());
							}

						} else {

							$this->log('SKIPPED import table: ' . $tableName);
							$this->log('Query: ' . $importQuery);

						}




					}
					$importQuery = '';
				}




		}

		@fclose($fileHandle);

		$this->log('Exiting importDB ');

	}

	public function didCountFilesInsideArchive($count)
	{
	}

	public function getCorrectCdrFilename($filename)
	{
		return $filename;
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

	public function shouldExtractFile($filePath)
	{
		if ($this->getRestoreMode() == SG_RESTORE_MODE_DB && !strpos($filePath, '/' . SG_BACKUP_DEFAULT_FOLDER_NAME . '/') && !strpos($filePath, '/' . SG_BACKUP_OLD_FOLDER_NAME . '/')) {
			return false;
		} else if ($this->getRestoreMode() == SG_RESTORE_MODE_FILES && ($filePath == $this->_databaseBackupPath || $filePath == $this->_databaseBackupOldPath)) {
			return false;
		}

		return true;
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

	/**
	 * @throws Exception
	 */
	public function openFile()
	{
		$task = new SGBGTask();
		$archive = new SGBGArchive($this->getFilesBackupPath());
		$rootDirectory = rtrim(SGConfig::get('SG_APP_ROOT_DIRECTORY'), '/') . '/';

		$task->prepare(SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_RESTORE_STATE_FILE_NAME);

		$archive->setTask($task);
		$archive->setDelegate($this);
		$archive->setLogFile($this->getLogFile());
		$this->_archive = $archive;
		$archive->open('r');
		$archive->extractTo($rootDirectory);
	}

	/**
	 * @throws SGExceptionMethodNotAllowed
	 * @throws SGExceptionForbidden
	 * @throws SGExceptionDatabaseError
	 * @throws Exception
	 */
	public function doRestore($backupName, $id, $restoreMode, $status = null)
	{
		$this->prepare($backupName, $restoreMode, $status);

		$task = new SGBGTask();
		$task->prepare(SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_RESTORE_STATE_FILE_NAME);

		if (!SGBLock::LockFile(SG_BACKUP_DIRECTORY . 'restore.lock')) return;

		$stateFile = $task->getStateFile();

		if (SGBackup::getActionStatus($this->getActionId()) == SG_ACTION_STATUS_IN_PROGRESS_DB) die('busy');

		switch ($stateFile->getStatus()) {
			case SGBGStateFile::STATUS_READY:
				$this->log('Start restore');

				$stateFile->setBackupFileName($this->getBackupName());
				$stateFile->setBackedUpTables(array());
				$stateFile->setAction(SG_STATE_ACTION_RESTORING_FILES);
				$stateFile->setType(SG_STATE_TYPE_FILE);
				$stateFile->setActionId($this->getActionId());
				$stateFile->setStartTs($this->_actionStartTs);
				$stateFile->setOffset(0);
				$stateFile->setRestoreMode($this->getRestoreMode());
				//$this->maintenanceMode(true);
				$this->openFile();
				break;

			case SGBGStateFile::STATUS_STARTED:
			case SGBGStateFile::STATUS_RESUME:
			case SGBGStateFile::STATUS_BUSY:
				$data = $stateFile->getData();
				$cdrSize = isset($data['cdrSize']) ? (int)$data['cdrSize'] : null;

				if ($cdrSize && $cdrSize != 0) {
					$this->UpdateProgress();
					$this->openFile();
				}

				if ($this->getRestoreMode() == 'db' || $this->getRestoreMode() == 'full' && $this->getDatabaseBackupAvailable()) {

					$this->log('After Files extract, Entering DB mode');
					$stateFile->setStatus(SGBGStateFile::STATUS_DB);
					$stateFile->save(true);
					break;

				} else {

					$this->log('After Files extract, No DB found, finished');

					$stateFile->setStatus(SGBGStateFile::STATUS_DONE);
					$stateFile->save(true);

				}

				break;

			case SGBGStateFile::STATUS_DB:


					SGBackup::changeActionStatus($this->getActionId(), SG_ACTION_STATUS_IN_PROGRESS_DB);

					$stateFile->setOffset(0);
					$stateFile->setCount(1);

					$stateFile->setAction(SG_STATE_ACTION_RESTORING_DATABASE);
					$stateFile->setType(SG_STATE_TYPE_DB);
					$stateFile->setStatus(SGBGStateFile::STATUS_STARTED);
					$stateFile->save(true);

					$this->log('Before importDB');

					if ($this->getDatabaseBackupAvailable()) {
						$this->importDB($this->getDatabaseBackupAvailable(), $task);
					} else {
						$this->log('No DB File found, skipping DB Import');
					}

					$this->log('After importDB');

					$stateFile->setStatus(SGBGStateFile::STATUS_IMOPRTED);
					$stateFile->save(true);
					SGBackup::changeActionStatus($this->getActionId(), SG_ACTION_STATUS_IMPORTED);

				break;

			case SGBGStateFile::STATUS_IMOPRTED:
			case SGBGStateFile::STATUS_MIGRATION:

				$this->log('Inside Migration, checking license');
				$this->log('License backupGuardGetCapabilities: ' . backupGuardGetCapabilities());

				if (backupGuardGetCapabilities() == BACKUP_GUARD_CAPABILITIES_FREE || backupGuardGetCapabilities() == BACKUP_GUARD_CAPABILITIES_SILVER) {

					$this->log('Free/Solo license found, not entering migration');

				} else {

					$this->processDBMigration();
					$this->log('Inside restore finalize state');

					//$Restore->maintenanceMode(false);
					$this->activePlugins();

					$this->log('After plugin update');

					$this->log('getRestoreMode: ' . $this->getRestoreMode());
					$this->log('getDatabaseBackupAvailable: ' . $this->getDatabaseBackupAvailable());


					$this->log('Before UpdateUser');

					$this->UpdateUser();

					$this->log('After UpdateUser');

					$this->log('Before UpdateConfig');

					$this->UpdateConfig();
					$this->log('After UpdateConfig');

				}

				$stateFile->setStatus(SGBGStateFile::STATUS_DONE);
				$stateFile->save(true);

				break;

			case SGBGStateFile::STATUS_DONE:

				SGConfig::set('SG_RESTORE_FINALIZE', 1, true);
				SGBackup::changeActionStatus($this->getActionId(), SG_ACTION_STATUS_FINISHED);
				$this->log('Memory peak usage ' . (memory_get_peak_usage(true) / 1024 / 1024) . 'MB', true);
				$this->log('Total duration ' . backupGuardFormattedDuration($this->_actionStartTs, time()), true);
				//delete sql file
				if (file_exists($this->getDatabaseBackupAvailable())) unlink($this->getDatabaseBackupAvailable());
				if (file_exists(SG_BACKUP_DIRECTORY . JBWP_DIRECTORY_STATE_FILE_NAME)) unlink(SG_BACKUP_DIRECTORY . JBWP_DIRECTORY_STATE_FILE_NAME);
				break;

			default:
				die('busy');
		}

		SGBLock::UnlockFile(SG_BACKUP_DIRECTORY . 'restore.lock');


	}

	/**
	 * @throws SGExceptionMethodNotAllowed
	 * @throws SGExceptionForbidden
	 * @throws SGExceptionDatabaseError
	 */
	public function continue()
	{
		$this->doRestore($this->getBackupName(), $this->getActionId(), $this->getRestoreMode(), null);
	}

	public function progress()
	{
		$params = $this->getParams();

		$params['lastAction'] = $params['restore_lines'] ?? null;
		$status = $params['status'] ?? null;

		switch ($status) {

			case SG_ACTION_STATUS_IN_PROGRESS_FILES:
				$this->UpdateProgress();
				die (json_encode($params));

			case SG_ACTION_STATUS_IMPORTED:
			case SG_ACTION_STATUS_MIGRATION:
			case SG_ACTION_STATUS_CREATED:
			case SG_ACTION_STATUS_IN_PROGRESS_DB:
				die (json_encode($params));

			case SG_ACTION_STATUS_FINISHED:
			case SG_ACTION_STATUS_FINISHED_WARNINGS:
				die ('1');

			default:
				die('0');
		}
	}

	public function getParams()
	{
		return $this->_params;
	}

	public function setParams()
	{
		$SGBackup = new SGBackup();
		if (defined('SG_ACTION_ID')) $this->_params = $SGBackup->getAction(SG_ACTION_ID);
	}

	public function setAction()
	{
		$this->_action = isset($_REQUEST['action']) ? filter_var($_REQUEST['action'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
	}

	public function setKey()
	{
		$this->_key = isset($_REQUEST['k']) ? filter_var($_REQUEST['k'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
	}

	public function getKey()
	{
		return $this->_key;
	}

	public function getAction()
	{
		return $this->_action;
	}
}