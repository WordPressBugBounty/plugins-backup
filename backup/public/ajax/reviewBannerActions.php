<?php

require_once(dirname(__FILE__) . '/../boot.php');
_jet_secureAjax();

require_once(SG_LIB_PATH . 'SGReviewManager.php');
$type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : null;
if (!$type) return;


switch ($type) {

    case 'dayCount':

		$timeDate = new \DateTime('now');
		$installTime = strtotime($timeDate->format('Y-m-d H:i:s'));
		SGConfig::set('installDate', $installTime);
		$timeDate->modify('+' . SG_BACKUP_REVIEW_PERIOD . ' day');

		$timeNow = strtotime($timeDate->format('Y-m-d H:i:s'));
		SGConfig::set('openNextTime', $timeNow);

		$usageDays = SGConfig::get('usageDays');
		$usageDays += SG_BACKUP_REVIEW_PERIOD;
		SGConfig::set('usageDays', $usageDays);
        break;

    case 'backupCount':
		$backupCountReview = SGConfig::get('backupReviewCount');
		if (empty($backupCountReview)) {
			$backupCountReview = SGReviewManager::getBackupCounts();
		}
		$backupCountReview += SG_BACKUP_REVIEW_BACKUP_COUNT;
		SGConfig::set('backupReviewCount', $backupCountReview);
        break;

    case 'restoreCount':
		$restoreReviewCount = SGConfig::get('restoreReviewCount');
		if (empty($restoreReviewCount)) {
			$restoreReviewCount = SGReviewManager::getBackupRestoreCounts();
		}
		$restoreReviewCount += SG_BACKUP_REVIEW_RESTORE_COUNT;
		SGConfig::set('restoreReviewCount', $restoreReviewCount);
        break;

}
