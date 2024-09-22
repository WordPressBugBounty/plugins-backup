<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

interface ISGArchiveDelegate
{
	public function getCorrectCdrFilename($filename);
	public function didCountFilesInsideArchive($count);
	public function shouldExtractFile($filePath);
	public function willExtractFile($filePath);
	public function didExtractFile($filePath);
	public function didFindExtractError($error);
	public function didExtractArchiveHeaders($version, $extra);

	public function willAddFile($filename);
	public function willAddFileChunk($filename);
	public function didAddFileChunk($filename, $chunk);
	public function didAddFile($filename);
	public function didUpdateProgress($progress);

	public function getArchiveExtraData();
}
