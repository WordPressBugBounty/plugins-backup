<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
@ class Cache
@ version 1.0.0
@ updated 23/01/2021
*/

class SGBGCache
{
	const CACHE_MODE_SIZE = 1;
	const CACHE_MODE_TIMEOUT = 2;

	private $cacheMode = 0;
	private $cacheSize = 4000000; //4mb
	private $cacheTimeout = 5; //seconds
	private $cacheData = '';
	private $lastFlushTs = 0;
	private $flushCallable = null;

	/**
	 *
	 * Constructor.
	 *
	 * @param callable $flushCallable  the function to call when cache is flushed (data will be passed as argument)
	 * @return null
	 */
	public function __construct($flushCallable = null)
	{
		//set default mode
		$this->setCacheMode(self::CACHE_MODE_SIZE);

		//we set flush timestamp to now so we don't flush the first coming data
		$this->setLastFlushTs(time());

		$this->flushCallable = $flushCallable;
	}

	/**
	 *
	 * Set the cache mode. 2 mods are available:
	 *
	 * 1) CACHE_MODE_SIZE: cache data until the specified size (in bytes) is reached.
	 * 2) CACHE_MODE_TIMEOUT: cache data until the specified timeout (in seconds) is reached.
	 *
	 * @param int $cacheMode  the cache mode (use bitwise OR for multiple modes)
	 * @return null
	 */
	public function setCacheMode($cacheMode)
	{
		$this->cacheMode = $cacheMode;
	}

	/**
	 *
	 * Get the cache mode.
	 * Use bitwise AND to check if individual modes are available.
	 *
	 * @return int  the cache mode
	 */
	public function getCacheMode()
	{
		return $this->cacheMode;
	}

	/**
	 *
	 * Set the cache size.
	 *
	 * @param int $cacheSize  the cache size (in bytes)
	 * @return null
	 */
	public function setCacheSize($cacheSize)
	{
		$this->cacheSize = $cacheSize;
	}

	/**
	 *
	 * Get the cache size.
	 *
	 * @return int  the cache size (in bytes)
	 */
	public function getCacheSize()
	{
		return $this->cacheSize;
	}

	/**
	 *
	 * Set the cache timeout inerval.
	 *
	 * @param int $cacheTimeout  the cache timeout (in seconds)
	 * @return null
	 */
	public function setCacheTimeout($cacheTimeout)
	{
		$this->cacheTimeout = $cacheTimeout;
	}

	/**
	 *
	 * Get the cache timeout interval.
	 *
	 * @return int  the cache timeout (in seconds)
	 */
	public function getCacheTimeout()
	{
		return $this->cacheTimeout;
	}

	/**
	 *
	 * Set the cache data.
	 *
	 * @param string $cacheData  the cache data
	 * @return null
	 */
	protected function setCacheData($cacheData)
	{
		$this->cacheData = $cacheData;
	}

	/**
	 *
	 * Get the cached data in buffer.
	 *
	 * @return string  the cached data
	 */
	public function getCacheData()
	{
		return $this->cacheData;
	}

	/**
	 *
	 * Set the last time data was flushed.
	 *
	 * @param int $lastFlushTs  the last flush timestamp
	 * @return null
	 */
	protected function setLastFlushTs($lastFlushTs)
	{
		$this->lastFlushTs = $lastFlushTs;
	}

	/**
	 *
	 * Get the last time data was flushed.
	 *
	 * @return int  the last flush timestamp
	 */
	public function getLastFlushTs()
	{
		return $this->lastFlushTs;
	}

	/**
	 *
	 * Check whether cache should flush.
	 *
	 * @return bool
	 */
	public function shouldFlush()
	{
		if ($this->getCacheMode() & self::CACHE_MODE_SIZE) {
			if (strlen($this->cacheData) >= $this->getCacheSize()) {
				return true;
			}
		}

		if ($this->getCacheMode() & self::CACHE_MODE_TIMEOUT) {
			if (time() - $this->getLastFlushTs() >= $this->getCacheTimeout()) {
				return true;
			}
		}

		return false;
	}

	/**
	 *
	 * Cache data.
	 *
	 * @param string $data  the data to cache
	 * @return null
	 */
	public function cache($data)
	{
		if (empty($data) || !is_string($data)) {
			return;
		}

		$this->cacheData .= $data;

		if ($this->shouldFlush()) {
			$this->setLastFlushTs(time());
			$this->flush();
		}
	}

	/**
	 *
	 * Flush data in cache buffer.
	 * The flush callable will be called and the cache buffer will be cleared.
	 *
	 * @return null
	 */
	public function flush()
	{
		$data = $this->getCacheData();

		if (empty($data)) {
			return;
		}

		if (is_callable($this->flushCallable)) {
			call_user_func($this->flushCallable, $data);
		}

		$this->clear();
	}

	/**
	 *
	 * Clear cache buffer without flushing.
	 *
	 * @return null
	 */
	public function clear()
	{
		$this->setCacheData('');
	}
}
