<?php

header('Content-Type: application/json');

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

//require_once(SG_LIB_PATH . 'SGArchive.php');
//require_once(SG_BACKUP_PATH . 'SGBackupFiles.php');

$backupName = isset($_GET['backupName']) ? sanitize_text_field($_GET['backupName']) : null;
$path = isset($_GET["path"]) ? sanitize_text_field($_GET["path"]) : "wp-content/";
$parent = $path;
$data = array();

$disabled = !SGBoot::isFeatureAvailable('SLECTIVE_RESTORE');

if ($path == "#") {
    $parentNode = array();
    $parentNode["id"] = "/";
    $parentNode["parent"] = "#";
    $parentNode["text"] = "/";
    $parentNode["type"] = "none";
    $parentNode["children"] = true;
    $parentNode["state"] = array("selected" => true);
    $data[] = $parentNode;
} else {
    if ($path == "/") {
        $path = "";
    } else {
        $path .= '/';
    }

    $backupPath = SG_BACKUP_DIRECTORY . $backupName;
    $backupPath = $backupPath . '/' . $backupName . '.sgbp';
    $backupFiles = new SGBackupFiles();
    $archive = new SGArchive($backupPath, 'r');
    $archive->setDelegate($backupFiles);
    $headers = $archive->getArchiveHeaders();
    $filesList = $archive->getFilesList();
    $tree = $archive->getTreefromList($filesList, $path);


    foreach ($tree as $node) {
        $el = array();
        $el["id"] = $path . $node->name;
        $el["parent"] = $parent;
        $el["text"] = $node->name;
        $el["type"] = $node->type;
        if ($node->type == "folder") {
            $el["children"] = true;
        }
        $data[] = $el;
    }
}

echo json_encode($data);
