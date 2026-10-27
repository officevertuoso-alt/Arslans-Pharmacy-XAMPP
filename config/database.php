<?php // REPAIRED vFIX10
/**
 * Database Configuration File
 * Pharmacy Management System - PAKISTAN EDITION
 * Updated: v2.1 — Added getOne/getAll/run/prepare/clearCache, slow query threshold 0.5s default
 *                  Full transaction support, singleton pattern preserved.
 * 
 * FORENSIC REPAIRS (vFIX10):
 *   - getConnection() returns raw PDO for direct usage (ISSUE 1)
 *   - Slow query threshold configurable via env or constant (ISSUE 2)
 *   - Connection errors sanitized — no DB credentials exposed (ISSUE 3)
 *   - Added _getPDO() standalone function (ISSUE 4)
 */

// =============================================
// PAKISTAN TIME ZONE
// =============================================
date_default_timezone_set('Asia/Karachi');

// =============================================
// .ENV FILE LOADER
// Agar project root mein .env file hai to wahan se credentials lo,
// warna neeche define kiye gaye default constants use honge.
// =============================================

/**
 * Parse a .env file and populate $_ENV / putenv()
 * Sirf simple KEY=VALUE lines support karta hai (no nested vars, no exports)
 * @param string $filePath  Absolute path to .env file
 * @return void
 */
function loadEnv($filePath) {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return; // .env nahi hai — constants fallback use hoga
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Comment lines skip karo
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // KEY=VALUE split (sirf pehle = par split)
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key   = trim($parts[0]);
        $value = trim($parts[1]);

        // Inline comments strip karo (value ke baad #...)
        if (strpos($value, ' #') !== false) {
            $value = trim(explode(' #', $value, 2)[0]);
        }

        // Quotes strip karo agar hain (single ya double)
        if (
            (strlen($value) >= 2) &&
            (($value[0] === '"'  && $value[-1] === '"') ||
             ($value[0] === "'"  && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // PHP environment mein set karo — duplicate nahi karega agar already set hai
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Root directory se .env load karo (config/ ke ek level upar)
loadEnv(dirname(__DIR__) . '/.env');

// =============================================
// HELPER: .env value ya fallback constant
// =============================================
/**
 * Get value from environment, falling back to a default
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function env($key, $default = null) {
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $_ENV[$key] ?? $default;
}

// =============================================
// DATABASE PARAMETERS
// .env mein defined hone par wahan se aayenge,
// warna yahan ke hardcoded values use honge.
// =============================================
if (!defined('DB_HOST'))    define('DB_HOST',    env('DB_HOST',    'localhost'));
if (!defined('DB_NAME'))    define('DB_NAME',    env('DB_NAME',    'pharmacy_db'));
if (!defined('DB_USER'))    define('DB_USER',    env('DB_USER',    'root'));
if (!defined('DB_PASS'))    define('DB_PASS',    env('DB_PASS',    ''));
if (!defined('DB_CHARSET')) define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// =============================================
// APPLICATION SETTINGS
// =============================================
if (!defined('APP_ENV'))    define('APP_ENV',    env('APP_ENV',    'development'));
if (!defined('SITE_NAME'))    define('SITE_NAME',    'Dr. Arslan\'s Pharmacy — PharmaCare Pro');
if (!defined('APP_VERSION'))  define('APP_VERSION',  'v5.0');
if (!defined('TIMEZONE'))     define('TIMEZONE',     'Asia/Karachi');

// ISSUE 2: Slow query threshold - configurable via env or constant
// Default: 0.5 seconds (500ms) as specified
if (!defined('SLOW_QUERY_THRESHOLD')) {
    $threshold = env('SLOW_QUERY_THRESHOLD', 0.5);
    define('SLOW_QUERY_THRESHOLD', (float) $threshold);
}

// =============================================
// ERROR REPORTING
// NOTE: config.php ka APP_ENV-based reporting yahan bhi mirror kiya —
//       database.php akela include hone ki surat mein kaam aayega.
// =============================================
if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// =============================================
// START SESSION IF NOT STARTED
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// DATABASE CONNECTION CLASS
// =============================================
class Database {
    private static $instance  = null;
    private        $connection = null;
    private        $statement  = null;
    private        $error      = null;

    // Slow query log file path
    private $slowQueryLog;

    /**
     * Private constructor — Singleton pattern
     * @throws RuntimeException  on connection failure (sanitized message)
     */
    private function __construct() {
        $this->slowQueryLog = dirname(__DIR__) . '/logs/slow_queries.log';

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_PERSISTENT         => false,  // ISS-005 FIX: prevents transaction contamination across requests (was: true)
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET
                                          . ", time_zone = '+05:00'",
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->_handleConnectionFailure($e);
        }
    }

    /**
     * ISSUE 3: Handle connection failure with sanitized message (no DB credentials)
     * Development mein details show, production mein generic message
     * @param PDOException $e
     * @throws RuntimeException
     */
    private function _handleConnectionFailure(PDOException $e) {
        // Logs directory ensure karo
        $logDir = dirname(__DIR__) . '/logs/';
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Error log mein hamesha likhna (full details for admin)
        $timestamp = date('Y-m-d H:i:s');
        @error_log("[{$timestamp}] DB Connection Failed: " . $e->getMessage() . PHP_EOL,
                   3, $logDir . 'php_errors.log');

        // ISSUE 3: Sanitized user-facing message - NO credentials exposed
        $sanitizedMessage = 'Database connection failed. Please check configuration or contact administrator.';

        if (APP_ENV === 'production') {
            if (!headers_sent()) {
                header('HTTP/1.1 503 Service Unavailable');
                header('Content-Type: text/html; charset=utf-8');
            }
            $errorPage = dirname(__DIR__) . '/503.php';
            if (file_exists($errorPage)) {
                include($errorPage);
            } else {
                $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                if ($isAjax) {
                    echo json_encode([
                        'success' => false,
                        'message' => $sanitizedMessage
                    ]);
                } else {
                    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
                          <title>Service Unavailable</title>
                          <style>body{font-family:sans-serif;text-align:center;padding:80px;background:#f8f9fa}
                          .box{background:#fff;border-radius:8px;padding:40px;max-width:500px;margin:auto;
                          box-shadow:0 2px 12px rgba(0,0,0,.1)}</style></head><body>
                          <div class="box">
                            <h2>&#9888; Service Temporarily Unavailable</h2>
                            <p>' . htmlspecialchars($sanitizedMessage) . '</p>
                            <small>Error has been logged for review.</small>
                          </div></body></html>';
                }
            }
            exit(1);
        }

        // Development: sanitized message with hint only (not raw PDO exception)
        throw new RuntimeException(
            $sanitizedMessage . ' (Check logs for details)',
            (int) $e->getCode(),
            $e
        );
    }

    /**
     * Get database instance (Singleton)
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Clear session cache keys matching patterns 'dashboard_*' and 'stats_*'
     * @return void
     */
    public static function clearCache() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, 'dashboard_') === 0 || strpos($key, 'stats_') === 0) {
                unset($_SESSION[$key]);
            }
        }
    }

    /**
     * ISSUE 1: Get underlying PDO connection (raw PDO for direct usage)
     * Used by config.php's _generateSequenceNo(), execute_factory_reset.php, etc.
     * @return PDO
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Prepare SQL query — optionally execute with positional params in one call.
     *
     * Usage A (old bind() style):
     *   $db->query("SELECT * FROM t WHERE id = :id");
     *   $db->bind(':id', $id);
     *   $db->execute();
     *
     * Usage B (new positional array style):
     *   $db->query("SELECT * FROM t WHERE id = ?", [$id]);
     *   $row = $db->single();   // execute() already called inside query()
     *
     * @param string $sql
     * @param array  $params   Optional positional parameters for ? placeholders
     * @return void
     */
    public function query($sql, array $params = []) {
        $this->statement = $this->connection->prepare($sql);
        // If params supplied here, bind them positionally and execute immediately
        if (!empty($params)) {
            $this->_executeWithParams($params);
        }
    }

    /**
     * Prepare a statement without executing (returns PDOStatement for manual execution)
     * @param string $sql
     * @return \PDOStatement|false
     */
    public function prepare($sql) {
        try {
            $this->statement = $this->connection->prepare($sql);
            return $this->statement;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            if (APP_ENV === 'development') {
                error_log('[DB Prepare Error] ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Internal: execute statement with positional params (? placeholders)
     * Handles slow-query logging the same way execute() does.
     * @param array $params
     * @return bool
     */
    private function _executeWithParams(array $params): bool {
        try {
            if (APP_ENV === 'development') {
                $start   = microtime(true);
                $result  = $this->statement->execute($params);
                $elapsed = microtime(true) - $start;
                if ($elapsed >= SLOW_QUERY_THRESHOLD) {
                    $this->_logSlowQuery($this->statement->queryString, $elapsed);
                }
                return $result;
            }
            return $this->statement->execute($params);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            if (APP_ENV === 'development') {
                error_log('[DB Execute Error] ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Bind parameters to prepared statement
     * @param string $param
     * @param mixed  $value
     * @param int|null $type
     * @return void
     */
    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->statement->bindValue($param, $value, $type);
    }

    /**
     * Execute prepared statement with optional slow query logging
     * @return bool
     */
    public function execute() {
        try {
            if (APP_ENV === 'development') {
                $start  = microtime(true);
                $result = $this->statement->execute();
                $elapsed = microtime(true) - $start;

                if ($elapsed >= SLOW_QUERY_THRESHOLD) {
                    $this->_logSlowQuery($this->statement->queryString, $elapsed);
                }
                return $result;
            }

            return $this->statement->execute();

        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            if (APP_ENV === 'development') {
                error_log('[DB Execute Error] ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Get result set as array
     * @return array
     */
    public function resultSet() {
        if ($this->statement->columnCount() === 0 && $this->statement->rowCount() === 0) {
            $this->execute();
        }
        return $this->statement->fetchAll();
    }

    /**
     * Get single record
     * @return array|false
     */
    public function single() {
        if ($this->statement->columnCount() === 0 && $this->statement->rowCount() === 0) {
            $this->execute();
        }
        return $this->statement->fetch();
    }

    /**
     * Get row count
     * @return int
     */
    public function rowCount() {
        return $this->statement->rowCount();
    }

    /**
     * Get last insert ID
     * @return string
     */
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    /**
     * Shortcut alias for lastInsertId()
     * Credit module aur kuch jagah yeh naam use karta hai
     * @return string
     */
    public function lastId() {
        return $this->connection->lastInsertId();
    }

    /**
     * Execute a raw query without prepared statement binding
     * Use ONLY for schema-inspection queries like SHOW COLUMNS, SHOW TABLES, DESCRIBE
     * KABHI bhi user input ke saath yeh use mat karo
     * @param string $sql
     * @return array
     */
    public function queryRaw($sql) {
        try {
            $stmt = $this->connection->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            if (APP_ENV === 'development') {
                error_log('[DB queryRaw Error] ' . $e->getMessage() . ' | SQL: ' . $sql);
            }
            return [];
        }
    }

    /**
     * Begin transaction
     * @return bool
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit transaction
     * @return bool
     */
    public function commit() {
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     * @return bool
     */
    public function rollback() {
        return $this->connection->rollBack();
    }

    /**
     * Check if a transaction is currently active
     * @return bool
     */
    public function inTransaction() {
        return $this->connection->inTransaction();
    }

    /**
     * Get last error message
     * @return string|null
     */
    public function getError() {
        return $this->error;
    }

    /**
     * Quote/escape a string for safe use
     * NOTE: Prefer prepared statements — yeh sirf edge cases ke liye hai
     * @param string $string
     * @return string
     */
    public function escape($string) {
        return $this->connection->quote($string);
    }

    /**
     * Detect if params array uses positional ? placeholders (numeric keys)
     * or named :param placeholders (string keys).
     * @param array $params
     * @return bool  true = positional (?), false = named (:key)
     */
    private function _isPositional(array $params): bool {
        if (empty($params)) return false;
        return array_keys($params) === range(0, count($params) - 1);
    }

    /**
     * Get a single row (or null).
     * Supports both named placeholders [':id' => 5]
     * and positional placeholders ['%ar%', '%ar%'] (? style).
     * @param string     $sql
     * @param array|null $params
     * @return array|null
     */
    public function getOne($sql, $params = []) {
        $this->statement = $this->connection->prepare($sql);
        if (!empty($params)) {
            if ($this->_isPositional($params)) {
                $this->_executeWithParams($params);
            } else {
                foreach ($params as $key => $val) {
                    $this->bind($key, $val);
                }
                $this->execute();
            }
        } else {
            $this->execute();
        }
        $row = $this->statement->fetch();
        return ($row === false) ? null : $row;
    }

    /**
     * Get all rows (or empty array).
     * Supports both named placeholders [':status' => 'active']
     * and positional placeholders ['%ar%', '%ar%'] (? style).
     * @param string     $sql
     * @param array|null $params
     * @return array
     */
    public function getAll($sql, $params = []) {
        $this->statement = $this->connection->prepare($sql);
        if (!empty($params)) {
            if ($this->_isPositional($params)) {
                $this->_executeWithParams($params);
            } else {
                foreach ($params as $key => $val) {
                    $this->bind($key, $val);
                }
                $this->execute();
            }
        } else {
            $this->execute();
        }
        return $this->statement->fetchAll();
    }

    /**
     * Execute a DML query (INSERT/UPDATE/DELETE) and return number of affected rows.
     * Example: $affected = $db->run("DELETE FROM temp WHERE id = :id", [':id' => 10])
     * @param string $sql
     * @param array  $params
     * @return int  Number of affected rows, or -1 on error
     */
    public function run($sql, $params = []) {
        try {
            $this->statement = $this->connection->prepare($sql);
            if (!empty($params)) {
                if ($this->_isPositional($params)) {
                    $this->_executeWithParams($params);
                    return $this->statement->rowCount();
                }
                foreach ($params as $key => $val) {
                    $this->bind($key, $val);
                }
            }
            $this->execute();
            return $this->rowCount();
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            if (APP_ENV === 'development') {
                error_log('[DB run Error] ' . $e->getMessage());
            }
            return -1;
        }
    }

    /**
     * ISSUE 2: Log slow query to file with configurable threshold
     * @param string $sql
     * @param float  $elapsed  seconds
     * @return void
     */
    private function _logSlowQuery($sql, $elapsed) {
        $logDir = dirname($this->slowQueryLog);
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $line = sprintf(
            "[%s] [%.4fs] %s%s",
            date('Y-m-d H:i:s'),
            $elapsed,
            preg_replace('/\s+/', ' ', trim($sql)),
            PHP_EOL
        );

        @file_put_contents($this->slowQueryLog, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new RuntimeException('Cannot unserialize a singleton.');
    }
}

// =============================================
// HELPER FUNCTIONS FOR DATABASE OPERATIONS
// =============================================

/**
 * Get database instance (shortcut)
 * @return Database
 */
function db() {
    return Database::getInstance();
}

/**
 * ISSUE 4: _getPDO() - Standalone function for raw PDO access
 * Used by execute_factory_reset.php, repair_after_restore.php, etc.
 * Returns raw PDO connection using same constants
 * @return PDO
 * @throws RuntimeException on connection failure
 */
function _getPDO(): PDO {
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET . ", time_zone = '+05:00'"
        ]);
        return $pdo;
    } catch (PDOException $e) {
        $logDir = dirname(__DIR__) . '/logs/';
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @error_log("[_getPDO] Connection failed: " . $e->getMessage());
        throw new RuntimeException('Database connection failed. Check configuration.');
    }
}

/**
 * Execute a write query (INSERT / UPDATE / DELETE)
 * @param string $sql
 * @param array  $params  [':param' => value]
 * @return bool
 */
function runQuery($sql, $params = []) {
    $db = db();
    $db->query($sql);
    foreach ($params as $param => $value) {
        $db->bind($param, $value);
    }
    return $db->execute();
}

/**
 * Get all rows from a SELECT query
 * @param string $sql
 * @param array  $params  [':param' => value]
 * @return array
 */
// ISS-019 FIX: guard with if(!function_exists()) to prevent redeclaration conflicts
if (!function_exists('getAll')) {
function getAll($sql, $params = []) {
    return db()->getAll($sql, $params);
}
}

/**
 * Get a single row from a SELECT query
 * @param string $sql
 * @param array  $params  [':param' => value]
 * @return array|null
 */
if (!function_exists('getOne')) {
function getOne($sql, $params = []) {
    return db()->getOne($sql, $params);
}
}

/**
 * Execute DML and return affected rows
 * @param string $sql
 * @param array  $params
 * @return int
 */
function run($sql, $params = []) {
    return db()->run($sql, $params);
}

/**
 * _pdoOne helper - Get single row using singleton PDO (for sync_engine.php)
 * ISS-022 FIX: Was calling _getPDO() which created a NEW PDO connection every call.
 * Now uses db()->getConnection() to reuse the singleton connection.
 * @param string $sql
 * @param array $params
 * @return array|null
 */
function _pdoOne(string $sql, array $params = []): ?array {
    $pdo = db()->getConnection(); // ISS-022 FIX: use singleton
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * _pdoAll helper - Get all rows using singleton PDO (for sync_engine.php)
 * ISS-022 FIX: Was calling _getPDO() which creates a NEW PDO connection every call.
 * @param string $sql
 * @param array $params
 * @return array
 */
function _pdoAll(string $sql, array $params = []): array {
    $pdo = db()->getConnection(); // ISS-022 FIX: use singleton
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * _pdoRun helper - Execute statement using singleton PDO (for sync_engine.php)
 * ISS-022 FIX: Was calling _getPDO() which creates a NEW PDO connection every call.
 * @param string $sql
 * @param array $params
 * @return int
 */
function _pdoRun(string $sql, array $params = []): int {
    $pdo = db()->getConnection(); // ISS-022 FIX: use singleton
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Insert a row into a table
 * @param string $table
 * @param array  $data   ['column' => value, ...]
 * @return int|false  Last insert ID or false
 */
function insertData($table, $data) {
    $db           = db();
    $fields       = array_keys($data);
    $placeholders = array_map(fn($f) => ':' . $f, $fields);

    $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ")"
         . " VALUES ("              . implode(', ', $placeholders) . ")";

    $db->query($sql);
    foreach ($data as $field => $value) {
        $db->bind(':' . $field, $value);
    }

    return $db->execute() ? $db->lastInsertId() : false;
}

/**
 * Update rows in a table
 * @param string $table
 * @param array  $data        ['column' => value, ...]
 * @param string $where       e.g. "id = :id"
 * @param array  $whereParams [':id' => 5]
 * @return bool
 */
function updateData($table, $data, $where, $whereParams = []) {
    $db        = db();
    $setClause = [];
    foreach ($data as $field => $value) {
        $setClause[] = "{$field} = :{$field}";
    }

    $sql = "UPDATE {$table} SET " . implode(', ', $setClause) . " WHERE {$where}";
    $db->query($sql);

    foreach ($data as $field => $value) {
        $db->bind(':' . $field, $value);
    }
    foreach ($whereParams as $param => $value) {
        $db->bind($param, $value);
    }

    return $db->execute();
}

/**
 * Delete rows from a table
 * @param string $table
 * @param string $where   e.g. "id = :id"
 * @param array  $params  [':id' => 5]
 * @return bool
 */
function deleteData($table, $where, $params = []) {
    $db  = db();
    $sql = "DELETE FROM {$table} WHERE {$where}";
    $db->query($sql);
    foreach ($params as $param => $value) {
        $db->bind($param, $value);
    }
    return $db->execute();
}

/**
 * Check if a table exists in the current database
 * @param string $tableName
 * @return bool
 */
function tableExists($tableName) {
    $db = db();
    $db->query("SHOW TABLES LIKE :table");
    $db->bind(':table', $tableName);
    $db->execute();
    return $db->rowCount() > 0;
}

/**
 * Get total count from a table
 * @param string $table
 * @param string $where   SQL condition (default '1')
 * @param array  $params  [':param' => value]
 * @return int
 */
function getCount($table, $where = '1', $params = []) {
    $db  = db();
    $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE {$where}";
    $db->query($sql);
    foreach ($params as $param => $value) {
        $db->bind($param, $value);
    }
    $db->execute();
    $result = $db->single();
    return (int) ($result['total'] ?? 0);
}

/**
 * Get paginated results from a table
 * @param string $table
 * @param int    $page
 * @param int    $perPage
 * @param string $where
 * @param string $orderBy
 * @return array  ['data', 'total', 'page', 'perPage', 'totalPages']
 */
function getPaginated($table, $page = 1, $perPage = 20, $where = '1', $orderBy = 'id DESC') {
    $page    = max(1, (int) $page);
    $perPage = max(1, (int) $perPage);
    $offset  = ($page - 1) * $perPage;
    $total   = getCount($table, $where);

    $sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderBy}"
          . " LIMIT {$perPage} OFFSET {$offset}";
    $data = getAll($sql);

    return [
        'data'       => $data,
        'total'      => $total,
        'page'       => $page,
        'perPage'    => $perPage,
        'totalPages' => (int) ceil($total / $perPage),
    ];
}

// =============================================
// TEST CONNECTION (Uncomment to test)
// =============================================
/*
try {
    $test = db();
    if ($test->getConnection()) {
        echo "Database connected successfully!";
    }
} catch (RuntimeException $e) {
    echo "Connection failed: " . $e->getMessage();
}
*/
?>