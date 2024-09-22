<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(SG_LIB_PATH . 'BackupGuard/Core/Execute.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/BGTask.php');
require_once(SG_LIB_PATH . 'BackupGuard/Core/Process.php');


class SGNoticeHandler
{

	public array $_conflicts = array ();

	public function __construct() {

		$this->checkConflicts();
		$this->checkPhpCLI();

	}

	public function run()
	{
		$this->checkTimeoutError();
		$this->checkMigrationError();
		$this->checkRestoreNotWritableError();
		$this->checkLiteSpeedWarning();
		$this->checkTables();
		$this->checkPingFilePermission();
		$this->checkExpiredLicense();
		$this->checkPluginRequirements();


	}

	private function checkPluginRequirements()
	{
		try {
            //check minimum requirements
            checkMinimumRequirements();

            //prepare directory for backups
            prepareBackupDir();
        } catch (SGException $exp) {
            SGNotice::getInstance()->addNotice($exp->getMessage(), SG_NOTICE_ERROR, true);
        }
	}

	private function checkTimeoutError()
	{
		$pluginCapabilities = backupGuardGetCapabilities();
		if (SGConfig::get('SG_EXCEPTION_TIMEOUT_ERROR')) {
			if ($pluginCapabilities != BACKUP_GUARD_CAPABILITIES_FREE) {
				SGNotice::getInstance()->addNoticeFromTemplate('timeout_error', SG_NOTICE_ERROR, true);
			}
			else {
				SGNotice::getInstance()->addNoticeFromTemplate('timeout_free_error', SG_NOTICE_ERROR, true);
			}
		}
	}
	
	public function checkTables()
	{
		if (!checkAllMissedTables()) {
			SGNotice::getInstance()->addNoticeFromTemplate('missed_table', SG_NOTICE_ERROR, true);
		}
	}

	private function checkMigrationError()
	{
		if (SGConfig::get('SG_BACKUP_SHOW_MIGRATION_ERROR')) {
			SGNotice::getInstance()->addNoticeFromTemplate('migration_error', SG_NOTICE_ERROR, true);
		}
	}

	private function checkRestoreNotWritableError()
	{
		if (SGConfig::get('SG_BACKUP_SHOW_NOT_WRITABLE_ERROR')) {
			SGNotice::getInstance()->addNoticeFromTemplate('restore_notwritable_error', SG_NOTICE_ERROR, true);
		}
	}

	private function checkLiteSpeedWarning()
	{
		$server = '';
		if (isset($_SERVER['SERVER_SOFTWARE'])) {
			$server = strtolower($_SERVER['SERVER_SOFTWARE']);
		}

		//check if LiteSpeed server is running
		if (strpos($server, 'litespeed') !== false) {
			$htaccessContent = '';
			if (is_readable(ABSPATH.'.htaccess')) {
				$htaccessContent = @file_get_contents(ABSPATH.'.htaccess');
				if (!$htaccessContent) {
					$htaccessContent = '';
				}
			}

			if (!$htaccessContent || !preg_match('/noabort/i', $htaccessContent)) {
				SGNotice::getInstance()->addNoticeFromTemplate('litespeed_warning', SG_NOTICE_WARNING);
			}
		}
	}

	// Return a list of plugins that are known as confilts
	private function BadPlugins (): array
	{

		return array (

			'autopost-to-mastodon/mastodon_autopost.php', // breaks uploads to one-drive
			'bs-barion-pixel/bs-barion-pixel-plugin.php', // break posts commands, cannot start backup and causing mysql queries to zombie

		);

	}

	private function ConflictMessage ($array): string
	{

		$msg = '<span style="color: red;">JetBackup Warning!  Conflicting plugins found: </span>';
		$msg .= '<ul>';
		foreach ($array as $value) {

			$msg .= '<li style="list-style: circle; margin-left: 20px;">' . $value . '</li>';

		}

		$msg .= '</ul>';

		$msg .= 'Using these plugins alongside with JetBackup might interfere with our plugin functionality and is likely to cause errors. <br />Please disable these plugins before using JetBackup.';
		return $msg;

	}

	private function PHPVersionMessage ($webVersion, $cliVersion): string
	{

		$settings_url = '<a href="'.get_admin_url().'admin.php?page=backup_guard_settings">settings</a>';
		$msg = '<span style="color: red;">JetBackup Warning! PHP CLI is different the Current PHP</span> <br />';
		$msg .= '<ul>';
		$msg .= '<li style="list-style: circle; margin-left: 20px;"> Current PHP: ' . $webVersion . '</li>';
		$msg .= '<li style="list-style: circle; margin-left: 20px;"> CLI PHP: ' . $cliVersion . '</li>';
		$msg .= '</ul>';
		$msg .= 'JetBackup is using PHP CLI for background operations and it is crucial to use the exact same PHP version';
		$msg .= '<br/> <br/> <strong>You can set alternate php-cli path in the '. $settings_url . ' or contact your hosting provider about this</strong><br/>';

		return $msg;

	}

	private function checkPhpCLI() {

		$phpcli = SGConfig::get('SG_PHP_CLI_LOCATION') ? SGConfig::get('SG_PHP_CLI_LOCATION') : 'php';

		$Execute = new Execute();
		$cmd = "$phpcli -r 'print_r(phpversion());'";
		$res = $Execute->runCommand($cmd, null, true);
		$webVersion = phpversion() ?? null;
		$cliVersion = null;

		if ($Execute->parseResultsCode($res) && isset($res['output'][0])) $cliVersion = $res['output'][0];

		if ($webVersion && $cliVersion) {

			if (version_compare($webVersion, $cliVersion, '!=')) {

				SGNotice::getInstance()->addNotice($this->PHPVersionMessage($webVersion, $cliVersion), SG_NOTICE_ERROR, true);


			}
		}

	}

	// Check conflicts with other plugins
	private function checkConflicts() {


		if ( !function_exists('get_plugins') ){
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
		}


		$bad_plugins = $this->BadPlugins();
		$installed_plugins = get_plugins();
		$diff = [];

		foreach ($bad_plugins as $plugin) {

			if (isset($installed_plugins[$plugin])) $diff[] = $plugin;
		}


		if (count($diff)) {

			foreach ($diff as $item) {

				 if (is_plugin_active($item)) $this->_conflicts[] = $installed_plugins[$item]['Name'];

			}


			if (count($this->_conflicts)) {

				SGNotice::getInstance()->addNotice($this->ConflictMessage($this->_conflicts), SG_NOTICE_ERROR, true);

			}

		}

	}

	private function checkPingFilePermission()
	{
		if (file_exists(SG_PING_FILE_PATH) && !is_readable(SG_PING_FILE_PATH)) {
			SGNotice::getInstance()->addNoticeFromTemplate('ping_permission', SG_NOTICE_ERROR, true);
		}
	}
	
	private function checkExpiredLicense()
	{
		$sg_license_get_status = SGConfig::get('SG_LICENSE_KEY_STATUS') ? SGConfig::get('SG_LICENSE_KEY_STATUS') : null;
		if (!empty($sg_license_get_status)) $sg_license_get_status = strtolower($sg_license_get_status);

		if ($sg_license_get_status && $sg_license_get_status == JBWP_LICENSE_KEY_STATUS_EXPIRED) {
			SGNotice::getInstance()->addNoticeFromTemplate('expired_license_error', SG_NOTICE_ERROR, true);
		}
	}
}
