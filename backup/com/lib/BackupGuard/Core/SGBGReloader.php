<?php
if (!defined('WPINC')) die ('Direct access is not allowed');
require_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGChunks.php');


class SGBGReloader
{
	private static $instance = null;
	protected $interval = 2; //seconds
	protected $lastReloadTs = 0;

	private function __construct() {}

	private function __clone() {}

	public static function getInstance()
	{
		if (!self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function getInterval()
	{
		return $this->interval;
	}

	public function setInterval($interval)
	{
		$this->interval = $interval;
	}

	public function getLastReloadTs()
	{
		return $this->lastReloadTs;
	}

	public function setLastReloadTs($lastReloadTs)
	{
		$this->lastReloadTs = $lastReloadTs;
	}

	public function shouldReload()
    {
		return (time() - $this->getLastReloadTs() >= $this->getInterval());
	}

	protected function getCurrentUrl()
	{
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')?'https':'http';
		return ($scheme.'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
	}

    public function reload($stateFile = null) {

		// status resume
		if ($stateFile && $stateFile->getData('is_resume') == 1) {

			sleep(1);

			$Cron = new Cron();
			$Execute = new Execute();

			$cron_path = $Cron->getCommand();

			if ($cron_path) {

				$Cron->setCronLastTime(true);
				$res = $Execute->runCommand($cron_path, null);

				if (!$Execute->parseResultsCode($res)) return null;

			}

		}

}

}
