<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(__DIR__ . '/ISGArchiveDelegate.php');
require_once(__DIR__ . '/SGBGArchiveHelper.php');
require_once(__DIR__ . '/SGBGArchiveCdr.php');
require_once(__DIR__ . '/SGBGCacheableFile.php');
require_once(__DIR__ . '/SGBGTask.php');
require_once(__DIR__ . '/SGBGLog.php');

class SGBGArchive extends SGBGCacheableFile
{
	const VERSION    = 5;
	const CHUNK_SIZE = 4 * 1024 * 1024; //4mb
	private $currentFileOffset = 0;
	private $cdrFile           = null;
	private $delegate          = null;
	private $task              = null;
	private $logFile           = null;
	private $logEnabled        = false;
	private $_excludePaths     = array();
	private $_warningsFound    = false;
	private $_options          = array();
	private $_cdrOffset        = 0;
	private $_cdrSize          = 0;

	public function __construct($path)
	{
		parent::__construct($path);
	}

	public function setLogFile($logFile)
	{
		$this->logFile = $logFile;
	}

	public function getLogFile()
	{
		return $this->logFile;
	}

	public function setLogEnabled($logEnabled)
	{
		$this->logEnabled = $logEnabled;
	}

	public function getLogEnabled()
	{
		return $this->logEnabled;
	}

	public function setExcludePaths($paths)
	{
		$this->_excludePaths = $paths;
	}

	public function getExcludePaths()
	{
		return $this->_excludePaths;
	}

	public function setOptions($options)
	{
		$this->_options = $options;
	}

	public function getOptions()
	{
		return $this->_options;
	}

	public function log($logData, $forceWrite = false)
	{
		$Log = new Log($this->getLogFile());
		$Log->write($logData);
	}

	/**
	 * @throws Exception
	 */
	public function open($mode, $lock = false)
	{
		//only write (w) and read (r) modes supported
		//convert all modes to binary
		if (strpos($mode, 'w') === 0) {
			$mode = 'ab';
		} else if (strpos($mode, 'r') === 0) {
			$mode = 'rb';
		} else {
			throw new Exception('Archive open mode not supported');
		}

		$this->log('Open archive (mode: ' . $mode . ')');

		parent::open($mode);

		if ($mode == 'ab') {
			$this->openCdrFile($this->getPath() . '.cdr');
		}
	}

	public function close($stateFile = null)
	{
		if ($this->cdrFile) {
			$this->log('Close CDR file');

			$this->cdrFile->close();
		}

		$this->log('Close archive');
		parent::close($stateFile);

	}

	private function openCdrFile($cdrPath)
	{
		$this->log('Open CDR file');

		$file = new SGBGArchiveCdr($cdrPath);
		$file->getCache()->setCacheMode(SGBGCache::CACHE_MODE_TIMEOUT | SGBGCache::CACHE_MODE_SIZE);
		$file->getCache()->setCacheTimeout(5);
		$file->getCache()->setCacheSize(4000000);
		$file->open('ab');

		//load cdr size from state
		$cdrSize = (int) $this->task->getStateFile()->getData('cdr_size');
		$file->setCount($cdrSize);

		$this->cdrFile = $file;
	}

	public function setDelegate($delegate)
	{
		$this->delegate = $delegate;
		if ($delegate instanceof ISGArchiveDelegate) {

		}
	}

	public function setTask($task)
	{
		if ($task instanceof SGBGTask) {
			$this->task = $task;
		}
	}

	private function willAddFile($filename, $path)
	{
		if ($this->delegate) {
			$this->delegate->willAddFile($filename);
		}
	}

	private function willAddFileChunk($filename, $path)
	{
		if ($this->delegate) {
			$this->delegate->willAddFileChunk($filename);
		}
	}

	private function didAddFileChunk($filename, $path, $chunk)
	{
		if ($this->delegate) {
			$this->delegate->didAddFileChunk($filename, $chunk);
		}
	}

	private function didAddFile($filename, $path)
	{
		if ($this->delegate) {
			$this->delegate->didAddFile($filename);
		}
	}

	public function didFindWarnings()
	{
		return $this->_warningsFound;
	}

	public function addFileFromPath($filename, $path, $i)
	{
		if ($path != '') {

			$this->log('Start add file ' . $filename, true);
			$this->log('Parent Iteration: ' . $i);

			//$this->getLogFile()->getCache()->flush();

			$this->willAddFile($filename, $path);

			$fp = @fopen($path, 'rb');
			if ($fp === false) {
				$this->warn('Failed to open file: ' . $path);
				return;
			}

			$fileSize  = SGBGArchiveHelper::realFilesize($path);
			$stateFile = $this->task->getStateFile();

			$fileChunks              = (array) $stateFile->getData('chunks');
			$chunkOffset             = (int) $stateFile->getData('chunk_offset');
			$srcFileOffset           = (int) $stateFile->getData('src_file_offset');
			$this->currentFileOffset = (int) $stateFile->getData('file_offset');

			$this->log('File size: ' . $fileSize, true);
			$this->log('File offset in archive: ' . $this->currentFileOffset, true);

			if ($srcFileOffset) {
				fseek($fp, $srcFileOffset);
				$this->log('Seek src file: ' . $srcFileOffset);
			} else {
				$this->addFileHeader();
			}

			//read file by chunks
			while (!feof($fp)) {
				$this->log('Start add file chunk', true);
				//$this->getLogFile()->getCache()->flush();
				$this->willAddFileChunk($filename, $path);

				$data = @fread($fp, self::CHUNK_SIZE);
				if ($data === false) {
					$this->warn('Failed to read file: ' . basename($filename));
				} else if (empty($data)) {
					break;
				}

				$chunk = $this->addFileChunk($data, $chunkOffset);
				$fileChunks[] = $chunk;
				$chunkOffset += $chunk['size'];

				$this->didAddFileChunk($filename, $path, $chunk);

				$this->log('End add file chunk: Start(' . $chunk['start'] . ') - Size(' . $chunk['size'] . ')');
				$this->log('Next chunk offset: ' . $chunkOffset);


				$this->task->continueTask(function () use ($fileChunks, $chunkOffset, &$stateFile, &$fp) {

					$this->log('Exit task (to resume later)', true);

					$this->getCache()->flush();
					$this->cdrFile->getCache()->flush();

					$stateFile->setData('chunks', $fileChunks);
					$stateFile->setData('chunk_offset', $chunkOffset);
					$srcFileOffset = ftell($fp);
					$stateFile->setData('src_file_offset', $srcFileOffset);
					$stateFile->setData('file_offset', $this->currentFileOffset);
					$this->log('Src file offset: ' . $srcFileOffset, true);
					$stateFile->setData('is_resume', 1);
					//$this->getLogFile()->getCache()->flush();
					$stateFile->save(true);

					//$this->getLogFile()->close();
					//$this->updateProgress();
					$this->close($stateFile);

					@fclose($fp);
				});
			}

			@fclose($fp);

			$fp = @fopen($path, 'rb');
			$firstBytes = fread($fp, 100);
			fseek($fp, -100, SEEK_END);
			$lastBytes = fread($fp, 100);

			@fclose($fp);
			$crcData = $firstBytes . $lastBytes;

			$this->cdrFile->addFile(
				$filename,
				$this->currentFileOffset,
				$chunkOffset, //total compressed length
				$fileSize,
				$fileChunks,
				array($this, 'log'),
				$crcData
			);

			$fileHeaderSize = 4;
			$this->currentFileOffset += $fileHeaderSize + $chunkOffset;
			$this->log('Next file offset in archive will be: ' . $this->currentFileOffset, true);

			$stateFile->setData('file_offset', $this->currentFileOffset);
			$stateFile->setData('cdr_size', $this->cdrFile->getCount());

			//reset state file data
			$stateFile->setData('chunks', array());
			$stateFile->setData('chunk_offset', 0);
			$stateFile->setData('src_file_offset', 0);
			$stateFile->setData('is_resume', 0);
			$stateFile->save(true);

			$this->didAddFile($filename, $path);
			$this->log('End add file ' . $path . $filename, true);
		}
	}

	public function addEmptyDirectory($filename)
	{
		//IMPORTANT: empty directory names must end with a slash
		$filename = rtrim($filename, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

		if ($this->delegate) {
			$this->delegate->willAddFile($filename);
		}

		$stateFile = $this->task->getStateFile();

		$fileHeaderSize = $this->addFileHeader();


		$this->cdrFile->addFile(
			$filename,
			$this->currentFileOffset,
			0,
			0,
			array(),
			array($this, 'log'),
			""
		);

		$this->currentFileOffset += $fileHeaderSize;
		$this->log('Next directory offset in archive will be: ' . $this->currentFileOffset);

		$stateFile->setData('file_offset', $this->currentFileOffset);
		$stateFile->setData('cdr_size', $this->cdrFile->getCount());

		//reset state file data
		$stateFile->setData('chunks', array());
		$stateFile->setData('chunk_offset', 0);
		$stateFile->setData('src_file_offset', 0);
		$stateFile->save(false);


		if ($this->delegate) {
			$this->delegate->didAddFile($filename);
		}

		$this->log('End add Empty Directory');
	}

	private function addFileChunk($data, $chunkOffset)
	{
		$this->log('Read: ' . strlen($data) . ' bytes');

		$data = gzdeflate($data);

		$this->log('Compressed: ' . strlen($data) . ' bytes');

		$result = $this->write($data);

		$this->log('Written: ' . $result . ' bytes');

		return array(
			'start' => $chunkOffset,
			'size'  => strlen($data)
		);
	}

	private function addFileHeader()
	{
		$this->log('Start add header');

		$extra = '';

		$extraLengthInBytes = 4;
		$this->write(SGBGArchiveHelper::packToLittleEndian(strlen($extra), $extraLengthInBytes) . $extra);

		$headerSize = ($extraLengthInBytes + strlen($extra));

		$this->log('End add header: ' . $headerSize . ' bytes');

		return $headerSize;
	}

	private function addFooter()
	{
		$footer = '';

		//save version
		$footer .= SGBGArchiveHelper::packToLittleEndian(self::VERSION, 1);

		$extra = '';

		if ($this->delegate) {
			$extra = $this->delegate->getArchiveExtraData();
		}

		//extra size
		$footer .= SGBGArchiveHelper::packToLittleEndian(strlen($extra), 4) . $extra;

		//save cdr size
		$cdrSize = (int) $this->task->getStateFile()->getData('cdr_size');
		$footer  .= SGBGArchiveHelper::packToLittleEndian($cdrSize, 4);

		$this->write($footer);

		//save cdr
		$cdrLen = $this->writeCdr();

		//save offset to the start of footer
		$len = $cdrLen + strlen($extra) + 13;
		$this->write(SGBGArchiveHelper::packToLittleEndian($len, 4));
	}

	private function writeCdr()
	{
		$this->cdrFile->getCache()->flush();
		$this->cdrFile->close();

		$cdrLen = 0;
		$this->cdrFile->open('rb');

		while (!$this->cdrFile->eof()) {
			$data   = $this->cdrFile->read(self::CHUNK_SIZE);
			$cdrLen += strlen($data);
			$this->write($data);
		}

		//@fclose($fp);
		$this->cdrFile->close();
		$this->cdrFile->remove();

		return $cdrLen;
	}

	public function finalize()
	{
		$this->addFooter();
		$this->getCache()->flush();

		$this->log('Finalized');

		//$this->getLogFile()->getCache()->flush();
		$this->updateProgress();
		$this->close();

	}

	public function getHeaders()
	{
		return $this->extractHeaders();
	}

	private function extractHeaders()
	{
		$this->log('Start extract headers');

		//read offset
		$this->seek(-4, SEEK_END);
		$offset = hexdec(SGBGArchiveHelper::unpackLittleEndian($this->read(4), 4));

		$this->log('Footer offset: ' . $offset);

		//read version
		$this->seek(-$offset, SEEK_END);
		$version = hexdec(SGBGArchiveHelper::unpackLittleEndian($this->read(1), 1));

		$this->log('Version: ' . $version);

//        if ($version < self::VERSION) {
//            throw new Exception('Invalid SGArchive file version.');
//        }

		//read extra size
		$extraSize = hexdec(SGBGArchiveHelper::unpackLittleEndian($this->read(4), 4));

		$this->log('Extra size: ' . $extraSize);

		//read extra
		$extra = '';
		if ($extraSize > 0) {
			$extra = $this->read($extraSize);
		}

		$this->log('Extra read: ' . strlen($extra) . ' bytes');

		if(is_string($extra)) {
			$extra = json_decode($extra, true);
			if(is_string($extra['tables'])) {
				$extra['tables'] = json_decode($extra['tables'], true);
			}
		}

		if ($this->delegate) {
			$this->delegate->didExtractArchiveHeaders($version, $extra);
		}

		if (is_array($extra)) {
			$extra['versions'] = $version;
		}

		$this->log('End extract headers');

		return $extra;
	}

	public function getFilesList()
	{
		$this->extractHeaders();

		$list    = array();
		$cdrSize = hexdec(SGBGArchiveHelper::unpackLittleEndian($this->read(4), 4));

		for ($i = 0; $i < $cdrSize; $i++) {
			$list[] = $this->getNextCdrItem();
		}

		return $list;
	}

	private function getNextCdrItem()
	{
		$this->log('Start read CDR item');

		//read crc (not used in this version)
		$crc = $this->read(4);

		//read filename
		$filenameLen = SGBGArchiveHelper::unpackLittleEndian($this->read(2), 2);
		$filenameLen = hexdec($filenameLen);

		$this->log('Filename length: '.$filenameLen);

		if ($filenameLen > 0) {
			$filename = $this->read($filenameLen);


			if ($this->delegate) {
				$filename = $this->delegate->getCorrectCdrFilename($filename);
			}

			$this->log('Filename: ' . $filename);

			//read file offset
			$fileOffsetInArchive = SGBGArchiveHelper::unpackLittleEndian($this->read(8), 8);
			$fileOffsetInArchive = hexdec($fileOffsetInArchive);

			$this->log('File offset: ' . $fileOffsetInArchive);

			//read compressed length
			$compressedLength = SGBGArchiveHelper::unpackLittleEndian($this->read(8), 8);
			$compressedLength = hexdec($compressedLength);

			$this->log('Compressed length: ' . $compressedLength);

			//read uncompressed length
			$uncompressedLength = SGBGArchiveHelper::unpackLittleEndian($this->read(8), 8);
			$uncompressedLength = hexdec($uncompressedLength);

			$this->log('Uncompressed length: ' . $uncompressedLength);

			//read number of chunks
			$chunksCount = hexdec(SGBGArchiveHelper::unpackLittleEndian($this->read(4), 4));

			$this->log('Number of chunks: ' . $chunksCount);

			$chunks = array();
			for ($i = 0; $i < $chunksCount; $i++) {
				$start = SGBGArchiveHelper::unpackLittleEndian($this->read(8), 8);
				$start = hexdec($start);

				$size = SGBGArchiveHelper::unpackLittleEndian($this->read(8), 8);
				$size = hexdec($size);

				$this->log('Chunk ' . ($i + 1) . ': Start(' . $start . ') - Size(' . $size . ')');

				$chunks[] = array(
					'start' => $start,
					'size'  => $size
				);
			}

			$this->log('End read CDR item');

			return array(
				'filename'           => $filename,
				'offset'             => $fileOffsetInArchive,
				'compressedLength'   => $compressedLength,
				'uncompressedLength' => $uncompressedLength,
				'chunks'             => $chunks,
				'crc'                => $crc
			);
		} else {
			return [];
		}
	}

	/**
	 * @throws Exception
	 */
	public function extractTo($destinationPath)
	{
		$resumingRestore = $this->task->getStateFile()->getStatus() != SGBGStateFile::STATUS_READY;

		$this->log('Start extract, status: ' . $this->task->getStateFile()->getStatus() );
		$this->log('Resume: ' . $resumingRestore );

		$this->extractHeaders();

		//read cdr size
		$cdrSize = hexdec(SGBGArchiveHelper::unpackLittleEndian($this->read(4), 4));

		$this->log('CDR size: ' . $cdrSize, false);

		$this->task->start($cdrSize);

		if ($this->delegate) {
			$this->delegate->didCountFilesInsideArchive($cdrSize);
		}

		$this->extractFiles($destinationPath, $cdrSize, $resumingRestore);
	}

	private function extractFiles($destinationPath, $cdrSize, $resumingRestore)
	{
		$stateFile = $this->task->getStateFile();

		$this->log('Start extract files');

		$cdrOffset = $stateFile->getData('cdrOffset');
		if ($resumingRestore) {
			$this->_cdrSize = (int) $stateFile->getData('cdrSize');
			$this->_cdrOffset = (int) $cdrOffset;
			$this->seek($this->_cdrOffset);
		} else {
			$this->_cdrSize = $cdrSize;
		}

		while ($this->_cdrSize) {
			$cdrItem = $this->getNextCdrItem();

			if ($cdrItem) {
				//we remember where we left the cdr, to come back to that point
				$this->_cdrOffset = $this->tell();

				$this->extractFile($cdrItem, $destinationPath);

				//coming back to the cdr
				$this->seek($this->_cdrOffset);

				$this->_cdrSize--;

				$stateFile->setData('cdrOffset', $this->_cdrOffset);
				$stateFile->setData('cdrSize', $this->_cdrSize);
				$stateFile->save(false);
			}
		}

		$this->log('End extract files', true);
	}

	private function createTmpFile($path)
	{
		$file = new SGBGCacheableFile($path);
		$file->getCache()->setCacheMode(SGBGCache::CACHE_MODE_TIMEOUT | SGBGCache::CACHE_MODE_SIZE);
		$file->getCache()->setCacheTimeout(10);
		$file->getCache()->setCacheSize(8000000);
		$file->open('ab');
		return $file;
	}

	private function extractFile($cdrItem, $destinationPath)
	{
		$this->log('Start extract file: ' . $cdrItem['filename'], true);

		$path = $destinationPath . $cdrItem['filename'];
		$path = str_replace('\\', DIRECTORY_SEPARATOR, $path);

		$isEmptyDirectory = false;
		$destPath         = $path;
		if (substr($path, -1) != DIRECTORY_SEPARATOR) { //it's not an empty directory
			$destPath = dirname($path);
		} else {
			$isEmptyDirectory = true;
		}

		$this->log('Destination path: ' . $path);
		//$this->log('Is empty directory: ' . ($isEmptyDirectory ? 'Yes' : 'No'));

		if ($this->delegate) {
			if ($this->delegate->shouldExtractFile($path)) {
				$this->delegate->willExtractFile($path);
			} else {
				$this->log('Skip file extract', true);
				return true;
			}
		}

		//$this->log('Prepare destination path (create folders recursively)');

		if (!SGBGArchiveHelper::createPath($destPath)) {
			if ($this->delegate) {
				$this->delegate->didFindExtractError('Could not create directory for: ' . $destPath);
			}
			return false;
		}

		if ($isEmptyDirectory) {
			$this->log('End extract file', true);
			return true;
		}

		$tmpPath = $path . '.sgbpTmpFile';
		$tmpFile = $this->createTmpFile($tmpPath);

		$this->log('Create tmp file: ' . $tmpPath);

		$errorFound = false;

		$stateFile = $this->task->getStateFile();
		$chunkNumber = (int) $stateFile->getData('chunkNumber');

		$chunks = array_slice($cdrItem['chunks'], $chunkNumber);
//        $this->log(print_r($cdrItem, true), true);

		foreach ($chunks as $i => $chunk) {
			$this->seek($cdrItem['offset'] + 4 + $chunk['start']);

			$this->task->continueTask(function () use ($cdrItem, $stateFile, $tmpFile, $chunkNumber, $i) {
				$this->log('Extracting file by chunks ' . $cdrItem['filename'], true);

				$tmpFile->getCache()->flush();
				$tmpFile->close();

				$this->close();

				$stateFile->setData('chunkNumber', $chunkNumber + $i);
				$stateFile->setStatus(SGBGStateFile::STATUS_RESUME);
				$stateFile->save(true);

				//$this->getLogFile()->getCache()->flush();
				//$this->getLogFile()->close();
				//$this->updateProgress();
			});

			if (!$this->extractFileChunk($chunk, $tmpFile)) {
				$errorFound = true;
				break;
			}
		}

		$tmpFile->getCache()->flush();
		$tmpFile->close();

		$this->task->endChunk();

		$stateFile->setData('chunkNumber', 0);
		$stateFile->save(true);

		//CRC check here
		$ff         = fopen($tmpPath, 'r');
		$firstBytes = fread($ff, 100);
		fseek($ff, -100, SEEK_END);
		$lastBytes = fread($ff, 100);

		if (abs(crc32($firstBytes . $lastBytes)) != hexdec(SGBGArchiveHelper::unpackLittleEndian($cdrItem['crc'], 4))) {
			$this->log("invalid CRC Header in file " . $cdrItem['filename']);
			$this->log(abs(crc32($firstBytes . $lastBytes)) . '!=');
			$this->log(hexdec(SGBGArchiveHelper::unpackLittleEndian($cdrItem['crc'], 4)));
		} else {
			$this->log("CRC Check Complete");
		}

		if (!$errorFound) {
			$errorFound = !@rename($tmpPath, $path);
		}

		if ($errorFound) {
			$tmpFile->remove();
			$this->log('Failed to extract file: ' . $path, true);

			if ($this->delegate) {
				$this->delegate->didFindExtractError('Failed to extract path: ' . $path);
			}
		} else if ($this->delegate) {
			$this->delegate->didExtractFile($path);
		}

		$this->log('End extract file: ' . $cdrItem['filename'], true);

		return !$errorFound;
	}

	private function extractFileChunk($chunk, $tmpFile)
	{
		$start = $chunk['start'];
		$size  = $chunk['size'];

		$this->log('Start extract chunk: Start(' . $start . ') - Size(' . $size . ')');

		$data = $this->read($size);
		$this->log('Read: ' . strlen($data) . ' bytes');
		$data = gzinflate($data);

		//if gzinflate() failed to uncompress, skip the current file and continue extraction
		if (!$data) {
			$this->log('Failed to uncompress');
			return false;
		}

		$this->log('Uncompressed: ' . strlen($data) . ' bytes');

		$result = $tmpFile->write($data);

		$this->log('Written: ' . $result . ' bytes');
		$this->log('End extract chunk');

		return ($result !== false);
	}

	private function updateProgress()
	{
		if ($this->task->getStateFile()->getCount() && $this->task->getStateFile()->getCount() > 0) {
			$progress = round($this->task->getStateFile()->getOffset() * 100.0 / $this->task->getStateFile()->getCount());

			if ($this->delegate) {
				$this->delegate->didUpdateProgress($progress);
			}
		}

		return true;
	}

	private function pathWithoutRootDirectory($path)
	{
		return substr($path, strlen(rtrim(SGConfig::get('SG_APP_ROOT_DIRECTORY'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
	}

	public function warn($message)
	{
		$this->_warningsFound = true;
		$this->log('Warning: ' . $message, true);
	}
}