<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

$archiveName = $VARS['archiveName'];

if ($VARS['succeeded'] == true) {
	echo 'The '.$archiveName.' archive upload succeeded.';
}
else {
	echo 'The '.$archiveName.' archive upload failed.';
}
