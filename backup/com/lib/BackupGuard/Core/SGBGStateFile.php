<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
@ class StateFile
@ version 1.0.1
@ updated 24/01/2021
*/

require_once(__DIR__.'/SGBGJsonFile.php');
require_once(__DIR__.'/SGBGCacheableFile.php');

class SGBGStateFile extends SGBGCacheableFile
{
	use SGBGJsonFile;

	const STATUS_READY = 0;
	const STATUS_STARTED = 1;
	const STATUS_BUSY = 2;
	const STATUS_RESUME = 3;
	const STATUS_DB = 4;
	const STATUS_IMOPRTED = 5;
	const STATUS_MIGRATION = 6;
	const STATUS_DONE = 100;
	const STATUS_WARNINGS = 500;
	const STATUS_CANCELED = 501;

	private $count = 0;
	private $offset = 0;
	private $status;
	private $data = [];
	private $startTs = 0; // php 7.2 cannot use int
	private $updateTs = 0;
    private $action = null;
    private $inprogress = false;
    private $tablesToBackup = '';
    private $activeDirectory = '';
    private $currentUploadChunksCount = 0;
    private $totalUploadChunksCount;
    private $chunkSize = 0;
    private $progress = 0;
    private $storageType;
    private $pendingStorageUploads = array();
    private $token;
    private $type;
    private $backedUpTables;
    private $uploadId = 0;
    private $actionId = 0;
    private $parts = array();
    private $backupFileName= '';
    private $progressCursor;
    private $numberOfEntries;
	private $restoreMode;
	private $treeIterator;
	private $treeState;

	/**
	 *
	 * Constructor.
	 *
	 * @param string $path  absolute file path
	 * @return null
	 */
	public function __construct($path)
	{
		parent::__construct($path);

		$this->status = SGBGStateFile::STATUS_READY;
		$this->startTs = time();
		$this->updateTs = time();
	}

	public function getPath() {
		return parent::getPath();
	}


	/**
	 *
	 * Get count.
	 *
	 * @return int  the count
	 */
	public function getCount()
	{
		return $this->count;
	}

	/**
	 *
	 * Set count.
	 *
	 * @param int $count  the count
	 * @return null
	 */
	public function setCount($count)
	{
		$this->count = $count;
	}

    /**
     *
     * Get count.
     *
     * @return int  the count
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     *
     * Set count.     $this->archive->setLogEnabled($logEnabled);
     *
     * @param string $action  the action
     * @return null
     */
    public function setAction( $action )
    {
        $this->action = $action;
    }

    public function getInprogress()
    {
        return $this->inprogress;
    }

    public function setInprogress(  $value )
    {
        $this->inprogress = $value;
    }

    /**
	 *
	 * Get offest.
	 *
	 * @return int  the offset
	 */
	public function getOffset()
	{
		return $this->offset;
	}

	/**
	 *
	 * Set offset.
	 *
	 * @param int $offest  the offset
	 * @return null
	 */
	public function setOffset($offset)
	{
		$this->offset = max($offset, 0);
	}

	/**
	 *
	 * Get status.
	 *
	 * @return int  the status
	 */
	public function getStatus(): int
	{
		return $this->status;
	}

	/**
	 *
	 * Set status
	 *
	 * @param int $status  the status
	 * @return null
	 */
	public function setStatus(int $status)
	{
		$this->status = $status;
	}

    public function getTablesToBackup()
    {
        return $this->tablesToBackup;
    }


    public function setTablesToBackup($tablesToBackup)
    {
        $this->tablesToBackup = $tablesToBackup;
    }

	/**
	 *
	 * Get data.
	 * If provided key is null, return all data.
	 *
	 * @param string $key  the key
	 * @return mixed  the data related to the key
	 */
	public function getData($key = null)
	{
		if (empty($key)) {
			return $this->data;
		}

		if (isset($this->data[$key])) {
			return $this->data[$key];
		}

		return null;
	}

	/**
	 *
	 * Set data.
	 *
	 * @param string $key  the key
	 * @param mixed $data  the data
	 * @return null
	 */
	public function setData($key, $data)
	{
		if (empty($key)) {
			$this->data = $data;
			return;
		}

		$this->data[$key] = $data;
	}

	/**
	 *
	 * Get start timestamp.
	 *
	 * @return int  the starting timestamp
	 */
	public function getStartTs()
	{
		return $this->startTs;
	}

	/**
	 *
	 * Set start timestamp.
	 *
	 * @param int $startTs  the starting timestamp
	 * @return null
	 */
	public function setStartTs(int $startTs)
	{
		$this->startTs = $startTs;
	}

	/**
	 *
	 * Get update timestamp.
	 *
	 * @return int  the update timestamp
	 */
	public function getUpdateTs()
	{
		return $this->updateTs;
	}

	/**
	 *
	 * Set update timestamp.
	 *
	 * @param int $updateTs  the update timestamp
	 * @return null
	 */
	public function setUpdateTs($updateTs)
	{
		$this->updateTs = $updateTs;
	}

	/**
	 *
	 * Initialize state object from array.
	 *
	 * @param array $arr  array containing state data
	 * @return bool  false if any data is missing; otherwise true
	 */

    public function getActiveDirectory()
    {
        return $this->activeDirectory;
    }

    public function setActiveDirectory($activeDirectory)
    {
        $this->activeDirectory = $activeDirectory;
    }

    public function getChunkSize()
    {
        return $this->chunkSize;
    }

    public function setChunkSize($chunkSize)
    {
        $this->chunkSize = $chunkSize;
    }

    public function getProgress()
    {
        return $this->progress;
    }

    public function setProgress($progress)
    {
        $this->progress = $progress;
    }

    public function getStorageType()
    {
        return $this->storageType;
    }

    public function setStorageType($storageType)
    {
        $this->storageType = $storageType;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function getNumberOfEntries()
    {
        return $this->numberOfEntries;
    }

    public function setNumberOfEntries($numberOfEntries)
    {
        $this->numberOfEntries = $numberOfEntries;
    }

    public function getProgressCursor()
    {
        return $this->progressCursor;
    }

    public function setProgressCursor($progressCursor)
    {
        $this->progressCursor = $progressCursor;
    }

    public function getBackedUpTables()
    {
        return $this->backedUpTables;
    }

    public function setBackedUpTables($backedUpTables)
    {
        $this->backedUpTables = $backedUpTables;
    }

    public function getPendingStorageUploads()
    {
        return $this->pendingStorageUploads;
    }

    public function setPendingStorageUploads($pendingStorageUploads)
    {
        $this->pendingStorageUploads = $pendingStorageUploads;
    }

    public function getBackupFileName()
    {
        return $this->backupFileName;
    }

    public function setBackupFileName($backupFileName)
    {
        $this->backupFileName = $backupFileName;
    }

    public function setCurrentUploadChunksCount($currentUploadChunksCount)
    {
        $this->currentUploadChunksCount = $currentUploadChunksCount;
    }

    public function getCurrentUploadChunksCount()
    {
        return $this->currentUploadChunksCount;
    }


    public function setTotalUploadChunksCount($totalUploadChunksCount)
    {
        $this->totalUploadChunksCount = $totalUploadChunksCount;
    }

    public function getTotalUploadChunksCount()
    {
        return $this->totalUploadChunksCount;
    }

    public function setToken($token)
    {
        $this->token = $token;
    }

    public function getToken()
    {
        return $this->token;
    }



    public function setUploadId($id)
    {
        $this->uploadId = $id;
    }

    public function getUploadId()
    {
        return $this->uploadId;
    }

    public function setActionId($id)
    {
        $this->actionId = $id;
    }

    public function getActionId()
    {
        return $this->actionId;
    }

    public function setParts($parts)
    {
        $this->parts = $parts;
    }

    public function getParts()
    {
        return $this->parts;
    }

	public function getRestoreMode()
	{
		return $this->restoreMode;
	}

	public function setRestoreMode($restoreMode)
	{
		$this->restoreMode = $restoreMode;
	}

	public function setTreeState($treeState)
	{
		$this->treeState = $treeState;
	}

	public function getTreeState()
	{
		return $this->treeState;
	}
	public function setTreeIterator($treeIterator)
	{
		$this->treeIterator = $treeIterator;
	}

	public function getTreeIterator()
	{
		return $this->treeIterator;
	}


    public function initFromArray($arr)
	{
		if (!isset($arr['count']) ||
			!isset($arr['offset']) ||
			!isset($arr['status']) ||
			!isset($arr['data']) ||
			!isset($arr['startTs']) ||
			!isset($arr['actionId']) ||
			!isset($arr['uploadId']) ||
			!isset($arr['progress']) ||
			!isset($arr['activeDirectory']) ||
			!isset($arr['currentUploadChunksCount']) ||
			!isset($arr['parts']) ||
			!isset($arr['updateTs'])
		) {
            error_log(json_encode($arr));
			return false;
		}

		$this->setCount($arr['count']);
		$this->setOffset($arr['offset']);
		$this->setStatus($arr['status']);
		$this->setData(null, $arr['data']);
		$this->setStartTs($arr['startTs']);
		$this->setUpdateTs($arr['updateTs']);
		$this->setUploadId($arr['uploadId']);
		$this->setProgress($arr['progress']);
		$this->setAction($arr['action']);
		$this->setType($arr['type']);
        $this->setNumberOfEntries($arr['numberOfEntries']);
        $this->setProgressCursor($arr['progressCursor']);
		$this->setBackedUpTables($arr['backedUpTables']);
		$this->setActionId($arr['actionId']);
		$this->setParts($arr['parts']);
		$this->setChunkSize($arr['chunkSize']);
		$this->setActiveDirectory($arr['activeDirectory']);
		$this->setPendingStorageUploads($arr['pendingStorageUploads']);
		$this->setCurrentUploadChunksCount($arr['currentUploadChunksCount']);
		$this->setTotalUploadChunksCount($arr['totalUploadChunksCount']);
		$this->setRestoreMode($arr['restoreMode']);
		$this->setTreeState($arr['treeState']);
		$this->setTreeIterator($arr['treeIterator']);
		return true;
	}

	/**
	 *
	 * Convert state to array.
	 *
	 * @return array  array containing state data
	 */
	public function toArray()
	{

		$startTs = $this->getStartTs();
		//if ($startTs == 0) $startTs = $this->getUpdateTs();

		return array(
			'count' => $this->getCount(),
			'offset' => $this->getOffset(),
			'status' => $this->getStatus(),
			'data' => $this->getData(),
			'startTs' => $startTs,
			'updateTs' => $this->getUpdateTs(),
			'action' => $this->getAction(),
			'type' => $this->getType(),
			'backedUpTables' => $this->getBackedUpTables(),
			'actionId' => $this->getActionId(),
			'numberOfEntries' => $this->getNumberOfEntries(),
			'progressCursor' => $this->getProgressCursor(),
			'uploadId' => $this->getUploadId(),
			'progress' => $this->getProgress(),
			'parts' => $this->getParts(),
			'chunkSize' => $this->getChunkSize(),
			'activeDirectory' => $this->getActiveDirectory(),
			'currentUploadChunksCount' => $this->getCurrentUploadChunksCount(),
			'totalUploadChunksCount' => $this->getTotalUploadChunksCount(),
			'pendingStorageUploads' => $this->getPendingStorageUploads(),
			'restoreMode' => $this->getRestoreMode(),
			'treeIterator' => $this->getTreeIterator(),
			'treeState' => $this->getTreeState()
		);
	}

	/**
	 *
	 * Override parent callback function to open and close file immediately after writing to file.
	 * We don't keep the file open, since next writing may come very late.
	 *
	 * @param string $data  data to write to file
	 * @return null
	 */
	public function writeToFile($data)
	{

		$this->open('w+');

		parent::writeToFile($data);

		$this->close();
	}

	/**
	 *
	 * Load state file.
	 * Decode the JSON file and store all local variables.
	 *
	 * @return null
	 */
	public function load($lock = true)
	{
		if (!$this->exists()) {
			return;
		}

		$this->open('r', $lock);

		//trait JsonFile method called here
		$contents = $this->getDecodedContents();

		if (!empty($contents)) {
			$this->initFromArray($contents);
		}

		$this->close();
	}

	/**
	 *
	 * Save state file.
	 * Encode the data and store in the file.
	 *
	 * @param bool $forceFlush  whether to flush buffer at the end
	 * @return null
	 */
	public function save($forceFlush = false)
	{
		$this->getCache()->clear();

		$this->setUpdateTs(time());

		//trait JsonFile method called here
		$this->encodeAndSetContents($this->toArray());

		if ($forceFlush) {
			$this->getCache()->flush();
		}
	}
}
