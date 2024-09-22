<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

class SGBGArchiveHelper
{
	public static function packToLittleEndian($value, $size = 4)
	{
		if (is_int($value)) {
			$size *= 2; //2 characters for each byte
			$value = str_pad(dechex($value), $size, '0', STR_PAD_LEFT);
			return strrev(pack('H'.$size, $value));
		}

		$hex = str_pad($value->toHex(), 16, '0', STR_PAD_LEFT);

		$high = substr($hex, 0, 8);
		$low  = substr($hex, 8, 8);

		$high = strrev(pack('H8', $high));
		$low = strrev(pack('H8', $low));

		return $low.$high;
	}

	public static function unpackLittleEndian($data, $size)
	{
		$size *= 2; //2 characters for each byte

		$data = unpack('H'.$size, strrev($data));
		return $data[1];
	}

	public static function createPath($path)
	{
		if (is_dir($path)) {
			return true;
		}

		$prevPath = substr($path, 0, strrpos($path, '/', -2) + 1);
		$return = self::createPath($prevPath);
		if ($return && is_writable($prevPath)) {
			if (!@mkdir($path)) {
				return false;
			}

			@chmod($path, 0755);
			return true;
		}

		return false;
	}

	public static function realFilesize($filename)
	{
		if (is_dir($filename)) {
			return 0;
		}

		$fp = fopen($filename, 'r');
		$return = false;
		if (is_resource($fp)) {
			if (PHP_INT_SIZE < 8) { // 32 bit
				if (0 === fseek($fp, 0, SEEK_END)) {
					$return = 0.0;
					$step = 0x7FFFFFFF;
					while ($step > 0) {
						if (0 === fseek($fp, - $step, SEEK_CUR)) {
							$return += floatval($step);
						}
						else {
							$step >>= 1;
						}
					}
				}
			}
			else if (0 === fseek($fp, 0, SEEK_END)) { // 64 bit
				$return = ftell($fp);
			}
		}

		return $return;
	}

    public static function is_dir_empty($dir) {
        if (!is_readable($dir)) return null;
        return (count(scandir($dir)) == 2);
    }
}
