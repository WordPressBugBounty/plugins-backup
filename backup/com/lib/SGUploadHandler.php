<?php

namespace BackupGuard\Upload;

class Handler
{
    private $_data        = null;
    private $_fileName    = "";
    private $_tmpFileName = "";

    public function __construct($data)
    {
        $this->_data = $data;
        $this->import();
    }

    private function import()
    {
        $this->_fileName    = $this->_data['files']['name'][0];
        $this->_tmpFileName = $this->_data['files']['tmp_name'][0];

        if (substr($this->_fileName, -5) != '.sgbp') {
            $this->_fileName .= '.sgbp';
        }

        $dirPath = $this->getDestinationDirPath();
        $file    = $dirPath . $this->_fileName;

        $data = file_get_contents($this->_tmpFileName);
        file_put_contents($file, $data, FILE_APPEND);
    }

    private function getDestinationDirPath()
    {
        return SG_BACKUP_DIRECTORY;
    }

    private function getDestinationDirUrl()
    {
        return SG_BACKUP_DIRECTORY_URL;
    }
}
