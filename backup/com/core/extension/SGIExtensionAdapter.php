<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

interface SGIExtensionAdapter
{
	public function isExtensionActive($extension);
	public function isExtensionAvailable($extension);
	public function activateExtension($extensions);
	public function installExtension($extension);
	public function isExtensionAlreadyInPluginsFolder($extension);
	public function copyExtensionFilesToPLuginsFolder($extension);
}
