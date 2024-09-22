<?php
if (!defined('WPINC')) die ('Direct access is not allowed');


/*
@ class DirectoryTreeFile
@ version 1.1.0
@ updated 12/02/2021
*/

require_once(__DIR__.'/SGBGCacheableFile.php');
require_once(__DIR__.'/SGBGTask.php');

class SGBGDirectoryTreeFile extends SGBGCacheableFile
{
	private $rootPath = '';
	private $filesCount = 0;
	private $_excludePaths = array();
	private $_addPaths = array();
	private $dontExclude = array();

	/**
	 *
	 * Get the root path from which the tree starts.
	 *
	 * @return string  the root path
	 */
	public function getRootPath()
	{
		return $this->rootPath;
	}

	/**
	 *
	 * Set the root path from which the tree should start.
	 *
	 * @param string $rootPath  the root path
	 * @return null
	 */
	public function setRootPath($rootPath)
	{
		$this->rootPath = $rootPath;
	}

	/**
	 *
	 * Get the number of files and directories in the tree.
	 *
	 * @return int  the number of files in the tree
	 */
	public function getFilesCount()
	{
		return $this->filesCount;
	}

	/**
	 *
	 * Set the number of files and directories in the tree.
	 *
	 * @param int $filesCount  the number of files in the tree
	 * @return null
	 */
	public function setFilesCount($filesCount)
	{
		$this->filesCount = $filesCount;
	}


	public function addDontExclude($dontExclude)
	{
		$this->dontExclude[] = $dontExclude;
	}



	/**
	 *
	 * Get Excluded File Paths;
	 *
	 * @return array  Excluded File Paths
	 */
	public function getExcludedFilePaths()
	{
		return $this->_excludePaths;
	}

	/**
	 *
	 * Set Excluded File Paths
	 *
	 * @param array $excludePaths  excluded paths array
	 * @return null
	 */
	public function setExcludedFilePaths($excludePaths)
	{
		$this->_excludePaths = $excludePaths;
	}

	public function getAddedFilePaths()
	{
		return $this->_addPaths;
	}


	public function setAddedFilePaths($addPaths)
	{
		$this->_addPaths = $addPaths;
	}

	/**
	 *
	 * Get recursive directory iterator.
	 * Set desired offset first.
	 *
	 * @param int $dirOffest  directory iterator offset
	 * @return LimitIterator  instance of LimitIterator
	 */
	private function getDirectoryIteratorAtOffset($dirOffest)
	{
		$directory = new \RecursiveDirectoryIterator(
			$this->getRootPath(),
			FilesystemIterator::FOLLOW_SYMLINKS|FilesystemIterator::SKIP_DOTS|FilesystemIterator::UNIX_PATHS
		);

		$iterator = new \RecursiveIteratorIterator(
			$directory,
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		$iterator = new \LimitIterator($iterator, $dirOffest);

		return $iterator;
	}

	/**
	 *
	 * Get file at the specified index in the tree.
	 * First index starts from 0.
	 *
	 * @param int $index  index to seek in the tree
	 * @return string  file path
	 */
	public function getFileAtIndex($index)
	{
		$file = $this->getSplFileObject();
		$file->seek($index);
		return trim($file->current());
	}

	/**
	 *
	 * Save tree file.
	 * Iterate recursively starting from the root path.
	 * Create a plain tree file where each row represents a directory or file.
	 * Rows are separated by a newline symbol (\n).
	 *
	 * @return null
	 * @throws Exception
	 */
	public function save($backupInstance) {

		if (empty($this->getRootPath())) return;

		$_current_file_count = SG_BACKUP_DIRECTORY . $backupInstance->GetBackupFolderName() . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_FILE_COUNT;
		$_dir_offset = SG_BACKUP_DIRECTORY . $backupInstance->GetBackupFolderName() . DIRECTORY_SEPARATOR . SG_BACKUP_DIR_OFFSET;
		$_tree_done = SG_BACKUP_DIRECTORY . $backupInstance->GetBackupFolderName() . DIRECTORY_SEPARATOR . SG_BACKUP_TREE_GENERATOR_DONE;

		$this->open('a');

		$task = new SGBGTask();
		$task->prepare(SG_BACKUP_DIRECTORY.JBWP_DIRECTORY_STATE_FILE_NAME);

		//we consider this as a single operation, that's why we pass 1 as count
		$task->start(1);

		$filesCount = file_exists($_current_file_count) && filesize($_current_file_count) ? (int) file_get_contents($_current_file_count) : 0;
		$dirOffset = file_exists($_dir_offset) && filesize($_dir_offset) ? (int) file_get_contents($_dir_offset) : 0;
		$iterator = $this->getDirectoryIteratorAtOffset($dirOffset);

		foreach ($iterator as $info) {

			$backupInstance->verify_pid();
			if ($this->shouldExcludeFile($info->getPathname())) continue;
			if (!$this->shouldAddFile($info->getPathname())) continue;

			$slash =  $info->isDir() ? DIRECTORY_SEPARATOR : '';
			if ($info->isDir() && !SGBGArchiveHelper::is_dir_empty($info)) continue;

			$this->write($info->getPathname().$slash."\n");
			if (file_put_contents($_dir_offset, $iterator->getPosition() + 1)) $filesCount++;
			if (file_put_contents($_current_file_count, $filesCount)) $this->setFilesCount($filesCount);

			$this->getCache()->flush();

			/*
			$task->continueTask(function() {
				//flush cache before exit
				$this->getCache()->flush();
				$this->close();
			});
			*/

		}


		//use this just in case at the end, so all buffers are flushed
		$this->getCache()->flush();

		//increment state offset by one
		$task->endChunk();

		//finalize task, passing false doesn't remove the state file
		$task->end(false);

		if (file_put_contents($_tree_done, $filesCount)) $this->close();

	}

	private function pathWithoutRootDirectory($path)
	{
		return substr($path, strlen( rtrim(SGConfig::get('SG_APP_ROOT_DIRECTORY'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
	}

	private function shouldExcludeFile($path)
	{

		if (in_array($path, $this->dontExclude)) {
			return false;
		}
		//get the name of the file/directory removing the root directory
		$file = $this->pathWithoutRootDirectory($path);
		//check if file/directory must be excluded
		foreach ($this->getExcludedFilePaths() as $exPath) {
			$exPath = trim($exPath);
			$exPath = trim($exPath, DIRECTORY_SEPARATOR);
			if (strpos($file, $exPath) === 0) {
				return true;
			}
		}

		return false;
	}

	private function shouldAddFile($path)
	{

		if (in_array($path, $this->dontExclude)) {
			return true;
		}
		//get the name of the file/directory removing the root directory
		$file = $this->pathWithoutRootDirectory($path);
		//check if file/directory must be excluded
		foreach ($this->getAddedFilePaths() as $addPath) {
			$addPath = trim($addPath);
			$addPath = trim($addPath, DIRECTORY_SEPARATOR);
			if (strpos($file, $addPath) === 0) {
				return true;
			}
		}

		return false;
	}
}