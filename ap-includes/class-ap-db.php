<?php

/**
 * AgoraPress database abstraction ($apdb) — PDO, prepared statements only.
 *
 * All value-bearing queries use bound parameters. Table and column identifiers
 * are validated against a safe pattern before interpolation. Supports MySQL /
 * MariaDB (primary), SQLite, and PostgreSQL per SPEC §1.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ap-db-exception.php';

/**
 * Database access layer backed by PDO.
 *
 * Prefer the procedural helper {@see ap_db()} which lazily populates the
 * global `$apdb` from site config. Unit tests may construct instances via
 * {@see AP_DB::fromPdo()} without touching config.
 *
 * Table names: pass unprefixed base names to {@see table()}, {@see insert()},
 * {@see update()}, and {@see delete()}. Public properties such as
 * `$apdb->options` / `$apdb->users` hold the fully prefixed names (WP-style).
 */
class AP_DB
{
    /**
     * Table prefix (e.g. `ap_`). Public for WP-style `$apdb->prefix` access.
     */
    public string $prefix;

    /** Fully prefixed core / forum table names (set whenever prefix changes). */
    public string $schema_migrations = '';

    public string $options = '';

    public string $users = '';

    public string $usermeta = '';

    public string $posts = '';

    public string $postmeta = '';

    public string $terms = '';

    public string $term_taxonomy = '';

    public string $term_relationships = '';

    public string $comments = '';

    public string $commentmeta = '';

    public string $forums = '';

    public string $topics = '';

    public string $forum_posts = '';

    public string $groups = '';

    public string $group_members = '';

    public string $messages = '';

    public string $ranks = '';

    public string $reports = '';

    public string $online = '';

    private PDO $pdo;

    private string $driver;

    private ?string $lastError = null;

    private int $rowsAffected = 0;

    private string $lastInsertId = '0';

    /**
     * @param PDO    $pdo    Live connection (ERRMODE_EXCEPTION recommended).
     * @param string $driver Normalized driver: mysql|sqlite|pgsql.
     * @param string $prefix Table prefix (normalized on construct; default ap_).
     */
    public function __construct(PDO $pdo, string $driver, string $prefix = 'ap_')
    {
        $driver = strtolower(trim($driver));
        if (!in_array($driver, ['mysql', 'sqlite', 'pgsql'], true)) {
            throw new AP_DB_Exception('Unsupported database driver: ' . $driver);
        }

        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->setPrefix($prefix);
    }

    /**
     * Build an AP_DB instance from an existing PDO (tests, advanced use).
     */
    public static function fromPdo(PDO $pdo, string $driver, string $prefix = 'ap_'): self
    {
        return new self($pdo, $driver, $prefix);
    }

    /**
     * Connect using constants from a loaded ap-config.php.
     *
     * Requires AP_DB_DRIVER, AP_DB_NAME, and for non-SQLite also AP_DB_USER,
     * AP_DB_PASSWORD, AP_DB_HOST. Uses AP_TABLE_PREFIX / $table_prefix when set.
     *
     * @throws AP_DB_Exception On missing config or connection failure.
     */
    public static function fromConfig(): self
    {
        if (!defined('AP_DB_DRIVER') || !defined('AP_DB_NAME')) {
            throw new AP_DB_Exception('Database configuration constants are not defined.');
        }

        $driver = strtolower(trim((string) AP_DB_DRIVER));
        if (function_exists('ap_normalized_db_driver')) {
            $normalized = ap_normalized_db_driver();
            if ($normalized !== null) {
                $driver = $normalized;
            }
        }

        $name = (string) AP_DB_NAME;
        $user = defined('AP_DB_USER') ? (string) AP_DB_USER : '';
        $password = defined('AP_DB_PASSWORD') ? (string) AP_DB_PASSWORD : '';
        $host = defined('AP_DB_HOST') ? (string) AP_DB_HOST : 'localhost';
        $charset = defined('AP_DB_CHARSET') ? (string) AP_DB_CHARSET : 'utf8mb4';

        $prefix = self::resolveConfiguredPrefix();

        $pdo = self::createPdo($driver, $name, $user, $password, $host, $charset);

        return new self($pdo, $driver, $prefix);
    }

    /**
     * Resolve the site table prefix from config helpers / constants / globals.
     */
    public static function resolveConfiguredPrefix(): string
    {
        if (function_exists('ap_get_table_prefix')) {
            return self::normalizePrefix(ap_get_table_prefix());
        }

        if (defined('AP_TABLE_PREFIX')) {
            return self::normalizePrefix((string) AP_TABLE_PREFIX);
        }

        if (isset($GLOBALS['table_prefix']) && is_string($GLOBALS['table_prefix'])) {
            return self::normalizePrefix($GLOBALS['table_prefix']);
        }

        $fromEnv = getenv('AP_TABLE_PREFIX');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return self::normalizePrefix($fromEnv);
        }

        return self::normalizePrefix('ap_');
    }

    /**
     * Normalize a table prefix to a safe SQL identifier fragment.
     *
     * Delegates to {@see ap_normalize_table_prefix()} when available.
     */
    public static function normalizePrefix(string $prefix): string
    {
        if (function_exists('ap_normalize_table_prefix')) {
            return ap_normalize_table_prefix($prefix);
        }

        $prefix = trim($prefix);
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $prefix);
        if (!is_string($clean) || $clean === '') {
            return 'ap_';
        }
        if (preg_match('/^[0-9]/', $clean) === 1) {
            $clean = 'ap_' . $clean;
        }

        return $clean;
    }

    /**
     * Unprefixed base names for known schema tables (core + forums).
     *
     * @return list<string>
     */
    public static function knownBaseTables(): array
    {
        if (function_exists('ap_all_base_tables')) {
            return ap_all_base_tables();
        }

        return [
            'schema_migrations',
            'options',
            'users',
            'usermeta',
            'posts',
            'postmeta',
            'terms',
            'term_taxonomy',
            'term_relationships',
            'comments',
            'commentmeta',
            'forums',
            'topics',
            'forum_posts',
            'groups',
            'group_members',
            'messages',
            'ranks',
            'reports',
            'online',
        ];
    }

    /**
     * Set (and normalize) the table prefix; refreshes public table properties.
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = self::normalizePrefix($prefix);
        $this->refreshTableProperties();
    }

    /**
     * Map of known base table name => fully prefixed name for this connection.
     *
     * @return array<string, string>
     */
    public function tables(): array
    {
        $map = [];
        foreach (self::knownBaseTables() as $base) {
            $map[$base] = $this->prefix . $base;
        }

        return $map;
    }

    /**
     * Assign public `$apdb->options`, `$apdb->users`, … from the active prefix.
     */
    private function refreshTableProperties(): void
    {
        foreach (self::knownBaseTables() as $base) {
            // Declared public properties only; ignore unknown base names.
            if (property_exists($this, $base)) {
                $this->{$base} = $this->prefix . $base;
            }
        }
    }

    /**
     * Create a configured PDO connection.
     *
     * @throws AP_DB_Exception
     */
    public static function createPdo(
        string $driver,
        string $name,
        string $user = '',
        string $password = '',
        string $host = 'localhost',
        string $charset = 'utf8mb4'
    ): PDO {
        $driver = strtolower(trim($driver));
        $dsn = self::buildDsn($driver, $name, $host, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $pdo = new PDO($dsn, null, null, $options);
            } else {
                $pdo = new PDO($dsn, $user, $password, $options);
            }
        } catch (PDOException $e) {
            throw new AP_DB_Exception(
                'Could not connect to the database: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        if ($driver === 'mysql' && $charset !== '') {
            // Ensure connection charset even if DSN charset is ignored by older drivers.
            $safeCharset = preg_replace('/[^a-zA-Z0-9_]/', '', $charset) ?? 'utf8mb4';
            $pdo->exec('SET NAMES ' . $safeCharset);
        }

        return $pdo;
    }

    /**
     * Build a PDO DSN for a supported driver.
     *
     * @throws AP_DB_Exception
     */
    public static function buildDsn(
        string $driver,
        string $name,
        string $host = 'localhost',
        string $charset = 'utf8mb4'
    ): string {
        $driver = strtolower(trim($driver));

        return match ($driver) {
            'mysql' => self::buildMysqlDsn($name, $host, $charset),
            'pgsql' => self::buildPgsqlDsn($name, $host),
            'sqlite' => self::buildSqliteDsn($name),
            default => throw new AP_DB_Exception('Unsupported database driver: ' . $driver),
        };
    }

    /**
     * Underlying PDO instance (escape hatch for advanced drivers / migrations).
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Normalized driver name: mysql, sqlite, or pgsql.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Table prefix (same as public $prefix).
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Prefixed table name. `$db->table('options')` → `ap_options`.
     *
     * The unprefixed fragment must be a safe SQL identifier. Prefer this (or
     * the public `$db->options` style properties) over hard-coding prefixes.
     *
     * @throws InvalidArgumentException When the name is not a safe identifier.
     */
    public function table(string $unprefixed): string
    {
        $this->assertIdentifier($unprefixed, 'table name');

        return $this->prefix . $unprefixed;
    }

    /**
     * Whether a string is a safe unprefixed SQL identifier (table/column).
     */
    public static function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * Last PDO error message captured by this layer, if any.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Rows affected by the most recent write (insert/update/delete/query).
     */
    public function rowsAffected(): int
    {
        return $this->rowsAffected;
    }

    /**
     * Last auto-increment / sequence id from insert (string for large ids).
     */
    public function lastInsertId(): string
    {
        return $this->lastInsertId;
    }

    /**
     * Prepare and execute a statement with bound parameters.
     *
     * Use placeholders (`?` or `:name`) for every value. Never interpolate
     * untrusted data into `$sql`.
     *
     * @param string       $sql    SQL with placeholders.
     * @param array<mixed> $params Positional list or named map.
     *
     * @return PDOStatement|false False on failure when exceptions are suppressed.
     */
    public function query(string $sql, array $params = []): PDOStatement|false
    {
        $this->lastError = null;
        $this->rowsAffected = 0;

        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                $this->lastError = 'Failed to prepare statement.';
                return false;
            }

            $ok = $params === [] ? $stmt->execute() : $stmt->execute($params);
            if ($ok === false) {
                $info = $stmt->errorInfo();
                $this->lastError = $info[2] ?? 'Statement execution failed.';
                return false;
            }

            $this->rowsAffected = $stmt->rowCount();

            return $stmt;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * First column of the first row, or null when no row.
     *
     * @param array<mixed> $params
     */
    public function getVar(string $sql, array $params = []): mixed
    {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) {
            return null;
        }

        $value = $stmt->fetchColumn(0);

        return $value === false ? null : $value;
    }

    /**
     * First row, or null when empty.
     *
     * @param array<mixed> $params
     * @param int          $fetchMode PDO::FETCH_OBJ or PDO::FETCH_ASSOC.
     *
     * @return object|array<string, mixed>|null
     */
    public function getRow(string $sql, array $params = [], int $fetchMode = PDO::FETCH_OBJ): object|array|null
    {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) {
            return null;
        }

        $row = $stmt->fetch($fetchMode);

        return $row === false ? null : $row;
    }

    /**
     * First column of every row as a list.
     *
     * @param array<mixed> $params
     *
     * @return list<mixed>
     */
    public function getCol(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) {
            return [];
        }

        /** @var list<mixed> $col */
        $col = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        return $col;
    }

    /**
     * All rows.
     *
     * @param array<mixed> $params
     * @param int          $fetchMode PDO::FETCH_OBJ or PDO::FETCH_ASSOC.
     *
     * @return list<object|array<string, mixed>>
     */
    public function getResults(string $sql, array $params = [], int $fetchMode = PDO::FETCH_OBJ): array
    {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) {
            return [];
        }

        /** @var list<object|array<string, mixed>> $rows */
        $rows = $stmt->fetchAll($fetchMode);

        return $rows;
    }

    /**
     * Insert a row. Table name is unprefixed (prefix applied automatically).
     *
     * @param array<string, mixed> $data Column => value.
     *
     * @return int|false Rows affected (usually 1), or false on failure.
     */
    public function insert(string $table, array $data): int|false
    {
        if ($data === []) {
            $this->lastError = 'Insert data cannot be empty.';
            return false;
        }

        $tableName = $this->table($table);
        $columns = [];
        $placeholders = [];
        $values = [];

        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                $this->lastError = 'Column names must be strings.';
                return false;
            }
            $this->assertIdentifier($column, 'column name');
            $columns[] = $this->quoteIdentifier($column);
            $placeholders[] = '?';
            $values[] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($tableName),
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->query($sql, $values);
        if ($stmt === false) {
            return false;
        }

        try {
            $this->lastInsertId = (string) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->lastInsertId = '0';
        }

        return $this->rowsAffected;
    }

    /**
     * Update rows matching a simple equality WHERE map (AND-combined).
     *
     * @param array<string, mixed> $data  Column => value to set.
     * @param array<string, mixed> $where Column => value conditions.
     *
     * @return int|false Rows affected, or false on failure.
     */
    public function update(string $table, array $data, array $where): int|false
    {
        if ($data === [] || $where === []) {
            $this->lastError = 'Update requires non-empty data and where clauses.';
            return false;
        }

        $tableName = $this->table($table);
        $setParts = [];
        $whereParts = [];
        $values = [];

        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                $this->lastError = 'Column names must be strings.';
                return false;
            }
            $this->assertIdentifier($column, 'column name');
            $setParts[] = $this->quoteIdentifier($column) . ' = ?';
            $values[] = $value;
        }

        foreach ($where as $column => $value) {
            if (!is_string($column)) {
                $this->lastError = 'WHERE column names must be strings.';
                return false;
            }
            $this->assertIdentifier($column, 'column name');
            $whereParts[] = $this->quoteIdentifier($column) . ' = ?';
            $values[] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($tableName),
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );

        $stmt = $this->query($sql, $values);
        if ($stmt === false) {
            return false;
        }

        return $this->rowsAffected;
    }

    /**
     * Delete rows matching a simple equality WHERE map (AND-combined).
     *
     * @param array<string, mixed> $where Column => value conditions.
     *
     * @return int|false Rows affected, or false on failure.
     */
    public function delete(string $table, array $where): int|false
    {
        if ($where === []) {
            $this->lastError = 'Delete requires a non-empty where clause.';
            return false;
        }

        $tableName = $this->table($table);
        $whereParts = [];
        $values = [];

        foreach ($where as $column => $value) {
            if (!is_string($column)) {
                $this->lastError = 'WHERE column names must be strings.';
                return false;
            }
            $this->assertIdentifier($column, 'column name');
            $whereParts[] = $this->quoteIdentifier($column) . ' = ?';
            $values[] = $value;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($tableName),
            implode(' AND ', $whereParts)
        );

        $stmt = $this->query($sql, $values);
        if ($stmt === false) {
            return false;
        }

        return $this->rowsAffected;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Quote an already-validated identifier for the active driver.
     */
    public function quoteIdentifier(string $identifier): string
    {
        // Allow dotted forms only after each segment is validated by callers
        // that need them; plain identifiers (incl. prefixed tables) here.
        if ($this->driver === 'mysql') {
            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        // SQLite and PostgreSQL: double quotes.
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertIdentifier(string $name, string $label): void
    {
        if (!self::isSafeIdentifier($name)) {
            throw new InvalidArgumentException('Invalid SQL ' . $label . ': ' . $name);
        }
    }

    private static function buildMysqlDsn(string $name, string $host, string $charset): string
    {
        $parsed = self::parseHostPort($host);
        $dsn = 'mysql:host=' . $parsed['host'];
        if ($parsed['port'] !== null) {
            $dsn .= ';port=' . $parsed['port'];
        }
        $dsn .= ';dbname=' . $name;
        if ($charset !== '') {
            $safeCharset = preg_replace('/[^a-zA-Z0-9_]/', '', $charset) ?? 'utf8mb4';
            $dsn .= ';charset=' . $safeCharset;
        }

        return $dsn;
    }

    private static function buildPgsqlDsn(string $name, string $host): string
    {
        $parsed = self::parseHostPort($host);
        $dsn = 'pgsql:host=' . $parsed['host'];
        if ($parsed['port'] !== null) {
            $dsn .= ';port=' . $parsed['port'];
        }
        $dsn .= ';dbname=' . $name;

        return $dsn;
    }

    private static function buildSqliteDsn(string $name): string
    {
        // Special SQLite paths: :memory: or empty → memory; otherwise filesystem path.
        if ($name === '' || $name === ':memory:') {
            return 'sqlite::memory:';
        }

        return 'sqlite:' . $name;
    }

    /**
     * @return array{host: string, port: int|null}
     */
    private static function parseHostPort(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            return ['host' => 'localhost', 'port' => null];
        }

        // host:port (not IPv6). IPv6 bracket form left for a later increment.
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $parts = explode(':', $host, 2);
            $port = $parts[1] !== '' && ctype_digit($parts[1]) ? (int) $parts[1] : null;

            return ['host' => $parts[0] !== '' ? $parts[0] : 'localhost', 'port' => $port];
        }

        return ['host' => $host, 'port' => null];
    }
}

/**
 * Lazily connect and return the global $apdb instance.
 *
 * Does not connect during bootstrap — first call opens the PDO connection
 * from site config. Callers that need a test double may assign `$GLOBALS['apdb']`
 * before invoking this helper.
 *
 * @throws AP_DB_Exception When configuration is missing or connection fails.
 */
function ap_db(): AP_DB
{
    global $apdb;

    if ($apdb instanceof AP_DB) {
        return $apdb;
    }

    $apdb = AP_DB::fromConfig();

    return $apdb;
}
