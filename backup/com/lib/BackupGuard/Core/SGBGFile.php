<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
@ class File
@ version 1.0.4
@ updated 12/02/2021
*/

class SGBGFile
{
	private $path = '';
	private $handle = null;
	private $splFileObject = null;

	/**
	 *
	 * Constructor.
	 * After the constructor call, the file will be created if it doesn't exist.
	 *
	 * @param string|null $path  absolute file path
	 * @return null
	 */
	public function __construct(string $path = null) {

		if ($path && !self::isPathWritable($path)) {
			throw new Exception('File path is not writable. ---'.$path);
		}

		if ($path && is_dir($path)) {
			throw new Exception('Specified path is a directory. ---'.$path);
		}

		$this->path = $path;
	}

	/**
	 *
	 * Destructor.
	 *
	 * @return null
	 */
	public function __destruct()
	{
		$this->close();
	}

	/**
	 *
	 * Get the file path.
	 *
	 * @return string  the file path
	 */
	public function getPath()
	{
		return $this->path;
	}

	/**
	 *
	 * Check whether the provided path is writable.
	 *
	 * @return string  the file path
	 */
	public static function isPathWritable($path)
	{
		if (empty($path) || !is_string($path)) {
			return false;
		}

		//if path is a file and it doesn't exist, check parent folder
		if (substr($path, -1) != '/' && !file_exists($path)) {
			$path = dirname($path);
		}

		return is_writable($path);
	}

	/**
	 *
	 * Open the file for the specified operations.
	 *
	 * @return null
	 * @throws Exception
	 */
	public function open($mode, $lock = false)
	{

		if (!$this->getPath()) return;

		$this->handle = @fopen($this->getPath(), $mode);
		if ($lock) {
			$block = false;
			$flag = LOCK_EX;
			if(!$block) $flag |= LOCK_NB;
			$wouldblock = 0;
			$ret = flock($this->handle, $flag, $wouldblock);
		}
		// Error in edge cases, ticket #801788
		/*
		if (!is_resource($this->handle)) {
			throw new Exception('Could not open file: '.$this->getPath());
		}*/
	}

	/**
	 *
	 * Close file.
	 *
	 * @return null
	 */
	public function close($stateFile = null)
	{

		if (is_resource($this->handle)) {

			@fclose($this->handle);
			$this->handle = null;

			if ($stateFile && $stateFile->getData('is_resume') == 1) {

				$_cron_lock = SG_BACKUP_DIRECTORY . 'cron.lock';
				SGBLock::UnlockFile($_cron_lock);

			}

		}

	}

	/**
	 *
	 * Write data into the file.
	 *
	 * @param string $data  the data to write
	 * @return int  the number of bytes written to file
	 */
	public function write($data)
	{
		if (!is_resource($this->handle)) {
			return 0;
		}

		return @fwrite($this->handle, $data);
	}

	/**
	 *
	 * Read file contents up to length bytes.
	 *
	 * @param int $length  the number of bytes to read, if null, read until reaching the end of file
	 * @return string|false  the read string or false on failure
	 */
	public function read($length = null)
	{
		if (!is_resource($this->handle)) {
			return false;
		}

		if ($length === null) {
			$length = $this->getSize();
		}

		if ($length != 0)return @fread($this->handle, $length);
	}

	/**
	 *
	 * Get the current position of the file read/write pointer.
	 *
	 * @return int|false   the position of the file pointer or false on failure
	 */
	public function tell()
	{
		return @ftell($this->handle);
	}

	/**
	 *
	 * Change the file position indicator.
	 *
	 * @param int $offset  position to seek
	 * @param int $whence  SEEK_SET, SEEK_CUR or SEEK_END
	 * @return bool  upon success, returns true; otherwise, false
	 */
	public function seek($offset, $whence = SEEK_SET)
	{
		if ($this->handle && @fseek($this->handle, $offset, $whence) === 0) {
			return true;
		}

		return false;
	}

	/**
	 *
	 * Check whether we have reached end of file
	 *
	 * @return bool
	 */
	public function eof()
	{
		return feof($this->handle);
	}

	/**
	 *
	 * Get file size.
	 *
	 * @return int|false  the size of the file in bytes, or false in case of an error
	 */
	public function getSize()
	{
		return @filesize($this->getPath());
	}

	/**
	 *
	 * Delete file.
	 *
	 * @return null
	 */
	public function remove()
	{
		@unlink($this->getPath());
	}

	/**
	 *
	 * Check whether file exists.
	 *
	 * @return bool  true if the file exists; false otherwise
	 */
	public function exists()
	{
		return @file_exists($this->getPath());
	}

	/**
	 *
	 * Get an instance of SplFileObject for the current file.
	 *
	 * @return SplFileObject  instance of SplFileObject
	 */
	public function getSplFileObject()
	{
		if (!$this->splFileObject) {
			$this->splFileObject = new \SplFileObject($this->getPath());
		}

		return $this->splFileObject;
	}
}
