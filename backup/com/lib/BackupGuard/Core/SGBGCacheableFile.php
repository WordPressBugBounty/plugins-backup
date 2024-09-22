<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
@ class CacheableFile
@ version 1.0.0
@ updated 23/01/2021
*/

require_once(__DIR__.'/SGBGFile.php');
require_once(__DIR__.'/SGBGCache.php');

class SGBGCacheableFile extends SGBGFile
{
	private $cache = null;

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

		$this->cache = new SGBGCache(array($this, 'writeToFile'));
	}

	/**
	 *
	 * Get Cache object.
	 *
	 * @return Cache  instance of Cache class
	 */
	public function getCache()
	{
		return $this->cache;
	}

	/**
	 *
	 * Callback called when Cache flushes.
	 *
	 * @param string $data  data being flushed
	 * @return null
	 */
	public function writeToFile($data)
	{
		parent::write($data);
	}

	/**
	 *
	 * Override parent write function to cache instead of directly writing to file.
	 *
	 * @param string $data  data to write to file
	 * @return int  the number of bytes cached or written to file
	 */
	public function write($data)
	{
		$this->getCache()->cache($data);
		return strlen($data);
	}
}
