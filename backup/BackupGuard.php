<?php
if (!defined('WPINC')) die ('Direct access is not allowed');


//ini_set('opcache.enable', '0');
include_once(SG_LIB_PATH . 'BackupGuard/Core/SGBLock.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGStateJson.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGChunks.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/SGBGReloader.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/NonBlockingHttpClientService.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/Process.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/Cron.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/CronTab.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/BGTask.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/Execute.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/Log.php');
include_once(SG_LIB_PATH . 'BackupGuard/Core/Timing.php');


function backupguard_genkey () {
	$_key = SGConfig::get('SG_BACKUP_CURRENT_KEY', true) ?? null;
	if (!$_key) SGConfig::set('SG_BACKUP_CURRENT_KEY', md5(microtime(true)), true);
}

// The code that runs during plugin activation.
function activate_backup_guard()
{
	// do checks before activating plugin
	try {
		prepareBackupDir();
		checkMinimumRequirements();
		backupguard_genkey();
	} catch (SGException $exp) {
		die($exp->getMessage());
	}

	// check if database should be updated
	if (backupGuardShouldUpdate()) {
		SGBoot::install();
		SGBoot::didInstallForFirstTime();
	}
}

// The code that runs during plugin deactivation.
function uninstall_backup_guard()
{
	SGBoot::uninstall();
}

function deactivate_backup_guard()
{


	$pluginCapabilities = backupGuardGetCapabilities();
	if ($pluginCapabilities != BACKUP_GUARD_CAPABILITIES_FREE) {
		include_once SG_LIB_PATH . 'SGAuthClient.php';
		SGAuthClient::getInstance()->logout();
	}

	$CronTab = new CronTab();
	$CronTab->removeCrontab();
	$CronTab->cleanup();

}

function backupGuardMaybeShortenEddFilename($return, $package)
{
	if (strpos($package, 'backup-guard') !== false) {
		add_filter('wp_unique_filename', 'backupGuardShortenEddFilename', 10, 2);
	}

	return $return;
}

function backupGuardShortenEddFilename($filename, $ext)
{
	$filename = substr($filename, 0, 20) . $ext;
	remove_filter('wp_unique_filename', 'backupGuardShortenEddFilename', 10);
	return $filename;
}

add_filter('upgrader_pre_download', 'backupGuardMaybeShortenEddFilename', 10, 4);

register_activation_hook(SG_BACKUP_GUARD_MAIN_FILE, 'activate_backup_guard');
register_uninstall_hook(SG_BACKUP_GUARD_MAIN_FILE, 'uninstall_backup_guard');
register_deactivation_hook(SG_BACKUP_GUARD_MAIN_FILE, 'deactivate_backup_guard');

// Register Admin Menus for single and multisite
if (is_multisite()) {
	add_action('network_admin_menu', 'backup_guard_admin_menu');
} else {
	add_action('admin_menu', 'backup_guard_admin_menu');
}

function backup_guard_admin_menu()
{
	$capability = 'manage_options';
	if (defined('SG_USER_MODE') && SG_USER_MODE) {
		$capability = 'read';
	}

	add_menu_page('Backups', 'JetBackup', $capability, 'backup_guard_backups', 'includeAllPages', SG_IMAGE_URL . 'eddie-white.svg', 74);

	add_submenu_page('backup_guard_backups', _backupGuardT('Backups', true), _backupGuardT('Backups', true), $capability, 'backup_guard_backups', 'includeAllPages');

	$pluginCapabilities = backupGuardGetCapabilities();
	if ($pluginCapabilities != BACKUP_GUARD_CAPABILITIES_FREE) {
		add_submenu_page('backup_guard_backups', _backupGuardT('Cloud', true), _backupGuardT('Cloud', true), $capability, 'backup_guard_cloud', 'includeAllPages');
		add_submenu_page('backup_guard_backups', _backupGuardT('Schedule', true), _backupGuardT('Schedule', true), $capability, 'backup_guard_schedule', 'includeAllPages');
	}

	add_submenu_page('backup_guard_backups', _backupGuardT('Settings', true), _backupGuardT('Settings', true), $capability, 'backup_guard_settings', 'includeAllPages');

	add_submenu_page('backup_guard_backups', _backupGuardT('System Info.', true), _backupGuardT('System Info.', true), $capability, 'backup_guard_system_info', 'includeAllPages');

	// Check if should show upgrade page
	if (SGBoot::isFeatureAvailable('SHOW_UPGRADE_PAGE')) {
		add_submenu_page('backup_guard_backups', _backupGuardT('Why upgrade?', true), _backupGuardT('Why upgrade?', true), $capability, 'backup_guard_pro_features', 'includeAllPages');
	}
}

function getBackupPageContentClassName($pageName = '')
{
	$hiddenClassName = 'sg-visibility-hidden';
	$page = sanitize_text_field($_GET['page']);

	if (strpos($page, $pageName)) {
		$hiddenClassName = '';
	}

	return $hiddenClassName;
}

function includeAllPages()
{
	if (!backupGuardValidateLicense()) {
		return false;
	}
	backup_guard_backups_page();
	backup_guard_cloud_page();
	backup_guard_system_info_page();
	backup_guard_pro_features_page();
	backup_guard_schedule_page();
	backup_guard_settings_page();

	include_once plugin_dir_path(__FILE__) . 'public/pagesContent.php';

	return true;
}

function backup_guard_system_info_page()
{
	if (backupGuardValidateLicense()) {
		//require_once(plugin_dir_path(__FILE__).'public/systemInfo.php');
	}
}

//Pro features page
function backup_guard_pro_features_page()
{
	//require_once(plugin_dir_path(__FILE__).'public/proFeatures.php');
}

//Backups Page
function backup_guard_backups_page()
{
	if (backupGuardValidateLicense()) {
		wp_enqueue_script('backup-guard-iframe-transport-js', plugin_dir_url(__FILE__) . 'public/js/jquery.iframe-transport.js', array('jquery'));
		wp_enqueue_script('backup-guard-fileupload-js', plugin_dir_url(__FILE__) . 'public/js/jquery.fileupload.js', array('jquery'));
		wp_enqueue_script('backup-guard-jstree-js', plugin_dir_url(__FILE__) . 'public/js/jstree.min.js', array('jquery'));
		wp_enqueue_script('backup-guard-jstree-checkbox-js', plugin_dir_url(__FILE__) . 'public/js/jstree.checkbox.js', array('jquery'));
		wp_enqueue_script('backup-guard-jstree-wholerow-js', plugin_dir_url(__FILE__) . 'public/js/jstree.wholerow.js', array('jquery'));
		wp_enqueue_script('backup-guard-jstree-types-js', plugin_dir_url(__FILE__) . 'public/js/jstree.types.js', array('jquery'));
		wp_enqueue_style('backup-guard-jstree-css', plugin_dir_url(__FILE__) . 'public/css/default/style.min.css');
		wp_enqueue_script('backup-guard-support-js', plugin_dir_url(__FILE__) . 'public/js/sgsupport.js', array('jquery'));
		wp_enqueue_script(
			'backup-guard-backups-js',
			plugin_dir_url(__FILE__) . 'public/js/sgbackup.js',
			array(
				'jquery',
				'jquery-effects-core',
				'jquery-effects-transfer',
				'jquery-ui-widget'
			)
		);

		// Localize the script with new data
		wp_localize_script(
			'backup-guard-backups-js',
			'BG_BACKUP_STRINGS',
			array(
				'confirm' => _backupGuardT('Are you sure you want to cancel import?', true),
				'nonce' => wp_create_nonce('backupGuardAjaxNonce'),
				'invalidBackupOption' => _backupGuardT('Please choose at least one option.', true),
				'invalidDirectorySelected' => _backupGuardT('Please choose at least one directory.', true),
				'invalidCloud' => _backupGuardT('Please choose at least one cloud.', true),
				'backupInProgress' => _backupGuardT('Backing Up...', true),
				'errorMessage' => _backupGuardT('Something went wrong. Please try again.', true),
				'noBackupsAvailable' => _backupGuardT('No backups found.', true),
				'invalidImportOption' => _backupGuardT('Please select one of the options.', true),
				'invalidDownloadFile' => _backupGuardT('Please choose one of the files.', true),
				'import' => _backupGuardT('Import', true),
				'importInProgress' => _backupGuardT('Importing please wait...', true),
				'fileUploadFailed' => _backupGuardT('File upload failed.', true)
			)
		);

		//  require_once(plugin_dir_path( __FILE__ ).'public/backups.php');
	}
}

//Cloud Page
function backup_guard_cloud_page()
{
	if (backupGuardValidateLicense()) {
		wp_enqueue_style('backup-guard-switch-css', plugin_dir_url(__FILE__) . 'public/css/bootstrap-switch.min.css');
		wp_enqueue_script('backup-guard-switch-js', plugin_dir_url(__FILE__) . 'public/js/bootstrap-switch.min.js', array('jquery'), SG_BACKUP_GUARD_VERSION, true);
		wp_enqueue_script(
			'backup-guard-jquery-validate-js',
			plugin_dir_url(__FILE__) . 'public/js/jquery.validate.min.js',
			array(
				'jquery',
				'backup-guard-switch-js'
			),
			SG_BACKUP_GUARD_VERSION,
			true
		);
		wp_enqueue_script(
			'backup-guard-cloud-js',
			plugin_dir_url(__FILE__) . 'public/js/sgcloud.js',
			array(
				'jquery',
				'backup-guard-switch-js'
			),
			SG_BACKUP_GUARD_VERSION,
			true
		);

		// Localize the script with new data
		wp_localize_script(
			'backup-guard-cloud-js',
			'BG_CLOUD_STRINGS',
			array(
				'invalidImportFile' => _backupGuardT('Please select a file.', true),
				'invalidFileSize' => _backupGuardT('File is too large.', true),
				'connectionInProgress' => _backupGuardT('Connecting...', true),
				'invalidDestinationFolder' => _backupGuardT('Destination folder is required.', true),
				'invalidDestinationFolderName' => _backupGuardT('Destination folder: Only english letters and numbers are allowed', true),
				'successMessage' => _backupGuardT('Successfully saved.', true)
			)
		);

		//require_once(plugin_dir_path(__FILE__).'public/cloud.php');
	}
}

//Schedule Page
function backup_guard_schedule_page()
{
	if (backupGuardValidateLicense()) {
		wp_enqueue_style('backup-guard-switch-css', plugin_dir_url(__FILE__) . 'public/css/bootstrap-switch.min.css');
		wp_enqueue_script('backup-guard-switch-js', plugin_dir_url(__FILE__) . 'public/js/bootstrap-switch.min.js', array('jquery'), '1.0.0', true);
		wp_enqueue_script('backup-guard-schedule-js', plugin_dir_url(__FILE__) . 'public/js/sgschedule.js', array('jquery'), '1.0.0', true);

		// Localize the script with new data
		wp_localize_script(
			'backup-guard-schedule-js',
			'BG_SCHEDULE_STRINGS',
			array(
				'deletionError' => _backupGuardT('Unable to delete schedule', true),
				'confirm' => _backupGuardT('Are you sure?', true),
				'invalidBackupOption' => _backupGuardT('Please choose at least one option.', true),
				'invalidDirectorySelected' => _backupGuardT('Please choose at least one directory.', true),
				'invalidCloud' => _backupGuardT('Please choose at least one cloud.', true),
				'savingInProgress' => _backupGuardT('Saving...', true),
				'successMessage' => _backupGuardT('You have successfully activated schedule.', true),
				'saveButtonText' => _backupGuardT('Save', true)
			)
		);

		//  require_once(plugin_dir_path( __FILE__ ).'public/schedule.php');
	}
}

//Settings Page
function backup_guard_settings_page()
{
	if (backupGuardValidateLicense()) {
		wp_enqueue_style('backup-guard-switch-css', plugin_dir_url(__FILE__) . 'public/css/bootstrap-switch.min.css');
		wp_enqueue_script('backup-guard-switch-js', plugin_dir_url(__FILE__) . 'public/js/bootstrap-switch.min.js', array('jquery'), '1.0.0', true);
		wp_enqueue_script('backup-guard-settings-js', plugin_dir_url(__FILE__) . 'public/js/sgsettings.js', array('jquery'), '1.0.0', true);

		// Localize the script with new data
		wp_localize_script(
			'backup-guard-settings-js',
			'BG_SETTINGS_STRINGS',
			array(
				'invalidEmailAddress' => _backupGuardT('Please enter valid email.', true),
				'invalidFileName' => _backupGuardT('Please enter valid file name.', true),
				'invalidRetentionNumber' => _backupGuardT('Please enter a valid retention number.', true),
				'successMessage' => _backupGuardT('Successfully saved.', true),
				'savingInProgress' => _backupGuardT('Saving...', true),
				'retentionConfirmationFirstPart' => _backupGuardT('Are you sure you want to keep the latest', true),
				'retentionConfirmationSecondPart' => _backupGuardT('backups? All older backups will be deleted.', true),
				'saveButtonText' => _backupGuardT('Save', true)
			)
		);

		//require_once(plugin_dir_path(__FILE__).'public/settings.php');
	}
}

function backup_guard_login_page()
{
	wp_enqueue_script('backup-guard-login-js', plugin_dir_url(__FILE__) . 'public/js/sglogin.js', array('jquery'), '1.0.0', true);

	include_once plugin_dir_path(__FILE__) . 'public/login.php';
}

add_action('admin_enqueue_scripts', 'enqueue_backup_guard_scripts');
function enqueue_backup_guard_scripts($hook)
{
	if (!strpos($hook, 'backup_guard')) {
		if ($hook == "index.php") {
			wp_enqueue_script('backup-guard-chart-manager', plugin_dir_url(__FILE__) . 'public/js/chart.umd.min.js');
		}
		return;
	}

	wp_enqueue_style('backup-guard-spinner', plugin_dir_url(__FILE__) . 'public/css/spinner.css');
	wp_enqueue_style('backup-guard-wordpress', plugin_dir_url(__FILE__) . 'public/css/bgstyle.wordpress.css');
	wp_enqueue_style('backup-guard-less', plugin_dir_url(__FILE__) . 'public/css/bgstyle.less.css');
	wp_enqueue_style('backup-guard-styles', plugin_dir_url(__FILE__) . 'public/css/styles.css');

	echo '<script type="text/javascript">sgBackup={};';
	$sgAjaxRequestFrequency = SGConfig::get('SG_AJAX_REQUEST_FREQUENCY');
	if (!$sgAjaxRequestFrequency) {
		$sgAjaxRequestFrequency = SG_AJAX_DEFAULT_REQUEST_FREQUENCY;
	}

	echo 'SG_AJAX_REQUEST_FREQUENCY = "' . intval($sgAjaxRequestFrequency) . '";';
	echo 'function getAjaxUrl(url) {' .
		'if (url==="cloudDropbox" || url==="cloudGdrive" || url==="cloudOneDrive"  || url==="cloudPCloud" || url==="cloudBox") return "' . admin_url('admin-post.php?action=backup_guard_') . '"+url+"&token=' . wp_create_nonce('backupGuardAjaxNonce') . '";' .
		'return "' . admin_url('admin-ajax.php') . '";}</script>';

	wp_enqueue_media();
	wp_enqueue_script('backup-guard-less-framework', plugin_dir_url(__FILE__) . 'public/js/less.min.js', array('jquery'), '1.0.0', true);
	wp_enqueue_script('backup-guard-bootstrap-framework', plugin_dir_url(__FILE__) . 'public/js/bootstrap.bundle.min.js', array('jquery'), '1.0.0', true);
	wp_enqueue_script('backup-guard-sgrequest-js', plugin_dir_url(__FILE__) . 'public/js/sgrequesthandler.js', array('jquery'), '1.0.0', true);
	wp_enqueue_script('backup-guard-sgwprequest-js', plugin_dir_url(__FILE__) . 'public/js/sgrequesthandler.wordpress.js', array('jquery'), '1.0.0', true);

	wp_enqueue_style('backup-guard-rateyo-css', plugin_dir_url(__FILE__) . 'public/css/jquery.rateyo.css');
	wp_enqueue_script('backup-guard-rateyo-js', plugin_dir_url(__FILE__) . 'public/js/jquery.rateyo.js');

	wp_enqueue_script('backup-guard-main-js', plugin_dir_url(__FILE__) . 'public/js/main.js', array('jquery', 'jquery-ui-tooltip'), '1.0.0', true);
	wp_enqueue_script('backup-popup.js', plugin_dir_url(__FILE__) . 'public/js/popup.js', array('jquery'), '1.0.0', true);
	wp_enqueue_style('popupTheme.css', plugin_dir_url(__FILE__) . 'public/css/popupTheme.css');

	// Localize the script with new data
	wp_localize_script(
		'backup-guard-main-js',
		'BG_MAIN_STRINGS',
		array(
			'confirmCancel' => _backupGuardT('Are you sure you want to cancel?', true)
		)
	);

	wp_localize_script(
		'backup-guard-main-js',
		'BG_BACKUP_STRINGS',
		array(
			'nonce' => wp_create_nonce('backupGuardAjaxNonce')
		)
	);
}

// adding actions to handle modal ajax requests
add_action('wp_ajax_backup_guard_modalManualBackup', 'backup_guard_get_manual_modal');
add_action('wp_ajax_backup_guard_modalSupport', 'backup_guard_get_support_modal');
add_action('wp_ajax_backup_guard_modalManualRestore', 'backup_guard_get_manual_restore_modal');
add_action('wp_ajax_backup_guard_modalManualExtract', 'backup_guard_get_manual_extract_modal');
add_action('wp_ajax_backup_guard_modalImport', 'backup_guard_get_import_modal');
add_action('wp_ajax_backup_guard_modalFtpSettings', 'backup_guard_get_ftp_modal');
add_action('wp_ajax_backup_guard_modalAmazonSettings', 'backup_guard_get_amazon_modal');
add_action('wp_ajax_backup_guard_modalPrivacy', 'backup_guard_get_privacy_modal');
add_action('wp_ajax_backup_guard_modalTerms', 'backup_guard_get_terms_modal');
add_action('wp_ajax_backup_guard_modalReview', 'backup_guard_get_review_modal');
add_action('wp_ajax_backup_guard_getFileDownloadProgress', 'backup_guard_get_file_download_progress');
add_action('wp_ajax_backup_guard_modalCreateSchedule', 'backup_guard_create_schedule');
add_action('wp_ajax_backup_guard_getBackupContent', 'backup_guard_get_backup_content');
add_action('wp_ajax_backup_guard_createSupportUser', 'backup_guard_create_support_user');

add_action('wp_ajax_backup_guard_modalBackupGuardDetails', 'backup_guard_get_backup_guard_modal');

function backup_guard_get_backup_guard_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalBackupGuardDetails.php';
	exit();
}

function backup_guard_get_file_download_progress()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'getFileDownloadProgress.php';
	exit();
}

function backup_guard_create_schedule()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalCreateSchedule.php';
	exit();
}

function backup_guard_get_manual_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	if (current_user_can('activate_plugins') || (defined('SG_USER_MODE') && SG_USER_MODE)) {
		include_once SG_PUBLIC_AJAX_PATH . 'modalManualBackup.php';
	}
	exit();
}

function backup_guard_get_support_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	if (current_user_can('activate_plugins') || (defined('SG_USER_MODE') && SG_USER_MODE)) {
		include_once SG_PUBLIC_AJAX_PATH . 'modalSupport.php';
	}
	exit();
}

function backup_guard_get_manual_restore_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalManualRestore.php';
	exit();
}

function backup_guard_get_manual_extract_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalManualExtract.php';
	exit();
}

function backup_guard_get_backup_content()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'getBackupContent.php';
	exit();
}

function backup_guard_get_import_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalImport.php';
	exit();
}

function backup_guard_get_ftp_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalFtpSettings.php';
	exit();
}

function backup_guard_get_amazon_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalAmazonSettings.php';
	exit();
}

function backup_guard_get_privacy_modal()
{
	include_once SG_PUBLIC_AJAX_PATH . 'modalPrivacy.php';
}

function backup_guard_get_terms_modal()
{
	include_once SG_PUBLIC_AJAX_PATH . 'modalTerms.php';
	exit();
}

function backup_guard_get_review_modal()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'modalReview.php';
	exit();
}

function backup_guard_register_ajax_callbacks()
{
	if (is_super_admin() || (defined('SG_USER_MODE') && SG_USER_MODE)) {
		// adding actions to handle ajax and post requests
		add_action('wp_ajax_backup_guard_cancelBackup', 'backup_guard_cancel_backup');
		add_action('wp_ajax_backup_guard_checkBackupCreation', 'backup_guard_check_backup_creation');
		add_action('wp_ajax_backup_guard_checkRestoreCreation', 'backup_guard_check_restore_creation');
		add_action('wp_ajax_backup_guard_checkExtractCreation', 'backup_guard_check_extract_creation');
		add_action('wp_ajax_backup_guard_cloudDropbox', 'backup_guard_cloud_dropbox');
		add_action('wp_ajax_backup_guard_send_usage_status', 'backup_guard_send_usage_status');

		$pluginCapabilities = backupGuardGetCapabilities();
		if ($pluginCapabilities != BACKUP_GUARD_CAPABILITIES_FREE) {
			include_once dirname(__FILE__) . '/BackupGuardPro.php';
		}
		add_action('wp_ajax_backup_guard_curlChecker', 'backup_guard_curl_checker');
		add_action('wp_ajax_backup_guard_deleteBackup', 'backup_guard_delete_backup');
		add_action('wp_ajax_backup_guard_cleanDbBackups', 'backup_guard_clean_db_backups');
		add_action('wp_ajax_backup_guard_getAction', 'backup_guard_get_action');
		add_action('wp_ajax_backup_guard_getRunningActions', 'backup_guard_get_running_actions');
		add_action('wp_ajax_backup_guard_importBackup', 'backup_guard_get_import_backup');
		add_action('wp_ajax_backup_guard_resetStatus', 'backup_guard_reset_status');
		add_action('wp_ajax_backup_guard_restore', 'backup_guard_restore');
		add_action('wp_ajax_backup_guard_extract', 'backup_guard_extract');
		add_action('wp_ajax_backup_guard_saveCloudFolder', 'backup_guard_save_cloud_folder');
		add_action('wp_ajax_backup_guard_schedule', 'backup_guard_schedule');
		add_action('wp_ajax_backup_guard_settings', 'backup_guard_settings');
		add_action('wp_ajax_backup_guard_setReviewPopupState', 'backup_guard_set_review_popup_state');
		add_action('wp_ajax_backup_guard_sendUsageStatistics', 'backup_guard_send_usage_statistics');
		add_action('wp_ajax_backup_guard_hideNotice', 'backup_guard_hide_notice');
		add_action('wp_ajax_backup_guard_downloadFromCloud', 'backup_guard_download_from_cloud');
		add_action('wp_ajax_backup_guard_listStorage', 'backup_guard_list_storage');
		add_action('wp_ajax_backup_guard_cancelDownload', 'backup_guard_cancel_download');
		add_action('wp_ajax_backup_guard_awake', 'backup_guard_awake');
		add_action('wp_ajax_backup_guard_awake_new', 'backup_guard_awake_new');
		add_action('wp_ajax_backup_guard_manualBackup', 'backup_guard_manual_backup');
		add_action('admin_post_backup_guard_downloadBackup', 'backup_guard_download_backup');
		add_action('wp_ajax_backup_guard_login', 'backup_guard_login');
		add_action('wp_ajax_backup_guard_logout', 'backup_guard_logout');
		add_action('wp_ajax_backup_guard_importKeyFile', 'backup_guard_import_key_file');
		add_action('wp_ajax_backup_guard_isFeatureAvailable', 'backup_guard_is_feature_available');
		add_action('wp_ajax_backup_guard_checkFreeMigration', 'backup_guard_check_free_migration');
		add_action('wp_ajax_backup_guard_checkPHPVersionCompatibility', 'backup_guard_check_php_version_compatibility');
		add_action('wp_ajax_backup_guard_reviewDontShow', 'backup_guard_review_dont_show');
		add_action('wp_ajax_backup_guard_review_later', 'backup_guard_review_later');
		add_action('wp_ajax_backup_guard_closeFreeBanner', 'wp_ajax_backup_guard_close_free_banner');
		add_action('wp_ajax_backup_guard_awake_frontend', 'backup_guard_awake_frontend');
	}
}

function wp_ajax_backup_guard_close_free_banner()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	SGConfig::set('SG_CLOSE_FREE_BANNER', 1);
	wp_die();
}

function backup_guard_review_dont_show()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	SGConfig::set('closeReviewBanner', 1);
	wp_die();
}

function backup_guard_review_later()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'reviewBannerActions.php';
	wp_die();
}

function backup_guard_is_feature_available()
{
	include_once SG_PUBLIC_AJAX_PATH . 'isFeatureAvailable.php';
}

function backup_guard_check_free_migration()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	if (SG_PRODUCT_IDENTIFIER == 'backup-guard-wp-free') {
		include_once SG_PUBLIC_AJAX_PATH . 'checkFreeMigration.php';
	}
	die;
}

function backup_guard_check_php_version_compatibility()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'checkPHPVersionCompatibility.php';
}

add_action('init', 'backup_guard_init');
add_action('wp_ajax_nopriv_backup_guard_awake', 'backup_guard_awake_nopriv');
add_action('wp_ajax_nopriv_backup_guard_awake_new', 'backup_guard_awake_nopriv_new');
add_action('admin_post_backup_guard_cloudDropbox', 'backup_guard_cloud_dropbox');

function backup_guard_import_key_file()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'importKeyFile.php';
}

function backup_guard_awake()
{
	_jet_secureAjax();

	include_once SG_CORE_PATH . 'backup/SGBackup.php';
	$sgbgBackup = new SGBackup();
	$SGBGStateJson = new SGBGStateJson();

	if (SGConfig::get('SG_RESTORE_FINALIZE', true)) {
		SGConfig::set('SG_RESTORE_FINALIZE', 0, true);
		die;
	}

	$actions = SGBackup::getRunningActions();

	if ($actions && count($actions)) {
		$action = $actions[0];
		$type = $action['type'] ?? null;

		switch ($type) {
			case SG_ACTION_TYPE_BACKUP:
			case SG_ACTION_TYPE_UPLOAD:
				$options = $SGBGStateJson->DoJson('json_decode', $action['options']);
				$sgbgBackup->backup($options);
				break;
			default:
				return;
		}
	}
}

function backup_guard_awake_nopriv()
{
	backup_guard_awake();
}

/**
 * @throws SGExceptionMethodNotAllowed
 * @throws SGExceptionForbidden
 * @throws SGExceptionDatabaseError
 */
function backup_guard_awake_nopriv_new()
{
	include_once SG_CORE_PATH . 'backup/SGBackup.php';
	$sgbgBackup = new SGBackup();
	$SGBGStateJson = new SGBGStateJson();
	$restore = new Restore();

	$actions = SGBackup::getRunningActions();
	if ($actions && count($actions)) {

		$action = $actions[0];
		$type = $action['type'] ?? null;

		switch ($type) {
			case SG_ACTION_TYPE_RESTORE:
				$restore->doRestore($action['name'], null, null, null);
				break;

			default:
				$options = $SGBGStateJson->DoJson('json_decode', $action['options']);
				$sgbgBackup->backup($options, true);
		}
	}
}

function backup_guard_cancel_download()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'cancelDownload.php';
}

function backup_guard_list_storage()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'listStorage.php';
}

function backup_guard_download_from_cloud()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'downloadFromCloud.php';
}

function backup_guard_hide_notice()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'hideNotice.php';
}

function backup_guard_cancel_backup()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'cancelBackup.php';
}

function backup_guard_check_backup_creation()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'checkBackupCreation.php';
}

function backup_guard_check_restore_creation()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'checkRestoreCreation.php';
}

function backup_guard_check_extract_creation()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'checkExtractCreation.php';
}

function backup_guard_cloud_dropbox()
{
	if (current_user_can('activate_plugins') || (defined('SG_USER_MODE') && SG_USER_MODE)) {
		check_ajax_referer('backupGuardAjaxNonce', 'token');
		include_once SG_PUBLIC_AJAX_PATH . 'cloudDropbox.php';
	}
}

function backup_guard_send_usage_status()
{

	if (current_user_can('activate_plugins') || (defined('SG_USER_MODE') && SG_USER_MODE)) {
		check_ajax_referer('backupGuardAjaxNonce', 'token');
		include_once SG_PUBLIC_AJAX_PATH . 'sendUsageStatus.php';
	}
}

function backup_guard_curl_checker()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'curlChecker.php';
}

function backup_guard_delete_backup()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'deleteBackup.php';
}

function backup_guard_clean_db_backups()
{
    check_ajax_referer('backupGuardAjaxNonce', 'token');
    include_once SG_PUBLIC_AJAX_PATH . 'cleanDbBackup.php';
}

function backup_guard_download_backup()
{
	include_once SG_PUBLIC_AJAX_PATH . 'downloadBackup.php';
}

function backup_guard_get_action()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'getAction.php';
}

function backup_guard_get_running_actions()
{
	include_once SG_PUBLIC_AJAX_PATH . 'getRunningActions.php';
}

function backup_guard_get_import_backup()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'importBackup.php';
}

function backup_guard_manual_backup()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'manualBackup.php';
}

function backup_guard_reset_status()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'resetStatus.php';
}

function backup_guard_restore()
{
	include_once SG_PUBLIC_AJAX_PATH . 'restore.php';
}

function backup_guard_extract()
{
	include_once SG_PUBLIC_AJAX_PATH . 'extract.php';
}

function backup_guard_save_cloud_folder()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'saveCloudFolder.php';
}

function backup_guard_schedule()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'schedule.php';
}

function backup_guard_settings()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'settings.php';
}

function backup_guard_set_review_popup_state()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'setReviewPopupState.php';
}

function backup_guard_send_usage_statistics()
{
	include_once SG_PUBLIC_AJAX_PATH . 'sendUsageStatistics.php';
}

function backup_guard_login()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'login.php';
}

function backup_guard_logout()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'logout.php';
}

//adds once weekly to the existing schedules.
add_filter('cron_schedules', 'backup_guard_cron_add_weekly');
function backup_guard_cron_add_weekly($schedules)
{
	$schedules['weekly'] = array(
		'interval' => 60 * 60 * 24 * 7,
		'display' => 'Once weekly'
	);
	return $schedules;
}

//adds once monthly to the existing schedules.
add_filter('cron_schedules', 'backup_guard_cron_add_monthly');
function backup_guard_cron_add_monthly($schedules)
{
	$schedules['monthly'] = array(
		'interval' => 60 * 60 * 24 * 30,
		'display' => 'Once monthly'
	);
	return $schedules;
}

//adds once yearly to the existing schedules.
add_filter('cron_schedules', 'backup_guard_cron_add_yearly');
function backup_guard_cron_add_yearly($schedules)
{
	$schedules['yearly'] = array(
		'interval' => 60 * 60 * 24 * 30 * 12,
		'display' => 'Once yearly'
	);
	return $schedules;
}

function backup_guard_init()
{
	backup_guard_register_ajax_callbacks();
	// backupGuardPluginRedirect();

	// check if database should be updated
	if (backupGuardShouldUpdate()) {
		SGBoot::install();
	}

	backupGuardSymlinksCleanup(SG_SYMLINK_PATH);
	backupguard_genkey();
}

add_action(SG_SCHEDULE_ACTION, 'backup_guard_schedule_action', 10, 1);

/**
 * @throws Exception
 */
function backup_guard_schedule_action($id)
{
	jet_backup_reload_schedule_action($id);
}

function sgBackupAdminInit()
{
    // This runs plugin updater check in real time (commercial version only)
    // Background checks still runs in the background
    // load pro plugin updater
    $pluginCapabilities = backupGuardGetCapabilities();
    $isLoggedIn = is_user_logged_in();

    if ($pluginCapabilities != BACKUP_GUARD_CAPABILITIES_FREE && $isLoggedIn) {
        include_once dirname(__FILE__) . '/plugin-update-checker/plugin-update-checker.php';
        include_once dirname(__FILE__) . '/plugin-update-checker/Puc/v5p0/Scheduler.php';
        include_once SG_LIB_PATH . 'SGAuthClient.php';

        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5p0\PucFactory::buildUpdateChecker(
            BackupGuard\Config::VERSION_URL,
            SG_BACKUP_GUARD_MAIN_FILE,
            SG_PRODUCT_IDENTIFIER
        );
    }
}

add_action('admin_init', 'sgBackupAdminInit');

if (SGBoot::isFeatureAvailable('ALERT_BEFORE_UPDATE')) {
	add_action('core_upgrade_preamble', 'backupGuardOnUpgradeScreenActivate');
	add_action('current_screen', 'backupGuardOnScreenActivate');
}

// Register the new dashboard widget with the 'wp_dashboard_setup' action
add_action('wp_dashboard_setup', 'backup_guard_add_dashboard_widgets');

function backup_guard_add_dashboard_widgets()
{

	if (!is_user_logged_in() || !current_user_can('manage_options')) return;

	include_once SG_CORE_PATH . 'SGConfig.php';
	if (!SGConfig::get('SG_SHOW_STATISTICS_WIDGET')) return;

	include_once plugin_dir_path(__FILE__) . 'public/dashboardWidget.php';
	wp_add_dashboard_widget('backupGuardWidget', 'JetBackup', 'backup_guard_dashboard_widget_function');
}

add_action('plugins_loaded', 'backupGuardloadTextDomain');
function backupGuardloadTextDomain()
{
	$backupGuardLangDir = plugin_dir_path(__FILE__) . 'languages' . DIRECTORY_SEPARATOR;
	$backupGuardLangDir = apply_filters('backupguardLanguagesDirectory', $backupGuardLangDir);
	$LangDirRelative = 'wp-content' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . BACKUP_GUARD_TEXTDOMAIN . DIRECTORY_SEPARATOR . 'languages';

	$locale = apply_filters('bg_plugin_locale', get_locale(), BACKUP_GUARD_TEXTDOMAIN);
	$mofile = sprintf('%1$s-%2$s.mo', BACKUP_GUARD_TEXTDOMAIN, $locale);

	$mofileLocal = $backupGuardLangDir . $mofile;

	if (file_exists($mofileLocal)) {
		// Look in local /wp-content/plugins/popup-builder/languages/ folder
		load_textdomain(BACKUP_GUARD_TEXTDOMAIN, $mofileLocal);
	} else {
		// Load the default language files
		load_plugin_textdomain(BACKUP_GUARD_TEXTDOMAIN, false, $LangDirRelative);
	}
}

add_action('admin_notices', 'backup_guard_review_banner');
function backup_guard_review_banner()
{
	include_once SG_LIB_PATH . 'SGReviewManager.php';
	$reviewManager = new SGReviewManager();
	$reviewManager->renderContent();
}

add_filter('cron_schedules', 'jet_backup_cron_job_interval');
function jet_backup_cron_job_interval($schedules)
{
	$schedules['sixty_seconds'] = array(
		'interval' => JBWP_CRON_RELOAD_INTERVAL,
		'display' => esc_html__('Every sixty seconds')
	);

	return $schedules;
}

/**
 * @throws Exception
 */
function run_next_chunk_of_backup()
{

	$SGBGChunks = new SGBGChunks();
	$SGBGChunks->run_chunk();

}

add_action('plugins_loaded', 'jet_backup_reload_schedule_action');

add_action(JBWP_RELOAD_SCHEDULE_ACTION, 'jet_backup_reload_schedule_action', 10, null);

/**
 * @throws Exception
 */
function jet_backup_reload_schedule_action($noreferer = false)
{
	$reloader = SGBGReloader::getInstance();
	$reloader->reload();

}


/**
 * @throws Exception
 */
function backup_guard_awake_frontend()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	run_next_chunk_of_backup();
}

function backup_guard_create_support_user()
{
	check_ajax_referer('backupGuardAjaxNonce', 'token');
	include_once SG_PUBLIC_AJAX_PATH . 'createSupportUser.php';
	exit();
}