<?php
if (!defined('WPINC')) die ('Direct access is not allowed');

/*
 *  Class will try to create a crontab entry for background tasks
 */

class CronTab {

	const TEMP_CRON = SG_BACKUP_DIRECTORY . '.temp_cron.php';

	// Verify if we have open functions to work with, array for a function, null for nothing

	private $_crontab;

	public function init()  {

		if (SGConfig::get('SG_CRONTAB_ADDED')) return;
		if (!$this->isLinux()) return;
		$this->cleanup();
		$this->setCrontab();
		$this->AddCrontab();
		$this->cleanup();

	}

	public function isLinux () {

		return stristr(PHP_OS, 'Linux');


	}
	public function cleanup () {
		if (file_exists(self::TEMP_CRON)) @unlink(self::TEMP_CRON);
	}
	public function getCrontab () {

		if (!$this->_crontab) $this->setCrontab();
		return $this->_crontab;

	}

	public function CreateTempCron ($remove = false): bool {

		$crontab = $this->buildCrontab($remove);

		$old = umask (077);
		$created = @file_put_contents(self::TEMP_CRON, $crontab);
		umask ($old);

		return $created;


	}

	public function buildCrontab ($remove = false): ?string {

		$OriginalCron = $this->getCrontab();
		if (!is_array($OriginalCron)) return null;

		$Cron = new Cron();
		$Execute = new Execute();
		$cronArray = array();

		$command = $Cron->getCommand(false);
		if (!$command) return null;


		switch ($remove) {

			case false:

				foreach ($OriginalCron as $line) {

					$array['output'][0] = $line; // We are only using this because 'isNoCrontab' expects this array structure
					if (trim($line) && $Execute->isNoCrontab($array)) continue;
					$cronArray[] = $line;

				}

				$cronArray[] = '* * * * * ' . $command;


				break;

			case true:

				$command = $Cron->getCommand(true);

				foreach ($OriginalCron as $line) {

					echo $line . PHP_EOL;

					if (strpos($line, $command) === false) $cronArray[] = $line;

				}


				break;


		}


		$cronArray[] = PHP_EOL; // prevents premature EOF error

		return implode(PHP_EOL , $cronArray);


	}

	public function removeCrontab () {


		if ( !$this->searchCron(true) ) return; // not found in crontab / cannot read - nothing to do
		if ( !$this->CreateTempCron(true) ) return; // cannot create tempcron, cannot continue

		$Execute = new Execute();
		$res = $Execute->runCommand('crontab ' . self::TEMP_CRON, null);
		if ($Execute->parseResultsCode($res)) SGConfig::set('SG_CRONTAB_ADDED', false);


	}

	public function AddCrontab () {

		$sg_crontab_added = SGConfig::get('SG_CRONTAB_ADDED') ?? null;

		if ($sg_crontab_added) return;
		if ( $this->searchCron(false) ) return; // entry exists or we can't read crontab
		if (!$this->CreateTempCron(false)) return;

		$Execute = new Execute();
		$res = $Execute->runCommand('crontab ' . self::TEMP_CRON, null);
		if ($Execute->parseResultsCode($res)) SGConfig::set('SG_CRONTAB_ADDED', true, true);

	}

	public function searchCron ($searchonly = false): bool
	{

		$Cron = new Cron;

		$command = $Cron->getCommand(true);
		$crontab = $this->getCrontab();

		if (!$crontab || !count($crontab) || !$command) return false;
		foreach ($crontab as $line) {

			if (strpos($line, $command)) {
				if (!$searchonly) SGConfig::set('SG_CRONTAB_ADDED', true, true);
				return true;
			}

		}

		return false;
		
	}
	
	/*
	 *  Verify we can access the crontab, results are array lines
	 */

	public function setCrontab () {

		$Execute = new Execute();

		$res = $Execute->runCommand('crontab -l', null);
		if (!$Execute->parseResultsCode($res)) return null;

		$this->_crontab = $res['output'] ?? null;

	}


}