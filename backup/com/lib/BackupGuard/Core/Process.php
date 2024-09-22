<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

class Process {

	const PATH = [
		'/usr/local/sbin',
		'/usr/local/bin',
		'/usr/sbin',
		'/usr/bin',
		'/sbin',
		'/bin'
	];

	private $_command;
	private $_pipes;
	private $_out;

	public function __construct($command) {
		$this->_command = $command;
		$this->_pipes = [];
		$this->_out = new stdClass();
		$this->_out->out = '';
		$this->_out->err = '';
	}

	private function _read_from_pipes(){
		$read = array($this->_pipes[1], $this->_pipes[2]);
		$write = NULL;
		$except = NULL;
		$n = @stream_select($read, $write, $except, 0, 500);
		if($n > 0) {
			do {
				$data = fread($this->_pipes[1], 8092);
				$this->_out->out .= $data;
			} while ($data);
			do {
				$data = fread($this->_pipes[2], 8092);
				$this->_out->err .= $data;
			} while ($data);
		}
	}

	public function execute(&$output=null, &$result_code=null) {
		$process = proc_open($this->_command, [ 0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"] ], $this->_pipes, getcwd(), [
			'PATH' => implode(":", self::PATH)
		]);

		if($process === false) {
			$output[] = "Can't open process using `proc_open`";
			$result_code=1;
			return false;
		}

		fclose($this->_pipes[0]); // close stdin

		stream_set_blocking($this->_pipes[1], 0);
		stream_set_blocking($this->_pipes[2], 0);

		while(true){
			$status = proc_get_status($process);
			$this->_read_from_pipes();
			if($status === FALSE || $status['running'] === FALSE) {
				fclose($this->_pipes[1]);
				fclose($this->_pipes[2]);
				proc_close($process);
				if($status === false) {
					$output[] = "Can't read status from process";
					$result_code=1;
					return false;
				}

				$result_code = $status['exitcode'];
				$out = trim($this->_out->out);
				if($result_code && trim($this->_out->err)) $out = trim($this->_out->err);
				$output = $out ? preg_split("/\r?\n/", $out) : [];
				break;
			}
		}

		return sizeof($output) ? $output[count($output)-1] : '';
	}

	public static function exec($command, &$output=null, &$result_code=null) {
		$process = new Process($command);
		return $process->execute($output, $result_code);
	}
}
