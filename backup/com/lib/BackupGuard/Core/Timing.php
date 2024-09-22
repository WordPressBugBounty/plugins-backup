<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

class Timing {

	private $_now;
	private $_UTC = 'UTC';
	private $_wpzone;


	public function __construct() {

		$this->setNow();
		$this->setWpTimeZone();
	}

	/**
	 * @throws Exception
	 */
	private function setNow () {

		$this->_now = time();

	}

	private function setWpTimeZone () {

		$this->_wpzone = function_exists('wp_timezone_string') ? wp_timezone_string() : $this->_UTC;

	}

	public function getWPTimeZone () {

		return $this->_wpzone;


	}

	/**
	 * @throws Exception
	 */
	public function printTime ($WordpressTime = false, $strTime = false, $timeStamp = false, $utc = false) {

		// $WordpressTime = Set time based on Wordpress's time zone
		// $strTime = true to display strtotime format
		// $timestamp = if set, will convert a given timestamp and will not use "now"
		// $utc = if false, will set timezone


		$newDateTime = new DateTime();
		$timeStamp = $timeStamp ?? $this->_now;
		$timeZone = $WordpressTime ? $this->getWPTimeZone() : $this->_UTC;

		$newDateTime->setTimestamp($timeStamp);
		if (!$utc) $newDateTime->setTimezone( new DateTimeZone($timeZone) ) ;
		if ($strTime) return strtotime($newDateTime->format('Y-m-d H:i:s'));
		return $newDateTime->format('Y-m-d H:i:s');

	}

	public function EpochUTC () {

		return $this->printTime(false, true, null, false);

	}

}