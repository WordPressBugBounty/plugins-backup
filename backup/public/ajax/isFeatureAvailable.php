<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

$sgFeature = $_POST['sgFeature'];
if (!SGBoot::isFeatureAvailable($sgFeature)) {
    die('{"error":' . _backupGuardT("This feature is not available in your package.", true) . '"}');
}

die('{"success":1}');
