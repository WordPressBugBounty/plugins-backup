<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
 *
 * 	Builds a list of available PHP functions that can run background tasks
 *  proc_open is the most recommended one, last resort is fsockopen since it's domain
 *  based and we cannot assure the domain is resolving to the right place
 *
 */


class BGTask {

	CONST PROC_OPEN = 'proc_open';
	CONST EXEC = 'exec';
	CONST SHELL_EXEC = 'shell_exec';
	CONST FSOCKOPEN = 'fsockopen';

	public array $_available;

	public function getAvailable(): array {

		return $this->_available;

	}

	public function __construct() {

		$this->setAvailable();

	}


	// Checks if we can use the functions from ListFunctions and puts them in array
	private function setAvailable (): void {

		$available = [];
		foreach ($this->ListFunctions() as $func) $available[$func] = function_exists($func) ?? null;

		$this->_available = $available;

	}

	// Just creates an array of all function names
	private function ListFunctions (): array {

		return array (
			self::PROC_OPEN,
			self::EXEC,
			self::SHELL_EXEC,
			//self::FSOCKOPEN,

		);

	}

	// Needed to display as string for GUI front end, just print the functions
	public function listAvailableFunctions (): string {

		return implode(", ", $this->ListFunctions());
	}

}