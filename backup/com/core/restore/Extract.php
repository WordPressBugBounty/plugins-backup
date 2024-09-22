<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(SG_BACKUP_PATH . '/SGBackup.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGStateJson.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/Log.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGStateFile.php');

class Extract
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
	private $_archive;
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

		if (isset($_fileName)) {
			$this->setRestorePath();
			$this->setFilesBackupPath();
			$this->setDatabaseBackupPath();
			$this->setDatabaseBackupOldPath();
			$this->setRestoreLogPath();

			if (!$this->_actionId) $this->setExtractActionId();
		}

		$this->_sgdb = SGDatabase::getInstance();
	}

	public function init($bg_restore_key)
	{
		if ($this->getKey() != $bg_restore_key) die('Invalid key');
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

    public function setExtractActionId()
    {
        $SGBGStateJson = new SGBGStateJson();

        $options = $SGBGStateJson->DoJson('json_encode', []);
        if (!$this->_actionId && !SGBackup::getActionByName($this->getBackupName())) {
            $this->_actionId = SGBackup::createAction($this->getBackupName(), SG_ACTION_TYPE_EXTRACT, SG_ACTION_STATUS_IN_PROGRESS_FILES, null, $options);
        }
    }

	public function getActionId()
	{
		$_actionId_file = SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_BACKUP_ACTION_ID_FILE;
		$id = file_exists($_actionId_file) ? file_get_contents($_actionId_file) : $this->_actionId;

		return $id;
	}

	public function setRestoreLogPath()
	{
		$this->_restoreLogPath = $this->getRestorePath() . '/' . $this->getBackupName() . '_extract.log';
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

	public function quit($die = false)
	{
		$SGBackup = new SGBackup();
		$SGBackup->changeActionStatus($this->getActionId(), SG_ACTION_STATUS_FINISHED);
		if ($die) die(1);
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
		if (!$this->_stateFile && $this->getBackupName()) $this->_stateFile = SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_EXTRACT_STATE_FILE_NAME;
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

	public function shouldExtractFile()
	{
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

    public function continueExtract()
    {
        $this->doExtract($this->getBackupName(), $this->getActionId());
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

			case SG_ACTION_STATUS_CREATED:
			case SG_ACTION_STATUS_IN_PROGRESS_DB:
				die (json_encode($params));

			case SG_ACTION_STATUS_IMPORTED:
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

	public function doExtract($backupName, $status = null)
	{
		$this->prepareExtract($backupName, $status);

		$task = new SGBGTask();
		$task->prepare(SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_EXTRACT_STATE_FILE_NAME);

		if (!SGBLock::LockFile(SG_BACKUP_DIRECTORY . 'extract.lock')) return;

		$stateFile = $task->getStateFile();

		switch ($stateFile->getStatus()) {
			case SGBGStateFile::STATUS_READY:
				$this->log('Start extract');

				$stateFile->setBackupFileName($this->getBackupName());
				$stateFile->setBackedUpTables(array());
				$stateFile->setAction(SG_STATE_ACTION_RESTORING_FILES);
				$stateFile->setType(SG_STATE_TYPE_FILE);
				$stateFile->setStartTs($this->_actionStartTs);
				$stateFile->setOffset(0);
				$this->openFileExtract();
				break;

			case SGBGStateFile::STATUS_STARTED:
			case SGBGStateFile::STATUS_RESUME:
			case SGBGStateFile::STATUS_BUSY:
                $data = $stateFile->getData();
                $cdrSize = isset($data['cdrSize']) ? (int)$data['cdrSize'] : null;

			if ($cdrSize && $cdrSize != 0) {
                    $this->UpdateProgress();
                    $this->openFileExtract();
                }

                $stateFile->setStatus(SGBGStateFile::STATUS_DB);
                $stateFile->save(true);
                break;

            case SGBGStateFile::STATUS_DB:
                $stateFile->setStatus(SGBGStateFile::STATUS_DONE);
                $stateFile->save(true);
                break;

			case SGBGStateFile::STATUS_DONE:
				SGConfig::set('SG_RESTORE_FINALIZE', 1, true); // ????
				SGBackup::changeActionStatus($this->getActionId(), SG_ACTION_STATUS_FINISHED);
				$this->log('Memory peak usage ' . (memory_get_peak_usage(true) / 1024 / 1024) . 'MB');
				$this->log('Total duration ' . backupGuardFormattedDuration($this->_actionStartTs, time()));
				break;

			default:
				die('busy');
		}

		SGBLock::UnlockFile(SG_BACKUP_DIRECTORY . 'extract.lock');


	}

	public function prepareExtract($backupName, $status)
	{
		$this->setBackupName($backupName);
		$this->setRestorePath();
		$this->setFilesBackupPath();
		$this->setDatabaseBackupPath();
        if (!$this->getActionId()) $this->setExtractActionId();

		SGExternalRestore::getInstance()->prepare($this->getActionId());
		$this->prepareRestoreFolder();
		$this->setFilesBackupAvailable();
		$this->setRestoreLogPath();
		$this->prepareRestoreLogFile();
		$this->setLogFile();
		SGConfig::set('SG_RUNNING_ACTION', 1);
	}

	public function openFileExtract()
	{
		$task = new SGBGTask();
		$archive = new SGBGArchive($this->getFilesBackupPath());

		$task->prepare(SG_BACKUP_DIRECTORY . $this->getBackupName() . '/' . SG_EXTRACT_STATE_FILE_NAME);

		$archive->setTask($task);
		$archive->setDelegate($this);
		$archive->setLogFile($this->getLogFile());
		$this->_archive = $archive;
		$archive->open('r');
		$archive->extractTo($this->getRestorePath() . '/extracted/');
	}
}