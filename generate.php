<?php

$envHost = getenv('DB_HOST') ?: getenv('DBHOST') ?: 'localhost';
$envUser = getenv('DB_USER') ?: getenv('DBUSER') ?: null;
$envPass = getenv('DB_PASS') ?: getenv('DBPASSWORD') ?: null;
$envName = getenv('DB_NAME') ?: getenv('DBNAME') ?: null;

if (isset($argv[1])) $envHost = $argv[1];
if (isset($argv[2])) $envUser = $argv[2];
if (isset($argv[3])) $envPass = $argv[3];
if (isset($argv[4])) $envName = $argv[4];

if (!$envUser || !$envName) {
	echo "Usage: php generate.php [host] [user] [pass] [dbname]\n";
	echo "Or set environment variables: DB_HOST, DB_USER, DB_PASS, DB_NAME\n";
	exit(1);
}

// Include the Database class definition directly here so the generator can use it
// AND we can write it to the output folder.
// We will read it from src/core/Database.php if available, otherwise use the provided code.
$databaseClassContent = '';
$srcDatabasePath = dirname(__DIR__) . '/src/core/Database.php';

if (file_exists($srcDatabasePath)) {
	$databaseClassContent = file_get_contents($srcDatabasePath);
	// Remove the opening <?php tag if present to eval or include safely if needed,
	// but here we just want to copy it.
	// For usage in THIS script, we need to define the class.
	// Since we can't easily redefine a class if it's already included, we'll just require it.
	require_once $srcDatabasePath;
} else {
	// Fallback if file not found (though user said it is there)
	die("Error: src/core/Database.php not found.\n");
}


class TempDatabaseClass
{
	/** @var PDO */
	private $pdo;

	/**
	 * Construct a new Database helper.
	 * Primary inputs are $host and $database. If they are null, will attempt to read environment variables:
	 * DB_HOST, DB_NAME, DB_USER, DB_PASS. For backward compatibility, if these are absent, DB_DSN is also supported.
	 *
	 * @param string|null $host
	 * @param string|null $database
	 * @param string|null $username
	 * @param string|null $password
	 * @param array       $options
	 */
	public function __construct(?string $host = null, ?string $database = null, ?string $username = null, ?string $password = null, array $options = [])
	{
		$dsn = null;

		// 1) Try to load configuration from conf/database.ini (preferred)
		$config = [];
		$projectRoot = dirname(__DIR__, 2);
		$iniPath = $projectRoot . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'database.ini';
		if (is_readable($iniPath)) {
			// Support both with and without sections
			$withSections = @parse_ini_file($iniPath, true, INI_SCANNER_TYPED);
			if (is_array($withSections)) {
				if (isset($withSections['database']) && is_array($withSections['database'])) {
					$config = array_change_key_case($withSections['database'], CASE_LOWER);
				} else {
					$config = array_change_key_case($withSections, CASE_LOWER);
				}
			}
		}

		// 2) Environment variables as secondary source
		$envUser = getenv('DB_USER') ?: getenv('DBUSER') ?: null;
		$envPass = getenv('DB_PASS') ?: getenv('DBPASSWORD') ?: null;
		$envHost = getenv('DB_HOST') ?: getenv('DBHOST') ?: null;
		$envName = getenv('DB_NAME') ?: getenv('DBNAME') ?: null;

		// Fill from INI first, then env, unless explicit args were provided
		$host = $host ?? ($config['host'] ?? $envHost);
		$database = $database ?? ($config['database'] ?? $config['name'] ?? $envName);
		$username = $username ?? ($config['username'] ?? $config['user'] ?? $envUser);
		$password = $password ?? ($config['password'] ?? $config['pass'] ?? $envPass);

		// Backward compatibility: allow DB_DSN when host/db not available
		if (($host === null || $database === null)) {
			$envDsn = getenv('DB_DSN') ?: null;
			if ($envDsn !== null) {
				$dsn = $envDsn;
			}
		}

		if ($dsn === null) {
			if ($host === null || $database === null) {
				throw new \InvalidArgumentException('Database configuration not found. Provide conf/database.ini or set DB_HOST and DB_NAME env vars (or DB_DSN).');
			}
			// Build DSN from parts (driver/charset/port from INI or env, with defaults)
			$driver = ($config['driver'] ?? getenv('DB_DRIVER')) ?: 'mysql';
			$charset = ($config['charset'] ?? getenv('DB_CHARSET')) ?: 'utf8mb4';
			$port = $config['port'] ?? getenv('DB_PORT') ?: null;

			$dsn = "{$driver}:host={$host};dbname={$database}";
			if ($port) {
				$dsn .= ";port={$port}";
			}
			if ($charset) {
				$dsn .= ";charset={$charset}";
			}
		}

		$defaultOptions = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		];
		$options = $options + $defaultOptions;

		try {
			$this->pdo = new PDO($dsn, $username ?? '', $password ?? '', $options);
		} catch (\Throwable $e) {
			// Avoid leaking credentials in error messages
			$safeDsn = preg_replace('/password=[^;]*/i', 'password=***', (string)$dsn);
			throw new DatabaseConnectionException('Failed to connect to database using DSN: ' . $safeDsn, 0, $e);
		}
	}

	/** Get the underlying PDO instance. */
	public function pdo(): PDO
	{
		return $this->pdo;
	}

	// ------------------ Compatibility layer for legacy generated code ------------------

	/**
	 * Legacy-style filter method used by generated code to inline values into raw SQL strings.
	 * - Numeric values are cast to string without quotes.
	 * - Booleans become '1' or '0'.
	 * - Null becomes empty string (so callers can decide to set NULL explicitly if needed).
	 * - Strings are escaped using addslashes() and with backticks removed to avoid breaking identifiers.
	 * NOTE: Prefer prepared statements for new code.
	 * @param mixed $value
	 * @return string
	 */
	public function filter($value): string
	{
		if ($value === null) {
			return '';
		}
		if (is_int($value) || is_float($value)) {
			return (string)$value;
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_object($value) && method_exists($value, '__toString')) {
			$value = (string)$value;
		} else if (is_array($value)) {
			$value = json_encode($value);
		} else {
			$value = (string)$value;
		}
		// Remove backticks (can break identifier quoting) and escape quotes/backslashes
		$value = str_replace('`', '', $value);
		return addslashes($value);
	}

	/**
	 * Legacy convenience wrapper used by generated code.
	 * When $single is true, returns the first row as associative array or empty array if none.
	 * Otherwise returns an array of rows.
	 */
	public function get(string $sql, bool $single = false): array
	{
		if ($single) {
			$row = $this->getRow($sql, []);
			return $row ?? [];
		}
		return $this->getAll($sql, []);
	}

	/**
	 * Legacy name expected by generated code to fetch last auto-increment id (mysqli compatibility).
	 */
	public function lastInsertIdFromMysqli(): int
	{
		try {
			return (int)$this->pdo->lastInsertId();
		} catch (Throwable $e) {
			return 0;
		}
	}

	// ------------------ Core execution helpers ------------------

	/**
	 * Execute a raw SQL query without parameters and return the PDOStatement.
	 * Suitable for SELECT queries; for non-SELECT you might prefer execute().
	 * @param string $sql
	 * @return PDOStatement
	 */
	public function query(string $sql): PDOStatement
	{
		try {
			return $this->pdo->query($sql);
		} catch (\Throwable $e) {
			throw new DatabaseQueryException('Database query() failed', $sql, null, (int)($e->getCode() ?: 0), $e);
		}
	}

	/**
	 * Execute a raw SQL (typically non-SELECT) and return affected rows count.
	 * @param string $sql
	 * @return int affected rows
	 */
	public function execute(string $sql): int
	{
		try {
			return $this->pdo->exec($sql);
		} catch (\Throwable $e) {
			throw new DatabaseQueryException('Database execute() failed', $sql, null, (int)($e->getCode() ?: 0), $e);
		}
	}

	/**
	 * Prepare and execute a statement with parameters (or run raw if no params provided), returning the PDOStatement.
	 * @param string $sql
	 * @param array  $params
	 * @return PDOStatement
	 */
	public function run(string $sql, array $params = []): PDOStatement
	{
		try {
			if (empty($params)) {
				return $this->query($sql);
			}
			$stmt = $this->pdo->prepare($sql);
			$this->bindAndExecute($stmt, $params);
			return $stmt;
		} catch (\Throwable $e) {
			throw new DatabaseQueryException('Database run() failed', $sql, $params, (int)($e->getCode() ?: 0), $e);
		}
	}

	/** Prepare a statement for later execution. */
	public function prepare(string $sql): PDOStatement
	{
		try {
			return $this->pdo->prepare($sql);
		} catch (\Throwable $e) {
			throw new DatabaseQueryException('Database prepare() failed', $sql, null, (int)($e->getCode() ?: 0), $e);
		}
	}

	// ------------------ Fetch helpers ------------------

	/** Fetch all rows from a SELECT. */
	public function getAll(string $sql, array $params = []): array
	{
		$stmt = $this->run($sql, $params);
		return $stmt->fetchAll();
	}

	/** Fetch the first row or null. */
	public function getRow(string $sql, array $params = []): ?array
	{
		$stmt = $this->run($sql, $params);
		$row = $stmt->fetch();
		return $row === false ? null : $row;
	}

	/** Fetch a single scalar value (first column of first row). */
	public function getValue(string $sql, array $params = [])
	{
		$stmt = $this->run($sql, $params);
		$value = $stmt->fetchColumn();
		return $value;
	}

	// ------------------ Table helpers (prepared statements) ------------------

	/**
	 * Select helper using table and where clause.
	 * @param string            $table
	 * @param array|string|null $where   Either associative array (column => value) for equality comparisons or raw string
	 * @param array             $params  Params used if $where is a raw string
	 * @param array             $columns Columns to select
	 * @param string            $orderBy Optional ORDER BY clause (without the word ORDER BY)
	 * @param string|null       $limit   Optional LIMIT clause (just the number or with offset)
	 * @return array
	 */
	public function select(string $table, $where = null, array $params = [], array $columns = ['*'], string $orderBy = '', ?string $limit = null): array
	{
		[$whereSql, $whereParams] = $this->buildWhere($where, $params);
		$cols = implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns));
		$sql = "SELECT {$cols} FROM " . $this->quoteIdentifier($table);
		if ($whereSql) {
			$sql .= " WHERE {$whereSql}";
		}
		if ($orderBy !== '') {
			$sql .= " ORDER BY {$orderBy}";
		}
		if ($limit !== null && $limit !== '') {
			$sql .= " LIMIT {$limit}";
		}
		return $this->getAll($sql, $whereParams);
	}

	/** Count rows in a table, optionally with where. */
	public function countTable(string $table, $where = null, array $params = []): int
	{
		[$whereSql, $whereParams] = $this->buildWhere($where, $params);
		$sql = "SELECT COUNT(*) FROM " . $this->quoteIdentifier($table);
		if ($whereSql) {
			$sql .= " WHERE {$whereSql}";
		}
		return (int)$this->getValue($sql, $whereParams);
	}

	/** Count rows using an arbitrary SQL SELECT (should return one row/one column). */
	public function countSql(string $sql, array $params = []): int
	{
		return (int)$this->getValue($sql, $params);
	}

	/** Delete rows from a table using a where condition. Returns affected rows. */
	public function deleteFrom(string $table, $where = null, array $params = []): int
	{
		[$whereSql, $whereParams] = $this->buildWhere($where, $params);
		$sql = "DELETE FROM " . $this->quoteIdentifier($table);
		if ($whereSql) {
			$sql .= " WHERE {$whereSql}";
		}
		$stmt = $this->run($sql, $whereParams);
		return $stmt->rowCount();
	}

	/** Delete via raw SQL (prepared). Returns affected rows. */
	public function deleteSql(string $sql, array $params = []): int
	{
		$stmt = $this->run($sql, $params);
		return $stmt->rowCount();
	}

	/** Insert helper. Returns last insert id as string (if supported) or affected rows if not supported. */
	public function insert(string $table, array $data)
	{
		if (empty($data)) {
			throw new \InvalidArgumentException('Insert data cannot be empty');
		}
		$columns = array_keys($data);
		$placeholders = array_map(fn($c) => ':' . $this->paramName($c), $columns);
		$sql = 'INSERT INTO ' . $this->quoteIdentifier($table)
			. ' (' . implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns)) . ')'
			. ' VALUES (' . implode(', ', $placeholders) . ')';
		$params = [];
		foreach ($data as $col => $val) {
			$params[$this->paramName($col)] = $val;
		}
		$this->run($sql, $params);
		// Prefer lastInsertId; fall back to rowCount for drivers without lastInsertId
		try {
			return $this->pdo->lastInsertId();
		} catch (\Exception $e) {
			return 1; // best-effort
		}
	}

	/** Update helper. Returns affected rows. */
	public function update(string $table, array $data, $where = null, array $params = []): int
	{
		if (empty($data)) {
			throw new \InvalidArgumentException('Update data cannot be empty');
		}
		[$whereSql, $whereParams] = $this->buildWhere($where, $params);
		$setParts = [];
		$execParams = [];
		foreach ($data as $col => $val) {
			$p = 'set_' . $this->paramName($col);
			$setParts[] = $this->quoteIdentifier($col) . ' = :' . $p;
			$execParams[$p] = $val;
		}
		$sql = 'UPDATE ' . $this->quoteIdentifier($table) . ' SET ' . implode(', ', $setParts);
		if ($whereSql) {
			$sql .= ' WHERE ' . $whereSql;
		}
		$stmt = $this->run($sql, $execParams + $whereParams);
		return $stmt->rowCount();
	}

	// ------------------ Transaction helpers ------------------

	public function beginTransaction(): bool
	{
		try {
			return $this->pdo->beginTransaction();
		} catch (\Throwable $e) {
			throw new DatabaseTransactionException('Failed to begin transaction', 0, $e);
		}
	}

	public function commit(): bool
	{
		try {
			return $this->pdo->commit();
		} catch (\Throwable $e) {
			throw new DatabaseTransactionException('Failed to commit transaction', 0, $e);
		}
	}

	public function rollBack(): bool
	{
		try {
			return $this->pdo->rollBack();
		} catch (\Throwable $e) {
			throw new DatabaseTransactionException('Failed to roll back transaction', 0, $e);
		}
	}

	// ------------------ Internal utilities ------------------

	private function bindAndExecute(PDOStatement $stmt, array $params): void
	{
		foreach ($params as $key => $value) {
			// Allow both named (":name") and name ("name") placeholders
			$param = is_int($key) ? $key + 1 : (str_starts_with((string)$key, ':') ? (string)$key : ':' . (string)$key);
			$type = $this->detectParamType($value);
			$stmt->bindValue($param, $value, $type);
		}
		try {
			$stmt->execute();
		} catch (\Throwable $e) {
			$sql = method_exists($stmt, 'queryString') ? $stmt->queryString : null;
			throw new DatabaseQueryException('Statement execute() failed', $sql, $params, (int)($e->getCode() ?: 0), $e);
		}
	}

	private function detectParamType($value): int
	{
		if (is_int($value)) return PDO::PARAM_INT;
		if (is_bool($value)) return PDO::PARAM_BOOL;
		if ($value === null) return PDO::PARAM_NULL;
		return PDO::PARAM_STR;
	}

	/**
	 * Build a WHERE clause from either an array or raw string.
	 * If array provided, only equality and NULL checks are generated.
	 * Returns [sql, params]
	 * @param array|string|null $where
	 * @param array             $params Used when $where is a raw string
	 * @return array{0:string,1:array}
	 */
	private function buildWhere($where, array $params): array
	{
		if ($where === null || $where === '' || (is_array($where) && empty($where))) {
			return ['', []];
		}
		if (is_string($where)) {
			return [$where, $params];
		}
		// array case
		$parts = [];
		$exec = [];
		foreach ($where as $col => $val) {
			$ph = 'w_' . $this->paramName($col);
			if ($val === null) {
				$parts[] = $this->quoteIdentifier($col) . ' IS NULL';
			} else {
				$parts[] = $this->quoteIdentifier($col) . ' = :' . $ph;
				$exec[$ph] = $val;
			}
		}
		return [implode(' AND ', $parts), $exec];
	}

	/** Quote an identifier (column or table) conservatively. */
	private function quoteIdentifier(string $name): string
	{
		// Basic quoting: wrap with backticks if not already quoted and not a wildcard
		if ($name === '*') return $name;
		// Remove existing quotes/backticks/brackets to prevent injection via name
		$clean = preg_replace('/[`"\[\]]/', '', $name);
		return '`' . $clean . '`';
	}

	private function paramName(string $name): string
	{
		return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
	}
}

class GeneratorPG1
{
	private $db;
	private $databaseClassContent;

	public function __construct(TempDatabaseClass $db, $databaseClassContent)
	{
		$this->db = $db;
		$this->databaseClassContent = $databaseClassContent;
	}

	public function generate()
	{
		$baseDir = __DIR__ . '/generated';
		$this->deleteDirectory($baseDir);

		if (!file_exists($baseDir)) mkdir($baseDir, 0777, true);

		$folders = ['controller', 'schema', 'app', 'core'];
		foreach ($folders as $f) {
			if (!file_exists("$baseDir/$f")) mkdir("$baseDir/$f", 0777, true);
		}

		// Write Database.php to core folder
		file_put_contents("$baseDir/core/Database.php", $this->databaseClassContent);

		$tables = $this->db->getAll("SHOW TABLES");
		foreach ($tables as $tRow) {
			$table = array_values($tRow)[0];
			$className = str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));
			echo "Generating $className...\n";

			$fields = $this->db->getAll("DESC `$table` ");
			$pk = $fields[0]['Field']; // Assuming first field is PK

			$this->generateApp($className, $table, $fields, $pk);
			$this->generateModel($className, $table, $fields, $pk);
			$this->generateController($className, $table, $fields, $pk);
		}

		foreach ($folders as $f) {
			$this->generateIncludeFile("$baseDir/$f");
		}
	}

	private function deleteDirectory($dir)
	{
		if (!file_exists($dir)) return true;
		if (!is_dir($dir)) return unlink($dir);
		foreach (scandir($dir) as $item) {
			if ($item == '.' || $item == '..') continue;
			if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
		}
		return rmdir($dir);
	}

	private function generateIncludeFile($dir)
	{
		$files = scandir($dir);
		$content = "<?php\n\n";
		foreach ($files as $file) {
			if ($file === '.' || $file === '..' || $file === '_include.php') continue;
			if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
				$content .= "require_once __DIR__ . '/$file';\n";
			}
		}
		file_put_contents("$dir/_include.php", $content);
	}

	private function transposeSqlType($typeFlag)
	{
		$typeFlag = explode('(', $typeFlag)[0];
		if (in_array($typeFlag, ["varchar", "text", "longtext", "mediumtext", "tinytext", "datetime", "enum", "blob"])) return "string";
		if (in_array($typeFlag, ["int", "bigint", "tinyint", "smallint"])) return "int";
		if (in_array($typeFlag, ["decimal", "float", "double"])) return "float"; // Changed from "double" to "float"
		return "string";
	}

	private function generateApp($className, $table, $fields, $pk)
	{
		$app = "<?php\n\nclass $className extends {$className}Model\n{\n";
		$app .= "    public function delete(): bool\n    {\n";
		$app .= "        return {$className}Controller::delete(\$this);\n";
		$app .= "    }\n}\n";
		file_put_contents(__DIR__ . "/generated/app/$className.class.php", $app);
	}

	private function generateModel($className, $table, $fields, $pk)
	{
		$model = "<?php\n\nclass {$className}Model\n{\n";
		foreach ($fields as $f) {
			$model .= "    public \${$f['Field']};\n";
		}
		$model .= "\n    public function __construct(\$id = null)\n    {\n";
		$model .= "        if (\$id) {\n";
		// Updated constructor logic
		$model .= "            if (is_int(\$id)) {\n";
		$model .= "                \$this->$pk = \$id;\n";
		$model .= "                \$this->spawn();\n";
		$model .= "            } else {\n";
		$model .= "                // Check if it's a GUID (simple check for string length/format if needed, or just assume string)\n";
		$model .= "                // For now, assume if not int, it might be a GUID string\n";
		$model .= "                \$intId = \$this->resolveGuidToInt(\$id);\n";
		$model .= "                if (\$intId) {\n";
		$model .= "                    \$this->$pk = \$intId;\n";
		$model .= "                    \$this->spawn();\n";
		$model .= "                }\n";
		$model .= "            }\n";
		$model .= "        }\n";
		$model .= "    }\n\n";

		// resolveGuidToInt method
		$model .= "    public function resolveGuidToInt(\$guid): int\n    {\n";
		$model .= "        \$res = Database::getInstance()->getValue(\"SELECT `$pk` FROM `$table` WHERE `guid` = :guid\", ['guid' => \$guid]);\n";
		$model .= "        return \$res ? (int)\$res : 0;\n";
		$model .= "    }\n\n";

		// Getter and Setter methods
		foreach ($fields as $f) {
			$field = $f['Field'];
			$methodName = str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
			$returnType = $this->transposeSqlType($f['Type']);
			// Map 'double' to 'float' for PHP return types if needed, but 'float' is standard in PHP 7+
			if ($returnType === 'double') $returnType = 'float';

			$model .= "    /**\n     * @return " . $this->transposeSqlType($f['Type']) . "\n     */\n";
			$model .= "    public function get$methodName(): ?$returnType\n    {\n";
			$model .= "        return \$this->$field;\n";
			$model .= "    }\n\n";

			$model .= "    public function set$methodName(\$val): self\n    {\n";
			$model .= "        \$this->update('$field', \$val);\n";
			$model .= "        return \$this;\n";
			$model .= "    }\n\n";
		}

		// spawn
		$model .= "    public function spawn(): bool\n    {\n";
		// Build list of columns for SELECT
		$cols = [];
		foreach ($fields as $f) {
			$cols[] = "`{$f['Field']}`";
		}
		$colStr = implode(', ', $cols);

		$model .= "        \$_q = \"SELECT $colStr FROM `$table` WHERE `$pk` = :pk LIMIT 1\";\n";
		$model .= "        \$t = Database::getInstance()->getRow(\$_q, ['pk' => \$this->$pk]);\n\n";
		$model .= "        if (\$t) {\n";
		foreach ($fields as $f) {
			$type = $this->transposeSqlType($f['Type']);
			$model .= "            \$this->{$f['Field']} = ($type)\$t['{$f['Field']}'];\n";
		}
		$model .= "            return true;\n";
		$model .= "        }\n\n";
		$model .= "        return false;\n";
		$model .= "    }\n\n";

		// insert
		$model .= "    public function insert()\n    {\n";
		// Use the new Database::insert helper
		$model .= "        \$data = [];\n";
		foreach ($fields as $f) {
			if ($f['Field'] === $pk && strpos($f['Extra'], 'auto_increment') !== false) continue;
			$model .= "        \$data['{$f['Field']}'] = \$this->{$f['Field']};\n";
		}
		$model .= "\n        \$res = Database::getInstance()->insert('$table', \$data);\n";

		if (strpos($fields[0]['Extra'], 'auto_increment') !== false) {
			$model .= "        if (\$res) {\n";
			$model .= "            \$this->$pk = \$res;\n";
			$model .= "        }\n";
		}
		$model .= "        return \$res;\n";
		$model .= "    }\n\n";

		// syncToDatabase (was update)
		$model .= "    public function syncToDatabase(): int\n    {\n";
		$model .= "        \$data = [];\n";
		foreach ($fields as $f) {
			if ($f['Field'] === $pk) continue;
			$model .= "        \$data['{$f['Field']}'] = \$this->{$f['Field']};\n";
		}
		$model .= "\n        return Database::getInstance()->update('$table', \$data, ['$pk' => \$this->$pk]);\n";
		$model .= "    }\n\n";

		// save
		$model .= "    public function save()\n    {\n";
		$model .= "        if (\$this->$pk) {\n";
		$model .= "            return \$this->syncToDatabase();\n";
		$model .= "        } else {\n";
		$model .= "            return \$this->insert();\n";
		$model .= "        }\n";
		$model .= "    }\n\n";

		// update (was set)
		$model .= "    public function update(\$key, \$value): int\n    {\n";
		$model .= "        \$res = Database::getInstance()->update('$table', [\$key => \$value], ['$pk' => \$this->$pk]);\n\n";
		$model .= "        if (\$res) {\n";
		$model .= "            \$this->\$key = \$value;\n";
		$model .= "        }\n\n";
		$model .= "        return \$res;\n";
		$model .= "    }\n";

		$model .= "}\n";
		file_put_contents(__DIR__ . "/generated/schema/{$className}Model.class.php", $model);
	}

	private function generateController($className, $table, $fields, $pk)
	{
		$ctrl = "<?php\n\nclass {$className}Controller\n{\n";

		// create (mass assignment)
		$ctrl .= "    public static function create(\$data): $className\n    {\n";
		$ctrl .= "        \$obj = new $className();\n";
		$ctrl .= "        foreach (\$data as \$k => \$v) {\n";
		$ctrl .= "            \$setter = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', \$k)));\n";
		$ctrl .= "            if (method_exists(\$obj, \$setter)) {\n";
		$ctrl .= "                \$obj->\$setter(\$v);\n";
		$ctrl .= "            }\n";
		$ctrl .= "        }\n";
		$ctrl .= "        return \$obj;\n";
		$ctrl .= "    }\n\n";

		$ctrl .= "    public static function getAll(): array\n    {\n";
		$ctrl .= "        \$res = Database::getInstance()->getAll(\"SELECT `$pk` FROM `$table`\");\n";
		$ctrl .= "        \$r = [];\n\n";
		$ctrl .= "        if (\$res) {\n";
		$ctrl .= "            foreach (\$res as \$row) {\n";
		$ctrl .= "                \$r[] = new $className(\$row['$pk']);\n";
		$ctrl .= "            }\n";
		$ctrl .= "        }\n\n";
		$ctrl .= "        return \$r;\n";
		$ctrl .= "    }\n\n";

		// getByField
		$ctrl .= "    public static function getByField(\$field, \$value): array\n    {\n";
		// Note: Using raw SQL here because column name is dynamic, but value is parameterized
		$ctrl .= "        \$res = Database::getInstance()->getAll(\"SELECT `$pk` FROM `$table` WHERE `\$field` = :val\", ['val' => \$value]);\n";
		$ctrl .= "        \$r = [];\n\n";
		$ctrl .= "        if (\$res) {\n";
		$ctrl .= "            foreach (\$res as \$row) {\n";
		$ctrl .= "                \$r[] = new $className(\$row['$pk']);\n";
		$ctrl .= "            }\n";
		$ctrl .= "        }\n\n";
		$ctrl .= "        return \$r;\n";
		$ctrl .= "    }\n\n";

		// delete(<class>)
		$ctrl .= "    public static function delete(\$instance): bool\n    {\n";
		$ctrl .= "        if (\$instance instanceof $className) {\n";
		$pkGetter = "get" . str_replace(' ', '', ucwords(str_replace('_', ' ', $pk)));
		$ctrl .= "            \$id = \$instance->$pkGetter();\n";
		$ctrl .= "            return (bool)Database::getInstance()->deleteFrom('$table', ['$pk' => \$id]);\n";
		$ctrl .= "        }\n\n";
		$ctrl .= "        return false;\n";
		$ctrl .= "    }\n";
		$ctrl .= "}\n";
		file_put_contents(__DIR__ . "/generated/controller/{$className}Controller.class.php", $ctrl);
	}
}

$db = new TempDatabaseClass($envHost, $envName, $envUser, $envPass);
$generator = new GeneratorPG1($db, $databaseClassContent);
$generator->generate();

echo "Done.\n";
