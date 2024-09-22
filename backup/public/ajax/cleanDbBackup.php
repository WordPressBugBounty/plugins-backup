<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();
require_once(SG_BACKUP_PATH . 'SGBackup.php');

SGBackup::dropActionsList();
die('{"success":1}');
