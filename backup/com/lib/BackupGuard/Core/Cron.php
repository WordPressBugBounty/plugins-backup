<?php
if (!defined('WPINC')) die ('Direct access is not allowed');


/*
 * 	Executed background tasks through available cli functions
 *  Get's the available functions from BGtask class
 *
 */

class Cron {

	public  $_url = null;
	public  $_key = null;
	public  $_command = null;
	public  $_command_clean = null;
	public  $_base = null;
	public  $_cron_last_file = null;
	public  $_cron_last_time = null;
	public  $_current_time = null;
	public  $_diff = null;
	/**
	 * @var mixed|string|null
	 */
	private $_phpCli;

	public function __construct() {

		$this->setPHPCli();
		$this->setBase();
		$this->setCurrentTime();
		$this->setCronLastFile();
		$this->setCronLastTime();
		$this->setDiff();
		$this->setKey();
		$this->setUrl();
		$this->setCommand();

	}

	private function setPHPCli () {

		$this->_phpCli = SGConfig::get('SG_PHP_CLI_LOCATION') ? SGConfig::get('SG_PHP_CLI_LOCATION') : 'php';

	}

	private function setDiff (): void {

		$this->_diff = round (( $this->getCurrentTime() - $this->getCronLastTime()));

	}

	private function setCurrentTime (): void {

		$this->_current_time = time();

	}

	public function setCronLastTime ($force = false): void {

		if ($force) file_put_contents($this->getCronLastFile(), $this->getCurrentTime());
		$this->_cron_last_time = file_exists($this->getCronLastFile()) ? file_get_contents($this->getCronLastFile()) : $this->getCurrentTime();


	}


	private function setCronLastFile (): void {

		$file = SG_BACKUP_DIRECTORY . SG_BACKUP_CRON_LAST_FILE;
		file_exists($file) ?: file_put_contents($file,time());
		$this->_cron_last_file = $file;

	}

	private function setBase (): void {
		$this->_base = dirname(__FILE__, 5);
	}


	private function setCommand (): void {

		$this->_command = $this->_phpCli. ' ' . $this->getBase() . '/public/cron/cron.php > /dev/null 2>&1 &';
		$this->_command_clean = $this->getBase() . '/public/cron/cron.php';

	}

	private function setUrl (): void {

		$loc = basename($this->getBase());
		$key = $this->getKey();

		$this->_url = get_site_url() . "/wp-content/plugins/".$loc."/public/cron/cron.php?token={$key}";

	}

	private function setKey () {

		$this->_key = SGConfig::get('SG_BACKUP_CURRENT_KEY', true) ?? null;
	}

	public function getCronLastTime (): int
	{

		return (int) $this->_cron_last_time;

	}

	public function getCurrentTime (): int
	{

		return (int) $this->_current_time;

	}

	public function getCronLastFile ()
	{

		return  $this->_cron_last_file;

	}

	public function getBase () {

		return $this->_base;
	}

	public function getKey () {

		return $this->_key;

	}

	public function getDiff () {

		return $this->_diff;

	}

	public function getCommand ($clean = false) {

		if ($clean) return $this->_command_clean;
		return $this->_command;

	}

	public function getUrl () {

		return $this->_url;

	}

	public function getAvailable(): array {

		$BGTask = new BGTask();
		return $BGTask->getAvailable();

	}


}