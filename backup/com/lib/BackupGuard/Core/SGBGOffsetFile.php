<?php

class SGBGOffsetFile
{
	public $handler;

	public $file;

	public function __construct($file) {
		$this->open($file, 'a+');
	}

	public function open($file, $mode) {
		$this->file = $file;
		$this->handler = fopen($file, $mode);
	}

	public function add_offset($offset) {
		fwrite($this->handler, $offset);
	}

	public function read_file($lines)
	{
		$handle = $this->handler;
		$linecounter = $lines;
		$pos = -2;
		$beginning = false;
		$text = array();
		while ($linecounter > 0) {
			$t = " ";
			while ($t != "\n") {
				if(fseek($handle, $pos, SEEK_END) == -1) {
					$beginning = true; break; }
				$t = fgetc($handle);
				$pos --;
			}
			$linecounter --;
			if($beginning) rewind($handle);
			$text[$lines-$linecounter-1] = fgets($handle);
			if($beginning) break;
		}
		return array_reverse($text);
	}


}