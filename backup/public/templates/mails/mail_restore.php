<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

$archiveName = $VARS['archiveName'];

if ($VARS['succeeded']==true) {
	echo 'The '.$archiveName.' archive was successfully restored.';
}
else {
	echo 'The '.$archiveName.' archive could not be restored.';
}
?>
