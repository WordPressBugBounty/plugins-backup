<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

include_once(SG_LIB_PATH . 'BackupGuard/Core/Timing.php');


class Log {

	public $_file = null;
	public $_date = null;

	function __construct($file) {

		$this->setDate();
		$this->_file = $file;

	}

	public function getFile () {

		return $this->_file;

	}

	/**
	 * @throws Exception
	 */
	public function setDate () {

		$Timing = new Timing();
		$this->_date = $Timing->printTime(1, 0, null, false );

	}

	public function getDate () {

		return $this->_date;

	}


	public function write ($data) {

		if ($this->getFile()) @file_put_contents($this->getFile(), $this->getDate(). " " . $data  . " \n ", FILE_APPEND);

	}


}