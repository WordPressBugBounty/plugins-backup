<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(__DIR__.'/SGBGCache.php');

class SGBGFileHelper
{
	private static $instances = [];
	private $lastUpdateTs = 0;
	private $offset = 0;
	private $shouldCache = false;
	private $fileCache = null;
	private $handle = null;

	private function __construct()
	{
		$this->fileCache = new Cache(array($this, 'write'));
	}

	private function __clone()
	{

	}

	public function setShouldCache($shouldCache)
	{
		$this->shouldCache = $shouldCache;
		return $this;
	}

	public function getShouldCache()
	{
		return $this->shouldCache;
	}

	public function setHandle($handle)
	{
		$this->handle = $handle;
		return $this;
	}

	public function getHandle()
	{
		return $this->handle;
	}

	public function getFileCache()
	{
		return $this->fileCache;
	}

	public static function file($name)
	{
		if (!empty(self::$instances[$name]) && self::$instances[$name] instanceof self) {
			return self::$instances[$name];
		}

		self::$instances[$name] = new self();
		return self::$instances[$name];
	}

	public function write($string)
	{
		$handle = $this->getHandle();
		if (!is_resource($handle)) {
			return false;
		}

		$bytes = fwrite($handle, $string);
		fflush($handle);

		return $bytes;
	}

	public function close()
	{
		if ($this->getShouldCache()) {
			$this->fileCache->flush();
		}
	}

	public function fwrite($string)
	{
		if ($this->getShouldCache() && $this->fileCache->cache($string)) {
			return strlen($string);
		}

		return $this->write($string);
	}

	public function ftell()
	{
		return (ftell($this->getHandle()) + strlen($this->fileCache->getCacheData()));
	}

	public function fread($length)
	{
		return fread($this->getHandle(), $length);
	}
}
