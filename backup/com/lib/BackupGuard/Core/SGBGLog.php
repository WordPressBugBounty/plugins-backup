<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
@ class DirectoryTreeFile
@ version 1.1.0
@ updated 12/02/2021
*/

require_once(__DIR__.'/SGBGCacheableFile.php');
require_once(__DIR__.'/SGBGTask.php');

class SGBGLog extends SGBGCacheableFile
{

    public function save($logData)
    {
        $this->open('a');
        if($logData) {
            $this->write(date("Y-m-d H:i:s", time()).": ".$logData."\n");
        } else {
            $this->write($logData."\n");
        }

		$this->close();

        //flush cache before exit
    }
}
