<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/**
 * Plugin Name:       JetBackup 
 * Plugin URI:        https://www.jetbackup.com/jetbackup-for-wordpress
 * Description:       JetBackup is the most complete WordPress site backup and restore plugin. We offer the easiest way to backup, restore or migrate your site. You can backup your files, database or both.
 * Version:           2.0.9.15
 * Author:            JetBackup
 * Author URI:        https://www.jetbackup.com/jetbackup-for-wordpress
 * License:           Commercial Software License
 *  URI:
 */

if (function_exists('activate_backup_guard')) {
	die('Please deactivate any other JetBackup version before activating this one.');
}

if (!defined('SG_BACKUP_GUARD_VERSION')) {
	define('SG_BACKUP_GUARD_VERSION', '2.0.9.15');
}

if (!defined('SG_BACKUP_GUARD_MAIN_FILE')) {
	define('SG_BACKUP_GUARD_MAIN_FILE', __FILE__);
}

if (!defined('SG_FORCE_DB_TABLES_RESET')) {
	define('SG_FORCE_DB_TABLES_RESET', false);
}

//if this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

require_once(plugin_dir_path(__FILE__) . 'public/boot.php');
require_once(plugin_dir_path(__FILE__) . 'BackupGuard.php');