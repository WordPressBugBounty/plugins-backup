<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

require_once(dirname(__FILE__) . '/config.php');

//Plugin's directory name
if (!defined('SG_PLUGIN_NAME')) {
    define('SG_PLUGIN_NAME', basename(dirname(SG_PUBLIC_PATH)));
}

//Urls
if (!defined('SG_PUBLIC_URL')) {
    define('SG_PUBLIC_URL', plugins_url() . '/' . SG_PLUGIN_NAME . '/public/');
}
define('SG_PUBLIC_AJAX_URL', SG_PUBLIC_URL . 'ajax/');
define('SG_PUBLIC_BACKUPS_URL', network_admin_url('admin.php?page=backup_guard_backups'));
define('SG_PUBLIC_CLOUD_URL', network_admin_url('admin.php?page=backup_guard_cloud'));
define('SG_BACKUP_GUARD_REVIEW_URL', 'https://wordpress.org/support/view/plugin-reviews/backup?filter=5');
define('SG_IMAGE_URL', SG_PUBLIC_URL . 'img/');

//JetBackup Site URL
if (!defined('SG_BACKUP_SITE_URL')) {
    define('SG_BACKUP_SITE_URL', 'https://www.jetbackup.com/jetbackup-for-wordpress');
}
define('SG_BACKUP_UPGRADE_URL', 'https://www.jetbackup.com/jetbackup-for-wordpress');

define('SG_BACKUP_SITE_PRICING_URL', 'https://jetbackup.com/pricing/');

// banner URLS
define('SG_BACKUP_KNOWLEDGE_BASE_URL', 'https://billing.jetapps.com/index.php?rp=/knowledgebase/31/JetBackup-for-WordPress');
define('SG_BACKUP_DEMO_URL', 'https://jetbackup.com/demo/');
define('SG_BACKUP_FAQ_URL', 'https://docs.jetbackup.com/wordpress/jbwp/faq.html');
define('SG_BACKUP_CONTACT_US_URL', 'https://wordpress.org/support/plugin/backup/');
