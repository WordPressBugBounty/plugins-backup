<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
 *  Execute cli commands
 */

class Execute {

	public array $_available = array();

	public function __construct() {

		$this->setAvailable();

	}

	/*
	 *  no crontab return exit code 1, but we can still add one
	 *  so we need to identify the situation
	 */
	public function isNoCrontab ($res) {

		$output = $res['output'][0] ?? null;
		if (!$output) return false;

		return preg_match('/no\s*crontab\s*for/i', $output);

	}

	public function parseResultsCode($res): bool {

		if (!is_array($res)) return false;
		$code = $res['code'] ?? 1;

		if ($code == 1)  return $this->isNoCrontab($res);
		if ($code != 0) return false;

		return true;

	}


	private function setAvailable() {

		$BGTask = new BGTask();
		$this->_available = $BGTask->getAvailable();

	}

	private function getAvailable(): array {

		return $this->_available;

	}

	private function resOutput ($method = null, $output = null, $code = null): array {


		// We have to convert lines into array for unity
		if ($output && is_string($output)) $output = preg_split("/\r\n|\n|\r/", $output);


		$result['method'] = $method;
		$result['output'] = $output;
		$result['code'] = $code;

		return $result;

	}

	public function runCommand ($cmd = null, $url = null, $clean = false): array {

		/*
		 *  Execute a shell commnad
		 *  $url - will be used for fsockopen
		 *
		 *  We cannot use 'system' as a function since it only returns the last line from the output
		 *
		 */

		$available = $this->getAvailable();

		if (!$clean) $cmd = function_exists('escapeshellcmd') ? escapeshellcmd($cmd) : $cmd;


		$result = array();

		foreach ($available as $key => $value) {


			if (!$value) continue;

			switch ($key) {

				case 'proc_open':

					Process::exec($cmd, $o, $c);
					$result = $this->resOutput($key, $o, $c);

					break 2;

				case 'shell_exec' :

					$output = shell_exec($cmd);
					$code = $output ? 0 : 1; // there is no return code so we are guessing based on output
					$result = $this->resOutput($key, $output, $code);
					break 2;

				case 'exec' :


					exec($cmd, $output, $retval);
					$result = $this->resOutput($key, $output, $retval);
					break 2;

				case 'fsockopen' : // last restort, not reliable (need a real domain)

					$req = new NonBlockingHttpClientService();
					$req->doRequest($url);

					break 2;

			}

		}

		// result['code'] : 0 Success, 1 Error
		return $result;

	}

}