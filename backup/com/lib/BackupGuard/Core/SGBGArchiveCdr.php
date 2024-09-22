<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(__DIR__.'/SGBGCacheableFile.php');
require_once(__DIR__.'/SGBGArchiveHelper.php');

class SGBGArchiveCdr extends SGBGCacheableFile
{
	private $count = 0;

	public function getCount()
	{
		return $this->count;
	}

	public function setCount($count)
	{
		$this->count = $count;
	}

	public function addFile($filename, $offset, $compressedLength, $uncompressedLength, $fileChunks, $logCallable, $crcData)
	{
		$logCallable('Start write CDR item');

		$rec = SGBGArchiveHelper::packToLittleEndian(abs(crc32($crcData)), 4); //crc (not used in this version)

		$filenameLen = strlen($filename);
		$logCallable('Filename length: '.$filenameLen);
		$rec .= SGBGArchiveHelper::packToLittleEndian($filenameLen, 2);

		$logCallable('Filename: '.$filename);
		$rec .= $filename;

		//file offset, compressed length, uncompressed length, all of them are written in 8 bytes to cover big integer size
		$logCallable('Offset: '.$offset);
		$rec .= SGBGArchiveHelper::packToLittleEndian($offset, 8);

		$logCallable('Compressed length: '.$compressedLength);
		$rec .= SGBGArchiveHelper::packToLittleEndian($compressedLength, 8);

		$logCallable('Uncompressed length: '.$uncompressedLength);
		$rec .= SGBGArchiveHelper::packToLittleEndian($uncompressedLength, 8); //uncompressed size (not used in this version)

        if (!empty($fileChunks)) {
            $chunksCount = count($fileChunks);
            $logCallable('Number of chunks: ' . $chunksCount);
            $rec .= SGBGArchiveHelper::packToLittleEndian($chunksCount, 4);

            foreach ($fileChunks as $fileChunk) {
                $logCallable('Chunk: Start(' . $fileChunk['start'] . ') - Size(' . $fileChunk['size'] . ')');
                //start and size are written in 8 bytes to cover big integer size
                $rec .= SGBGArchiveHelper::packToLittleEndian($fileChunk['start'], 8);
                $rec .= SGBGArchiveHelper::packToLittleEndian($fileChunk['size'], 8);
            }
        } else {
            $rec .= SGBGArchiveHelper::packToLittleEndian(0, 8);
            $rec .= SGBGArchiveHelper::packToLittleEndian(0, 8);
        }


		$result = $this->write($rec);
		if ($result === FALSE) {
			throw new Exception('Failed to write in cdr.');
		}

		$this->count++;

		$logCallable('End write CDR item: '.$result.' bytes');
	}
}
