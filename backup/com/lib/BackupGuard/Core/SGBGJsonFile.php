<?php
if (!defined('WPINC')) die ('Direct access is not allowed');


/*
@ trait SGBGJsonFile
@ version 1.0.1
@ updated 23/01/2021
*/

require_once(__DIR__.'/SGBGFile.php');

trait SGBGJsonFile
{
	/**
	 *
	 * Get decoded JSON file contents.
	 *
	 * @return mixed  decoded JSON contents
	 */
	public function getDecodedContents()
	{
		$contents = parent::read();

		if (!empty($contents)) {
			$contents = json_decode($contents, true);
			if (json_last_error() == JSON_ERROR_NONE) {
				return $contents;
			}
		}

		return null;
	}

	/**
	 *
	 * JSON encode the contents and save to file.
	 *
	 * @param string $contents  data to encode and save
	 * @return string  the number of bytes written to file, or false in case of an error
	 */
	public function encodeAndSetContents($contents)
	{
		return parent::write(json_encode($contents));
	}
}
