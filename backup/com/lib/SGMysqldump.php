<?php
/**
 * SGMysqldump Class Doc Comment
 *
 * @category Library
 * @package  Ifsnop\Mysqldump
 * @author   Michael J. Calkins <clouddueling@github.com>
 * @author   Diego Torres <ifsnop@github.com>
 * @license  http://www.gnu.org/copyleft/gpl.html GNU General Public License
 * @link     https://github.com/ifsnop/mysqldump-php
 *
 */

interface SGIMysqldumpDelegate
{
    public function getLogFile();
    public function log($logData, $forceWrite = false);
}

class SGMysqldump
{
    // Same as mysqldump
    const MAXLINESIZE = 1000000;

    // Available compression methods as constants
    const GZIP  = 'Gzip';
    const BZIP2 = 'Bzip2';
    const NONE  = 'None';

    // Available connection strings
    const UTF8    = 'utf8';
    const UTF8MB4 = 'utf8mb4';

    // This can be set both on constructor or manually
    public $db;
    public $fileName;

    // Internal stuff
    private $tables           = array();
    private $views            = array();
    private $triggers         = array();
    private $dbHandler;
    private $dbType;
    private $compressManager;
    private $typeAdapter;
    private $dumpSettings     = array();
    private $version;
    private $tableColumnTypes = array();
    private $delegate         = null;
    private $excludeTables    = array();

    private $cursor         = 0;
    private $state          = null;
    private $inprogress     = false;
    private $backedUpTables = array();

    /**
     * Constructor of SGMysqldump. Note that in the case of an SQLite database
     * connection, the filename must be in the $db parameter.
     *
     * @param string $db Database name
     * @param string $type SQL database type
     * @param array $dumpSettings SQL database settings
     * @throws Exception
     */
    public function __construct(
        $dbHandler,
        string $db = '',
        string $type = 'mysql',
        array $dumpSettings = []
    ) {
        $dumpSettingsDefault = array(
            'include-tables'             => array(),
            'exclude-tables'             => array(),
            'compress'                   => SGMysqldump::NONE,
            'no-data'                    => false,
            'add-drop-table'             => false,
            'single-transaction'         => true,
            'lock-tables'                => true,
            'add-locks'                  => true,
            'extended-insert'            => true,
            'disable-keys'               => true,
            'where'                      => '',
            'no-create-info'             => false,
            'skip-triggers'              => false,
            'add-drop-trigger'           => true,
            'hex-blob'                   => true,
            'databases'                  => false,
            'add-drop-database'          => false,
            'skip-tz-utz'                => false,
            'no-autocommit'              => true,
            'default-character-set'      => SG_DB_CHARSET,
            'skip-comments'              => false,
            'skip-dump-date'             => false,
            /* deprecated */
            'disable-foreign-keys-check' => true
        );

        $this->db            = $db;
        $this->dbHandler     = $dbHandler;
        $this->dbType        = strtolower($type);
        $this->dumpSettings  = self::array_replace_recursive($dumpSettingsDefault, $dumpSettings);
        $this->excludeTables = $dumpSettings['exclude-tables'];

        $diff = array_diff(array_keys($this->dumpSettings), array_keys($dumpSettingsDefault));
        if (count($diff) > 0) {
            throw new Exception("Unexpected value in dumpSettings: (" . implode(",", $diff) . ")");
        }

        // Create a new compressManager to manage compressed output
        $this->compressManager = CompressManagerFactory::create($this->dumpSettings['compress']);
    }

    public function setDelegate(SGIMysqldumpDelegate $delegate)
    {
        $this->delegate = $delegate;
    }

    private function getState()
    {
        return $this->delegate->getState();
    }

    /**
     * Custom array_replace_recursive to be used if PHP < 5.3
     * Replaces elements from passed arrays into the first array recursively
     *
     * @param array $array1 The array in which elements are replaced
     * @param array $array2 The array from which elements will be extracted
     *
     * @return array Returns an array, or NULL if an error occurs.
     */
    public static function array_replace_recursive(array $array1, array $array2): array
    {
        if (function_exists('array_replace_recursive')) {
            return array_replace_recursive($array1, $array2);
        }

        foreach ($array2 as $key => $value) {
            if (is_array($value)) {
                $array1[$key] = self::array_replace_recursive($array1[$key], $value);
            } else {
                $array1[$key] = $value;
            }
        }
        return $array1;
    }

    /**
     * Connect with PDO
     *
     * @return null
     * @throws Exception
     */
    private function connect()
    {
        $this->dbHandler->query("SET NAMES " . $this->dumpSettings['default-character-set']);
        $this->typeAdapter = TypeAdapterFactory::create($this->dbType, $this->dbHandler);
        return;
    }

    /**
     * Main call
     *
     * @param string $filename Name of file to write sql dump to
     *
     * @return null
     * @throws SGException
     * @throws Exception
     */
    public function start(string $filename, $task)
    {
        $this->state = $task->getStateFile();

        $this->fileName = $filename;

        // Connect to database
        $this->connect();

        // Create output file
        $this->compressManager->open($this->fileName);

        if ($this->state->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
            // Write some basic info to output file
            $this->compressManager->write($this->getDumpFileHeader());

            // Store server settings and use sanner defaults to dump
            $this->compressManager->write(
                $this->typeAdapter->backup_parameters($this->dumpSettings)
            );

            if ($this->dumpSettings['databases']) {
                $this->compressManager->write(
                    $this->typeAdapter->getDatabaseHeader($this->db)
                );
                if ($this->dumpSettings['add-drop-database']) {
                    $this->compressManager->write(
                        $this->typeAdapter->add_drop_database($this->db)
                    );
                }
            }
        }

        // Get table, view and trigger structures from database
        $this->getDatabaseStructure();

        if ($this->state->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
            if ($this->dumpSettings['databases']) {
                $this->compressManager->write(
                    $this->typeAdapter->databases($this->db)
                );
            }

            // If there still are some tables/views in include-tables array,
            // that means that some tables or views weren't found.
            // Give proper error and exit.
            if (0 < count($this->dumpSettings['include-tables'])) {
                $name = implode(",", $this->dumpSettings['include-tables']);
                throw new SGException("Table or View (" . $name . ") not found in database");
            }
        }

        $this->exportTables();
        $this->exportViews();
        $this->exportTriggers();

        // Restore saved parameters
        $this->compressManager->write(
            $this->typeAdapter->restore_parameters($this->dumpSettings)
        );
        // Write some stats to output file
        $this->compressManager->write($this->getDumpFileFooter());
        // Close output file
        $this->compressManager->close();
        return;
    }

    /**
     * Returns header for dump file
     *
     * @return string
     */
    private function getDumpFileHeader(): string
    {
        $header = '';
        if (!$this->dumpSettings['skip-comments']) {
            // Some info about software, source and time
            if (!empty($this->version)) {
                $header .= "-- Server version \t" . $this->version . PHP_EOL;
            }

            $header .= "-- Date: " . @date('r') . PHP_EOL . PHP_EOL;
        }
        return $header;
    }

    /**
     * Returns footer for dump file
     *
     * @return string
     */
    private function getDumpFileFooter(): string
    {
        $footer = '';
        if (!$this->dumpSettings['skip-comments']) {
            $footer .= '-- Dump completed';
            if (!$this->dumpSettings['skip-dump-date']) {
                $footer .= ' on: ' . @date('r');
            }
            $footer .= PHP_EOL;
        }

        return $footer;
    }

    /**
     * Reads table and views names from database.
     * Fills $this->tables array so they will be dumped later.
     *
     * @return null
     */
    private function getDatabaseStructure()
    {
        // Listing all tables from database
        if (empty($this->dumpSettings['include-tables'])) {
            // include all tables for now, blacklisting happens later
            $arr = $this->dbHandler->query($this->typeAdapter->show_tables($this->db));
            foreach ($arr as $row) {
                // Push wp_options table to front to solve restore related bug
                if ($row['tbl_name'] != SG_ENV_DB_PREFIX . 'options') {
                    $this->tables[] = $row['tbl_name'];
                } else {
                    array_unshift($this->tables, $row['tbl_name']);
                }
            }
        } else {
            // include only the tables mentioned in include-tables
            $arr = $this->dbHandler->query($this->typeAdapter->show_tables($this->db));
            foreach ($arr as $row) {
                if (in_array($row['tbl_name'], $this->dumpSettings['include-tables'], true)) {
                    $this->tables[] = $row['tbl_name'];
                    $elem = array_search(
                        $row['tbl_name'],
                        $this->dumpSettings['include-tables']
                    );
                    unset($this->dumpSettings['include-tables'][$elem]);
                }
            }
        }

        // Listing all views from database
        if (empty($this->dumpSettings['include-tables'])) {
            // include all views for now, blacklisting happens later
            $arr = $this->dbHandler->query($this->typeAdapter->show_views($this->db));
            foreach ($arr as $row) {
                $this->views[] = $row['tbl_name'];
            }
        } else {
            // include only the tables mentioned in include-tables
            $arr = $this->dbHandler->query($this->typeAdapter->show_views($this->db));
            foreach ($arr as $row) {
                if (in_array($row['tbl_name'], $this->dumpSettings['include-tables'], true)) {
                    $this->views[] = $row['tbl_name'];
                    $elem = array_search(
                        $row['tbl_name'],
                        $this->dumpSettings['include-tables']
                    );
                    unset($this->dumpSettings['include-tables'][$elem]);
                }
            }
        }

        // Listing all triggers from database
        if (false === $this->dumpSettings['skip-triggers']) {
            $arr = $this->dbHandler->query($this->typeAdapter->show_triggers($this->db));
            foreach ($arr as $row) {
                $this->triggers[] = $row['Trigger'];
            }
        }
        return;
    }

    /**
     * Exports all the tables selected from database
     *
     * @return null
     * @throws Exception
     */
    private function exportTables()
    {
        if ($this->state->getAction() != SG_STATE_ACTION_PREPARING_STATE_FILE) {
            $this->cursor         = $this->state->getCursor();
            $this->inprogress     = $this->state->getInprogress();
            $this->backedUpTables = $this->state->getBackedUpTables();
        } else {
            $this->cursor         = 0;
            $this->inprogress     = false;
            $this->backedUpTables = array();
        }

        // Exporting tables one by one
        for ($i = $this->cursor; $i < count($this->tables); $i++) {
            $table = $this->tables[$i];
            if (in_array($table, $this->dumpSettings['exclude-tables'], true)) {
                if ($this->delegate) {
                    $this->delegate->log('Exclude table ' . $table, true);
                }

                $this->cursor += 1;
                continue;
            }

            if (!$this->inprogress) {
                if ($this->delegate) {
                    $this->delegate->log('Start backup table ' . $table, true);
                    //$this->delegate->getLogFile()->getCache()->flush();
                }

                $this->getTableStructure($table, false);
            } else {
                $this->getTableStructure($table, true);
            }

            if (false === $this->dumpSettings['no-data']) {
                $this->listValues($table);
            }

            $this->backedUpTables[] = $table;

            if ($this->delegate) {
                $this->delegate->log('End backup table ' . $table, true);
                $progress = round(($i / count($this->tables)) * 100);
                $this->delegate->changeActionProgress($this->state->getActionId(), $progress);
                $this->state->setProgress($progress);
                $this->state->save(true);
                //$this->delegate->getLogFile()->getCache()->flush();
            }
        }

        if ($this->delegate) {
            //$this->delegate->getLogFile()->getCache()->flush();
        }

        SGConfig::set('SG_BACKUPED_TABLES', json_encode($this->backedUpTables));
        return;
    }

    /**
     * Exports all the views found in database
     *
     * @return null
     * @throws Exception
     */
    private function exportViews()
    {
        if (false === $this->dumpSettings['no-create-info']) {
            // Exporting views one by one
            foreach ($this->views as $view) {
                if (in_array($view, $this->dumpSettings['exclude-tables'], true)) {
                    continue;
                }
                $this->getViewStructure($view);
            }
        }
        return;
    }

    /**
     * Exports all the triggers found in database
     *
     * @return null
     * @throws Exception
     */
    private function exportTriggers()
    {
        // Exporting triggers one by one
        foreach ($this->triggers as $trigger) {
            $this->getTriggerStructure($trigger);
        }
        return;
    }

    /**
     * Table structure extractor
     *
     * @param string $tableName Name of table to export
     *
     * @return null
     * @throws Exception
     * @todo move specific mysql code to typeAdapter
     */
    private function getTableStructure(string $tableName, $skipCreate = false)
    {
        if (!$skipCreate && !$this->dumpSettings['no-create-info']) {
            $ret = '';
            if (!$this->dumpSettings['skip-comments']) {
                $ret = "--" . PHP_EOL .
                       "-- Table structure for table `$tableName`" . PHP_EOL .
                       "--" . PHP_EOL . PHP_EOL;
            }
            $stmt = $this->typeAdapter->show_create_table($tableName);
            $arr  = $this->dbHandler->query($stmt);
            foreach ($arr as $r) {
                $this->compressManager->write($ret);
                if ($this->dumpSettings['add-drop-table']) {
                    $this->compressManager->write(
                        $this->typeAdapter->drop_table($tableName)
                    );
                }
                $this->compressManager->write(
                    $this->typeAdapter->create_table($r, $this->dumpSettings)
                );
                break;
            }
        }

        $columnTypes = array();
        $columns     = $this->dbHandler->query(
            $this->typeAdapter->show_columns($tableName)
        );

        foreach ($columns as $key => $col) {
            $types                      = $this->typeAdapter->parseColumnType($col);
            $columnTypes[$col['Field']] = array(
                'is_numeric' => $types['is_numeric'],
                'is_blob'    => $types['is_blob'],
                'type'       => $types['type']
            );
        }
        $this->tableColumnTypes[$tableName] = $columnTypes;
        return;
    }

    /**
     * View structure extractor
     *
     * @param string $viewName Name of view to export
     *
     * @return null
     * @throws Exception
     * @todo move mysql specific code to typeAdapter
     */
    private function getViewStructure(string $viewName)
    {
        $ret = '';
        if (!$this->dumpSettings['skip-comments']) {
            $ret = "--" . PHP_EOL .
                   "-- Table structure for view `{$viewName}`" . PHP_EOL .
                   "--" . PHP_EOL . PHP_EOL;
        }
        $this->compressManager->write($ret);
        $stmt = $this->typeAdapter->show_create_view($viewName);
        $arr  = $this->dbHandler->query($stmt);
        foreach ($arr as $r) {
            if ($this->dumpSettings['add-drop-table']) {
                $this->compressManager->write(
                    $this->typeAdapter->drop_view($viewName)
                );
            }
            $this->compressManager->write(
                $this->typeAdapter->create_view($r)
            );
            break;
        }
        return;
    }

    /**
     * Trigger structure extractor
     *
     * @param string $triggerName Name of trigger to export
     *
     * @return null
     * @throws Exception
     */
    private function getTriggerStructure(string $triggerName)
    {
        $stmt = $this->typeAdapter->show_create_trigger($triggerName);
        $arr  = $this->dbHandler->query($stmt);
        foreach ($arr as $r) {
            if ($this->dumpSettings['add-drop-trigger']) {
                $this->compressManager->write(
                    $this->typeAdapter->add_drop_trigger($triggerName)
                );
            }
            $this->compressManager->write(
                $this->typeAdapter->create_trigger($r)
            );
        }
        return;
    }

    /**
     * Escape values with quotes when needed
     *
     * @param string $tableName Name of table which contains rows
     * @param array $row       Associative array of column names and values to be quoted
     *
     * @return array
     */
    private function escape(string $tableName, array $row): array
    {
        $ret         = array();
        $columnTypes = $this->tableColumnTypes[$tableName];
        foreach ($row as $colName => $colValue) {
            if (is_null($colValue)) {
                $ret[] = "NULL";
            } elseif ($this->dumpSettings['hex-blob'] && $columnTypes[$colName]['is_blob']) {
                if ($columnTypes[$colName]['type'] == 'bit' || !empty($colValue)) {
                    $ret[] = "0x{$colValue}";
                } else {
                    $ret[] = "''";
                }
            } elseif ($columnTypes[$colName]['is_numeric']) {
                $ret[] = $colValue;
            } else {
                $str   = "'" . str_replace("'", "''", $colValue) . "'";
                $str   = str_replace("\\", "\\\\", $str);
                $ret[] = $str;
            }
        }
        return $ret;
    }

    /**
     * Table rows extractor
     *
     * @param string $tableName Name of table to export
     *
     * @return null
     * @throws Exception
     */
    private function listValues(string $tableName)
    {
        if (strpos($tableName, 'redirection_404') || strpos($tableName, 'aioseo_links_suggestions')) {
            return;
        }

        if (!$this->state->getInprogress()) {
            $this->prepareListValues($tableName);
        }

        $onlyOnce = true;
        $lineSize = 0;
        $offset   = 0;

        if ($this->state->getInprogress()) {
            $onlyOnce = false;
            $offset   = $this->state->getOffset();
            $lineSize = $this->state->getLineSize();
        }

        $colStmt = $this->getColumnStmt($tableName);
        $limit = 10000;

        while (true) {
            $stmt = "SELECT $colStmt FROM `$tableName`";
            $stmt1 = $stmt . " LIMIT " . $limit . " OFFSET " . $offset;
            $results = $this->dbHandler->query($stmt1);

            if (!$results) {
                $this->inprogress = false;
                if (!$onlyOnce) {
                    $this->compressManager->write(";/*SGEnd*/" . PHP_EOL);
                }

                $this->endListValues($tableName);
                return;
            }

            foreach ($results as $row) {
                $vals = $this->escape($tableName, $row);
                if ($onlyOnce) {
                    $lineSize += $this->compressManager->write(
                        "INSERT INTO `$tableName` VALUES (" . implode(",", $vals) . ")"
                    );
                    $onlyOnce = false;
                } else {
                    $lineSize += $this->compressManager->write(",(" . implode(",", $vals) . ")");
                }

                if ($lineSize > self::MAXLINESIZE) {
                    $onlyOnce = true;
                    $this->compressManager->write(";/*SGEnd*/" . PHP_EOL);
                    $lineSize = 0;
                }
            }
            $offset = $offset + $limit;

            unset($results);
        }

        $this->inprogress = false;

        if (!$onlyOnce) {
            $this->compressManager->write(";/*SGEnd*/" . PHP_EOL);
        }

        $this->endListValues($tableName);
        return;
    }

    /**
     * Table rows extractor, append information prior to dump
     *
     * @param string $tableName Name of table to export
     *
     * @return null
     * @throws Exception
     */
    function prepareListValues(string $tableName)
    {
        if (!$this->dumpSettings['skip-comments']) {
            $this->compressManager->write(
                "--" . PHP_EOL .
                "-- Dumping data for table `$tableName`" . PHP_EOL .
                "--" . PHP_EOL . PHP_EOL
            );
        }

        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->query($this->typeAdapter->setup_transaction());
            $this->dbHandler->query($this->typeAdapter->start_transaction());
        }

        if ($this->dumpSettings['lock-tables']) {
            $this->typeAdapter->lock_table($tableName);
        }

        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write(
                $this->typeAdapter->start_add_lock_table($tableName)
            );
        }

        if ($this->dumpSettings['disable-keys']) {
            $this->compressManager->write(
                $this->typeAdapter->start_add_disable_keys($tableName)
            );
        }

        // Disable autocommit for faster reload
        if ($this->dumpSettings['no-autocommit']) {
            $this->compressManager->write(
                $this->typeAdapter->start_disable_autocommit()
            );
        }

        return;
    }

    /**
     * Table rows extractor, close locks and commits after dump
     *
     * @param string $tableName Name of table to export
     *
     * @return null
     * @throws Exception
     */
    function endListValues(string $tableName)
    {
        if ($this->dumpSettings['disable-keys']) {
            $this->compressManager->write(
                $this->typeAdapter->end_add_disable_keys($tableName)
            );
        }

        if ($this->dumpSettings['add-locks']) {
            $this->compressManager->write(
                $this->typeAdapter->end_add_lock_table($tableName)
            );
        }

        if ($this->dumpSettings['single-transaction']) {
            $this->dbHandler->query($this->typeAdapter->commit_transaction());
        }

        if ($this->dumpSettings['lock-tables']) {
            $this->typeAdapter->unlock_table($tableName);
        }

        // Commit to enable autocommit
        if ($this->dumpSettings['no-autocommit']) {
            $this->compressManager->write(
                $this->typeAdapter->end_disable_autocommit()
            );
        }

        $this->compressManager->write(PHP_EOL);

        return;
    }

    /**
     * Build SQL List of all columns on current table
     *
     * @param string $tableName Name of table to get columns
     *
     * @return string SQL sentence with columns
     */
    function getColumnStmt($tableName)
    {
        $colStmt = array();
        foreach ($this->tableColumnTypes[$tableName] as $colName => $colType) {
            if ($colType['type'] == 'bit' && $this->dumpSettings['hex-blob']) {
                $colStmt[] = "LPAD(HEX(`{$colName}`),2,'0') AS `{$colName}`";
            } else if ($colType['is_blob'] && $this->dumpSettings['hex-blob']) {
                $colStmt[] = "HEX(`{$colName}`) AS `{$colName}`";
            } else {
                $colStmt[] = "`{$colName}`";
            }
        }
        $colStmt = implode(",", $colStmt);

        return $colStmt;
    }
}

/**
 * Enum with all available compression methods
 *
 */
abstract class CompressMethod
{
    public static $enums
        = array(
            "None",
            "Gzip",
            "Bzip2"
        );

    /**
     * @param string $c
     *
     * @return boolean
     */
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}

abstract class CompressManagerFactory
{
    /**
     * @param string $c
     *
     * @return CompressBzip2|CompressGzip|CompressNone
     */
    public static function create($c)
    {
        $c = ucfirst(strtolower($c));
        if (!CompressMethod::isValid($c)) {
            throw new Exception("Compression method ($c) is not defined yet");
        }

        $method = __NAMESPACE__ . "\\" . "Compress" . $c;

        return new $method;
    }

    public static function prepareToWrite($str)
    {
        return $str;
    }
}

class CompressBzip2 extends CompressManagerFactory
{
    private $fileHandler = null;

    public function __construct()
    {
        if (!function_exists("bzopen")) {
            throw new Exception("Compression is enabled, but bzip2 lib is not installed or configured properly");
        }
    }

    public function open($filename)
    {
        $this->fileHandler = bzopen($filename, 'a');
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        $str = self::prepareToWrite($str);

        if (false === ($bytesWritten = bzwrite($this->fileHandler, $str))) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return bzclose($this->fileHandler);
    }
}

class CompressGzip extends CompressManagerFactory
{
    private $fileHandler = null;

    public function __construct()
    {
        if (!function_exists("gzopen")) {
            throw new Exception("Compression is enabled, but gzip lib is not installed or configured properly");
        }
    }

    public function open($filename)
    {
        $this->fileHandler = gzopen($filename, "ab");
        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        $str = self::prepareToWrite($str);
        if (false === ($bytesWritten = gzwrite($this->fileHandler, $str))) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return gzclose($this->fileHandler);
    }
}

class CompressNone extends CompressManagerFactory
{
    private $fileHandler = null;

    public function open($filename)
    {
        $this->fileHandler = fopen($filename, "ab");

        if (false === $this->fileHandler) {
            throw new Exception("Output file is not writable");
        }

        return true;
    }

    public function write($str)
    {
        $str = self::prepareToWrite($str);
        if (false === ($bytesWritten = fwrite($this->fileHandler, $str))) {
            throw new Exception("Writting to file failed! Probably, there is no more free space left?");
        }
        return $bytesWritten;
    }

    public function close()
    {
        return fclose($this->fileHandler);
    }
}

/**
 * Enum with all available TypeAdapter implementations
 *
 */
abstract class TypeAdapter
{
    public static $enums
        = array(
            "Sqlite",
            "Mysql"
        );

    /**
     * @param string $c
     *
     * @return boolean
     */
    public static function isValid($c)
    {
        return in_array($c, self::$enums);
    }
}

/**
 * TypeAdapter Factory
 *
 */
abstract class TypeAdapterFactory
{
    /**
     * @param string $c Type of database factory to create (Mysql, Sqlite,...)
     * @param PDO    $dbHandler
     */
    public static function create($c, $dbHandler = null)
    {
        $c = ucfirst(strtolower($c));
        if (!TypeAdapter::isValid($c)) {
            throw new Exception("Database type support for ($c) not yet available");
        }
        $method = __NAMESPACE__ . "\\" . "TypeAdapter" . $c;
        return new $method($dbHandler);
    }

    /**
     * function databases Add sql to create and use database
     *
     * @todo make it do something with sqlite
     */
    public function databases()
    {
        return "";
    }

    public function show_create_table($tableName)
    {
        return "SELECT tbl_name as 'Table', sql as 'Create Table' " .
               "FROM sqlite_master " .
               "WHERE type='table' AND tbl_name='$tableName'";
    }

    /**
     * function create_table Get table creation code from database
     *
     * @todo make it do something with sqlite
     */
    public function create_table($row, $dumpSettings)
    {
        return "";
    }

    public function show_create_view($viewName)
    {
        return "SELECT tbl_name as 'View', sql as 'Create View' " .
               "FROM sqlite_master " .
               "WHERE type='view' AND tbl_name='$viewName'";
    }

    /**
     * function create_view Get view creation code from database
     *
     * @todo make it do something with sqlite
     */
    public function create_view($row)
    {
        return "";
    }

    /**
     * function show_create_trigger Get trigger creation code from database
     *
     * @todo make it do something with sqlite
     */
    public function show_create_trigger($triggerName)
    {
        return "";
    }

    /**
     * function create_trigger Modify trigger code, add delimiters, etc
     *
     * @todo make it do something with sqlite
     */
    public function create_trigger($triggerName)
    {
        return "";
    }

    public function show_tables()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='table'";
    }

    public function show_views()
    {
        return "SELECT tbl_name FROM sqlite_master WHERE type='view'";
    }

    public function show_triggers()
    {
        return "SELECT Trigger FROM sqlite_master WHERE type='trigger'";
    }

    public function show_columns()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "pragma table_info({$args[0]})";
    }

    public function setup_transaction()
    {
        return "";
    }

    public function start_transaction()
    {
        return "BEGIN EXCLUSIVE";
    }

    public function commit_transaction()
    {
        return "COMMIT";
    }

    public function lock_table()
    {
        return "";
    }

    public function unlock_table()
    {
        return "";
    }

    public function start_add_lock_table()
    {
        return PHP_EOL;
    }

    public function end_add_lock_table()
    {
        return PHP_EOL;
    }

    public function start_add_disable_keys()
    {
        return PHP_EOL;
    }

    public function end_add_disable_keys()
    {
        return PHP_EOL;
    }

    public function start_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }

    public function end_disable_foreign_keys_check()
    {
        return PHP_EOL;
    }

    public function add_drop_database()
    {
        return PHP_EOL;
    }

    public function add_drop_trigger()
    {
        return PHP_EOL;
    }

    public function drop_table()
    {
        return PHP_EOL;
    }

    public function drop_view()
    {
        return PHP_EOL;
    }

    /**
     * Decode column metadata and fill info structure.
     * type, is_numeric and is_blob will always be available.
     *
     * @param array $colType Array returned from "SHOW COLUMNS FROM tableName"
     *
     * @return array
     */
    public function parseColumnType($colType)
    {
        return array();
    }

    public function backup_parameters()
    {
        return PHP_EOL;
    }

    public function restore_parameters()
    {
        return PHP_EOL;
    }
}

class TypeAdapterPgsql extends TypeAdapterFactory
{
}

class TypeAdapterDblib extends TypeAdapterFactory
{
}

class TypeAdapterSqlite extends TypeAdapterFactory
{
}

class TypeAdapterMysql extends TypeAdapterFactory
{
    private $dbHandler = null;

    // Numerical Mysql types
    public $mysqlTypes
        = array(
            'numerical' => array(
                'bit',
                'tinyint',
                'smallint',
                'mediumint',
                'int',
                'integer',
                'bigint',
                'real',
                'double',
                'float',
                'decimal',
                'numeric'
            ),
            'blob'      => array(
                'tinyblob',
                'blob',
                'mediumblob',
                'longblob',
                'binary',
                'varbinary',
                'bit'
            )
        );

    public function __construct($dbHandler)
    {
        $this->dbHandler = $dbHandler;
    }

    /**
     * @throws Exception
     */
    public function databases(): string
    {
        if (func_num_args() != 1) {
            throw new Exception("Unexpected parameter passed to " . __METHOD__);
        }

        $args         = func_get_args();
        $databaseName = $args[0];

        $resultSet    = $this->dbHandler->query("SHOW VARIABLES LIKE 'character_set_database';");
        $characterSet = $resultSet[0]['Value'];

        $resultSet   = $this->dbHandler->query("SHOW VARIABLES LIKE 'collation_database';");
        $collationDb = $resultSet[0]['Value'];

        return "CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{$databaseName}`" .
            " /*!40100 DEFAULT CHARACTER SET {$characterSet} " .
            " COLLATE {$collationDb} */;/*SGEnd*/" . PHP_EOL . PHP_EOL .
            "USE `{$databaseName}`;/*SGEnd*/" . PHP_EOL . PHP_EOL;
    }

    public function show_create_table($tableName)
    {
        return "SHOW CREATE TABLE `$tableName`";
    }

    public function show_create_view($viewName)
    {
        return "SHOW CREATE VIEW `$viewName`";
    }

    public function show_create_trigger($triggerName)
    {
        return "SHOW CREATE TRIGGER `$triggerName`";
    }

    public function create_table($row, $dumpSettings): string
    {
        if (!isset($row['Create Table'])) {
            throw new Exception("Error getting table code, unknown output");
        }

        return "/*!40101 SET @saved_cs_client     = @@character_set_client */;/*SGEnd*/" . PHP_EOL .
               "/*!40101 SET character_set_client = " . $dumpSettings['default-character-set'] . " */;/*SGEnd*/" . PHP_EOL .
               $row['Create Table'] . ";/*SGEnd*/" . PHP_EOL .
               "/*!40101 SET character_set_client = @saved_cs_client */;/*SGEnd*/" . PHP_EOL .
               PHP_EOL;
    }

    /**
     * @throws Exception
     */
    public function create_view($row): string
    {
        $ret = "";
        if (!isset($row['Create View'])) {
            throw new Exception("Error getting view structure, unknown output");
        }

        $triggerStmt          = $row['Create View'];
        $triggerStmtReplaced1 = str_replace(
            "CREATE ALGORITHM",
            "/*!50001 CREATE ALGORITHM",
            $triggerStmt
        );
        $triggerStmtReplaced2 = str_replace(
            " DEFINER=",
            " */" . PHP_EOL . "/*!50013 DEFINER=",
            $triggerStmtReplaced1
        );
        $triggerStmtReplaced3 = str_replace(
            " VIEW ",
            " */" . PHP_EOL . "/*!50001 VIEW ",
            $triggerStmtReplaced2
        );
        if (false === $triggerStmtReplaced1 ||
            false === $triggerStmtReplaced2 ||
            false === $triggerStmtReplaced3) {
            $triggerStmtReplaced = $triggerStmt;
        } else {
            $triggerStmtReplaced = $triggerStmtReplaced3 . " */;/*SGEnd*/";
        }

        $ret .= $triggerStmtReplaced . PHP_EOL . PHP_EOL;
        return $ret;
    }

    public function create_trigger($row)
    {
        $ret = "";
        if (!isset($row['SQL Original Statement'])) {
            throw new Exception("Error getting trigger code, unknown output");
        }

        $triggerStmt         = $row['SQL Original Statement'];
        $triggerStmtReplaced = str_replace(
            "CREATE DEFINER",
            "/*!50003 CREATE*/ /*!50017 DEFINER",
            $triggerStmt
        );
        $triggerStmtReplaced = str_replace(
            " TRIGGER",
            "*/ /*!50003 TRIGGER",
            $triggerStmtReplaced
        );
        if (false === $triggerStmtReplaced) {
            $triggerStmtReplaced = $triggerStmt;
        }

        $ret .= "DELIMITER ;;/*SGEnd*/" . PHP_EOL .
                $triggerStmtReplaced . "*/;;/*SGEnd*/" . PHP_EOL .
                "DELIMITER ;/*SGEnd*/" . PHP_EOL . PHP_EOL;
        return $ret;
    }

    public function show_tables()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SELECT TABLE_NAME AS tbl_name " .
               "FROM INFORMATION_SCHEMA.TABLES " .
               "WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='{$args[0]}'";
    }

    public function show_views()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SELECT TABLE_NAME AS tbl_name " .
               "FROM INFORMATION_SCHEMA.TABLES " .
               "WHERE TABLE_TYPE='VIEW' AND TABLE_SCHEMA='{$args[0]}'";
    }

    public function show_triggers()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SHOW TRIGGERS FROM `{$args[0]}`;";
    }


    public function show_columns()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "SHOW COLUMNS FROM `{$args[0]}`;";
    }

    public function setup_transaction()
    {
        return "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ";
    }

    public function start_transaction()
    {
        return "START TRANSACTION";
    }

    public function commit_transaction()
    {
        return "COMMIT";
    }

    public function lock_table()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();
        //$tableName = $args[0];
        //return "LOCK TABLES `$tableName` READ LOCAL";
        return $this->dbHandler->query("LOCK TABLES `{$args[0]}` READ LOCAL");

    }

    public function unlock_table()
    {
        return $this->dbHandler->query("UNLOCK TABLES");
    }

    public function start_add_lock_table()
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "LOCK TABLES `{$args[0]}` WRITE;/*SGEnd*/" . PHP_EOL;
    }

    public function end_add_lock_table()
    {
        return "UNLOCK TABLES;/*SGEnd*/" . PHP_EOL;
    }

    public function start_add_disable_keys()
    {
        if (func_num_args() != 1) {
            return "";
        }
        $args = func_get_args();
        return "/*!40000 ALTER TABLE `{$args[0]}` DISABLE KEYS */;/*SGEnd*/" .
               PHP_EOL;
    }

    public function end_add_disable_keys()
    {
        if (func_num_args() != 1) {
            return "";
        }
        $args = func_get_args();
        return "/*!40000 ALTER TABLE `{$args[0]}` ENABLE KEYS */;/*SGEnd*/" .
               PHP_EOL;
    }

    public function start_disable_autocommit()
    {
        return "SET autocommit=0;/*SGEnd*/" . PHP_EOL;
    }

    public function end_disable_autocommit()
    {
        return "COMMIT;/*SGEnd*/" . PHP_EOL;
    }

    public function add_drop_database(): string
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "/*!40000 DROP DATABASE IF EXISTS `{$args[0]}`*/;/*SGEnd*/" .
               PHP_EOL . PHP_EOL;
    }

    public function add_drop_trigger(): string
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "DROP TRIGGER IF EXISTS `{$args[0]}`;/*SGEnd*/" . PHP_EOL;
    }

    public function drop_table(): string
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "DROP TABLE IF EXISTS `{$args[0]}`;/*SGEnd*/" . PHP_EOL;
    }

    public function drop_view(): string
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "DROP TABLE IF EXISTS `{$args[0]}`;/*SGEnd*/" . PHP_EOL .
               "/*!50001 DROP VIEW IF EXISTS `{$args[0]}`*/;/*SGEnd*/" . PHP_EOL;
    }

    public function getDatabaseHeader(): string
    {
        if (func_num_args() != 1) {
            return "";
        }

        $args = func_get_args();

        return "--" . PHP_EOL .
               "-- Current Database: `{$args[0]}`" . PHP_EOL .
               "--" . PHP_EOL . PHP_EOL;
    }

    /**
     * Decode column metadata and fill info structure.
     * type, is_numeric and is_blob will always be available.
     *
     * @param array $colType Array returned from "SHOW COLUMNS FROM tableName"
     *
     * @return array
     */
    public function parseColumnType($colType): array
    {
        $colInfo  = array();
        $colParts = explode(" ", $colType['Type']);

        if ($fparen = strpos($colParts[0], "(")) {
            $colInfo['type']       = substr($colParts[0], 0, $fparen);
            $colInfo['length']     = str_replace(")", "", substr($colParts[0], $fparen + 1));
            $colInfo['attributes'] = $colParts[1] ?? null;
        } else {
            $colInfo['type'] = $colParts[0];
        }
        $colInfo['is_numeric'] = in_array($colInfo['type'], $this->mysqlTypes['numerical']);
        $colInfo['is_blob']    = in_array($colInfo['type'], $this->mysqlTypes['blob']);

        return $colInfo;
    }

    /**
     * @throws Exception
     */
    public function backup_parameters(): string
    {
        if (func_num_args() != 1) {
            throw new Exception("Unexpected parameter passed to " . __METHOD__);
        }

        $args         = func_get_args();
        $dumpSettings = $args[0];
        $ret          = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;/*SGEnd*/" . PHP_EOL .
                        "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;/*SGEnd*/" . PHP_EOL .
                        "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;/*SGEnd*/" . PHP_EOL .
                        "/*!40101 SET NAMES " . $dumpSettings['default-character-set'] . " */;/*SGEnd*/" . PHP_EOL;

        if (false === $dumpSettings['skip-tz-utz']) {
            $ret .= "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;/*SGEnd*/" . PHP_EOL .
                    "/*!40103 SET TIME_ZONE='+00:00' */;/*SGEnd*/" . PHP_EOL;
        }

        $ret .= "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;/*SGEnd*/" . PHP_EOL .
                "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;/*SGEnd*/" . PHP_EOL .
                "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;/*SGEnd*/" . PHP_EOL .
                "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;/*SGEnd*/" . PHP_EOL . PHP_EOL;

        return $ret;
    }

    /**
     * @throws Exception
     */
    public function restore_parameters(): string
    {
        if (func_num_args() != 1) {
            throw new Exception("Unexpected parameter passed to " . __METHOD__);
        }

        $args = func_get_args();
        $dumpSettings = $args[0];
        $ret = "";

        if (false === $dumpSettings['skip-tz-utz']) {
            $ret .= "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;/*SGEnd*/" . PHP_EOL;
        }

        $ret .= "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;/*SGEnd*/" . PHP_EOL .
                "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;/*SGEnd*/" . PHP_EOL .
                "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;/*SGEnd*/" . PHP_EOL .
                "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;/*SGEnd*/" . PHP_EOL .
                "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;/*SGEnd*/" . PHP_EOL .
                "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;/*SGEnd*/" . PHP_EOL .
                "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;/*SGEnd*/" . PHP_EOL . PHP_EOL;

        return $ret;
    }
}
