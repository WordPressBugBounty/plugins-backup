<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

abstract class SGExternalRestore
{
	private static $instance = null;

	public static function getInstance()
	{
		if (!self::$instance) {
			self::$instance = self::createChildInstance();
		}

		return self::$instance;
	}

	private static function createChildInstance()
	{
		$className = 'SGExternalRestore'.SG_ENV_ADAPTER;
		require_once(dirname(__FILE__).'/'.$className.'.php');
		$child = new $className();
		return $child;
	}

	protected function __construct()
	{

	}

	private function __clone()
	{

	}

	public function getSourceFilePath()
	{
		return SG_PUBLIC_PATH.'restore_'.strtolower(SG_ENV_ADAPTER).'.php';
	}

	public function getDestinationFilePath()
	{
		//get already saved restore path
		$path = SGConfig::get('SG_EXTERNAL_RESTORE_PATH', true);

		if (!$path) {
			$path = $this->getDestinationPath().SG_EXTERNAL_RESTORE_FILE;
			SGConfig::set('SG_EXTERNAL_RESTORE_PATH', $path, true);
		}

		return $path;
	}

	public function getDestinationFileUrlArray(&$key = '')
	{
		//we use this key to deny direct access to the file
		$key = SGConfig::get('SG_BACKUP_CURRENT_KEY', true);

		//get already saved restore url
		$url = SGConfig::get('SG_SITE_URL', true);

		if (!$url) $url = $this->getDestinationUrl();

		$array['key'] = $key;
		$array['url'] = $url;
		$array['restore_file'] = SG_EXTERNAL_RESTORE_FILE;

		return $array;
	}

	public function getDestinationFileUrl(&$key = '')
	{
		// we use this key to deny direct access to the file
		$key = SGConfig::get('SG_BACKUP_CURRENT_KEY', true);

		// get already saved restore url
		$url = SGConfig::get('SG_EXTERNAL_RESTORE_URL', true);

		if (!$url) {
			$url = $this->getDestinationUrl() . SG_EXTERNAL_RESTORE_FILE . '?k=' . $key;
			SGConfig::set('SG_EXTERNAL_RESTORE_URL', $url, true);
		}

		return $url;
	}

	public function getDestinationExtractFileUrl(&$key = '')
	{
		// we use this key to deny direct access to the file
		$key = SGConfig::get('SG_BACKUP_CURRENT_KEY', true);

		// get already saved restore url
		$url = SGConfig::get('SG_EXTERNAL_RESTORE_URL', true);

		if (!$url) {
			$url = $this->getDestinationUrl() . SG_EXTERNAL_RESTORE_FILE . '?k=' . $key;
			SGConfig::set('SG_EXTERNAL_RESTORE_URL', $url, true);
		}

		return $url;
	}

	public static function isEnabled()
	{
		//return SGConfig::get('SG_EXTERNAL_RESTORE_ENABLED')?true:false;
		return true;
	}

	protected static function setEnabled($enabled)
	{
		SGConfig::set('SG_EXTERNAL_RESTORE_ENABLED', ($enabled?1:0), true);
	}
	private function getConstants($actionId)
	{
		$key = '';
		$destinationUrl = $this->getDestinationFileUrl($key);
		$isMultisite = backupGuardIsMultisite();
		return array(
			'SG_ACTION_ID' => $actionId,
			'SG_ENV_DB_PREFIX' => SG_ENV_DB_PREFIX,
			'SG_PLUGIN_NAME' => SG_PLUGIN_NAME,
			'SG_BACKUP_SITE_URL' => SG_BACKUP_SITE_URL,
			'SG_PUBLIC_URL' => SG_PUBLIC_URL,
			'SG_BACKUP_DIRECTORY' => SG_BACKUP_DIRECTORY,
			'SG_BACKUP_OLD_DIRECTORY' => SG_BACKUP_OLD_DIRECTORY,
			'SG_BACKUP_GUARD_VERSION' => SG_BACKUP_GUARD_VERSION,
			'SG_PING_FILE_PATH' => SG_PING_FILE_PATH,
			'SG_SITE_URL' => SG_SITE_URL,
			'BG_PLUGIN_URL' => SG_PUBLIC_BACKUPS_URL,
			'BG_RESTORE_KEY' => $key,
			'BG_RESTORE_URL' => $destinationUrl,
			'BG_AWAKE_URL' => get_admin_url() . "admin-ajax.php?action=backup_guard_awake",
			'BG_IS_MULTISITE' => $isMultisite,
			'SG_RESTORE_PATH' => SG_RESTORE_PATH

		);
	}

	public function prepare($actionId): bool {
		/*
		 * This will determine if the SG_EXTERNAL_RESTORE_ENABLED const will return true / false
		 * true - we were able to create the external bg_restore.php file and populate it
		 * false - something happened, and we cannot use external restore file
		 *
		 */

		// reset everything
		self::setEnabled(false);
		SGConfig::set('SG_EXTERNAL_RESTORE_URL', '', true);
		SGConfig::set('SG_EXTERNAL_RESTORE_PATH', '', true);

		if (!$this->canPrepare()) return false;

		$contents = file_exists($this->getSourceFilePath()) ? file_get_contents($this->getSourceFilePath()) : null;
		if (!$contents) return false;

		$constants = $this->getConstants($actionId);
		$customConstants = $this->getCustomConstants();
		$allConstants = array_merge($constants, $customConstants);

		$defines = '';
		foreach ($constants as $key => $val) {
			$defines .= "define('$key', '$val');\n";
		}

		// put all defines inside the file
		$contents = str_replace('#SG_DYNAMIC_DEFINES#', $defines, $contents);

		// create new copy
		if (file_exists($this->getDestinationFilePath())) unlink($this->getDestinationFilePath());
		if (file_put_contents($this->getDestinationFilePath(), $contents) !== false) {
			self::setEnabled(true);
			return true;
		}

		return false;
	}

	public function cleanup()
	{
		if (file_exists($this->getDestinationFilePath())) {
			$actions = SGBackup::getRunningActions();
			if (empty($actions)) {
				@unlink($this->getDestinationFilePath());
			}
		}
	}

	abstract protected function canPrepare();

	abstract protected function getCustomConstants();

	abstract public function getDestinationPath();

	abstract public function getDestinationUrl();
}
