<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

class SGBGStateJson {

	public function DoJson($type = null, $data = null) {

		if (!$type || !$data) return null;
		$res = null;

		switch ($type) {
			case 'json_decode':
				$res = json_decode($data, true);
				break;

			case 'json_encode':
				$res = json_encode($data, true);
				break;
		}

		if (json_last_error() === 0) return $res;
		return null;
	}

}