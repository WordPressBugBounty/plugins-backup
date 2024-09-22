<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

interface SGINoticeAdapter
{
	public function addNotice($notice, $type);
	public function addNoticeFromTemplate($template, $type);
	public function renderAll();
}
