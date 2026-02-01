<?php

/**
 * STANDALONE PHP DB GENERATOR - ALL IN ONE
 */

// 8.) DB Config logic
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

class Database
{
	private $link;
	private static $instance;

	public function __construct($host, $user, $password, $database)
	{
		$this->link = new mysqli($host, $user, $password, $database);
		if ($this->link->connect_errno) {
			die("Connect failed: " . $this->link->connect_error . "\n");
		}
		$this->link->set_charset("utf8mb4");
		self::$instance = $this;
	}

	public static function getInstance()
	{
		if (self::$instance === null) {
			// In the generated product, this might need to be initialized differently
			// but for the generator we initialize it in the constructor.
		}
		return self::$instance;
	}

	public function getLink()
	{
		return $this->link;
	}

	public function query($_q)
	{
		$res = $this->link->query($_q);
		if (!$res) {
			echo "Query Error: " . $this->link->error . "\nSQL: $_q\n";
		}
		return $res;
	}

	public function get($_q, $single = false)
	{
		$result = $this->link->query($_q);
		if ($result) {
			$r = [];
			if ($single) return $result->fetch_assoc();
			while ($row = $result->fetch_assoc()) $r[] = $row;
			$result->free();
			return $r;
		}
		return false;
	}

	public function filter($_t)
	{
		return $this->link->real_escape_string($_t);
	}
}

class Generator
{
	private $db;

	public function __construct(Database $db)
	{
		$this->db = $db;
	}

	public function generate()
	{
		$folders = ['controller', 'schema', 'app'];
		foreach ($folders as $f) {
			if (!file_exists($f)) mkdir($f, 0777, true);
		}

		$tables = $this->db->get("SHOW TABLES");
		foreach ($tables as $tRow) {
			$table = array_values($tRow)[0];
			$className = str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));
			echo "Generating $className...\n";

			$fields = $this->db->get("DESC `$table` ");
			$pk = $fields[0]['Field']; // Assuming first field is PK

			$this->generateApp($className, $table, $fields, $pk);
			$this->generateModel($className, $table, $fields, $pk);
			$this->generateController($className, $table, $fields, $pk);
		}
	}

	private function transposeSqlType($typeFlag)
	{
		$typeFlag = explode('(', $typeFlag)[0];
		if (in_array($typeFlag, ["varchar", "text", "longtext", "mediumtext", "tinytext", "datetime", "enum", "blob"])) return "string";
		if (in_array($typeFlag, ["int", "bigint", "tinyint", "smallint"])) return "int";
		if (in_array($typeFlag, ["decimal", "float", "double"])) return "double";
		return "string";
	}

	private function generateApp($className, $table, $fields, $pk)
	{
		$app = "<?php\n\nclass $className extends {$className}Model\n{\n";
		$app .= "    public function delete()\n    {\n";
		$app .= "        return {$className}Controller::delete(\$this);\n";
		$app .= "    }\n}\n";
		file_put_contents("app/$className.class.php", $app);
	}

	private function generateModel($className, $table, $fields, $pk)
	{
		$model = "<?php\n\nclass {$className}Model\n{\n";
		foreach ($fields as $f) {
			$model .= "    public \${$f['Field']};\n";
		}
		$model .= "\n    public function __construct(\$id = null)\n    {\n";
		$model .= "        if (\$id) {\n            \$this->$pk = \$id;\n            \$this->spawn();\n        }\n    }\n\n";

		// Getter and Setter methods
		foreach ($fields as $f) {
			$field = $f['Field'];
			$methodName = str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));

			$model .= "    /**\n     * @return " . $this->transposeSqlType($f['Type']) . "\n     */\n";
			$model .= "    public function get$methodName() { return \$this->$field; }\n";
			$model .= "    public function set$methodName(\$val) { \$this->$field = \$val; return \$this; }\n";
		}

		// spawn
		$model .= "\n    public function spawn()\n    {\n";
		$model .= "        \$_q = \"SELECT * FROM `$table` WHERE `$pk` = '\" . Database::getInstance()->filter(\$this->$pk) . \"' LIMIT 1\";\n";
		$model .= "        \$t = Database::getInstance()->get(\$_q, true);\n";
		$model .= "        if (\$t) {\n";
		foreach ($fields as $f) {
			$type = $this->transposeSqlType($f['Type']);
			$model .= "            \$this->{$f['Field']} = ($type)\$t['{$f['Field']}'];\n";
		}
		$model .= "            return true;\n        }\n        return false;\n    }\n";

		// insert
		$model .= "\n    public function insert()\n    {\n";
		$model .= "        \$fields = [];\n        \$values = [];\n";
		foreach ($fields as $f) {
			if ($f['Field'] === $pk && strpos($f['Extra'], 'auto_increment') !== false) continue;
			$model .= "        \$fields[] = \"`{$f['Field']}`\";\n";
			$model .= "        \$values[] = \"'\" . Database::getInstance()->filter(\$this->{$f['Field']}) . \"'\";\n";
		}
		$model .= "        \$_q = \"INSERT INTO `$table` (\" . implode(', ', \$fields) . \") VALUES (\" . implode(', ', \$values) . \")\";\n";
		$model .= "        \$res = Database::getInstance()->query(\$_q);\n";
		if (strpos($fields[0]['Extra'], 'auto_increment') !== false) {
			$model .= "        if (\$res) \$this->$pk = Database::getInstance()->getLink()->insert_id;\n";
		}
		$model .= "        return \$res;\n    }\n";

		// update
		$model .= "\n    public function update()\n    {\n";
		$model .= "        \$sets = [];\n";
		foreach ($fields as $f) {
			if ($f['Field'] === $pk) continue;
			$model .= "        \$sets[] = \"`{$f['Field']}` = '\" . Database::getInstance()->filter(\$this->{$f['Field']}) . \"'\";\n";
		}
		$model .= "        \$_q = \"UPDATE `$table` SET \" . implode(', ', \$sets) . \" WHERE `$pk` = '\" . Database::getInstance()->filter(\$this->$pk) . \"' LIMIT 1\";\n";
		$model .= "        return Database::getInstance()->query(\$_q);\n";
		$model .= "    }\n";

		// save
		$model .= "\n    public function save()\n    {\n";
		$model .= "        if (\$this->$pk) {\n            return \$this->update();\n        } else {\n            return \$this->insert();\n        }\n    }\n";

		// set (generic for simple updates of single fields)
		$model .= "\n    public function set(\$key, \$value)\n    {\n";
		$model .= "        \$_q = \"UPDATE `$table` SET `\$key` = '\" . Database::getInstance()->filter(\$value) . \"' WHERE `$pk` = '\" . Database::getInstance()->filter(\$this->$pk) . \"' LIMIT 1\";\n";
		$model .= "        \$res = Database::getInstance()->query(\$_q);\n";
		$model .= "        if (\$res) \$this->\$key = \$value;\n";
		$model .= "        return \$res;\n    }\n";

		$model .= "}\n";
		file_put_contents("schema/{$className}Model.class.php", $model);
	}

	private function generateController($className, $table, $fields, $pk)
	{
		$ctrl = "<?php\n\nclass {$className}Controller\n{\n";

		// create (mass assignment)
		$ctrl .= "    public static function create(\$data)\n    {\n";
		$ctrl .= "        \$obj = new $className();\n";
		$ctrl .= "        foreach (\$data as \$k => \$v) {\n";
		$ctrl .= "            \$setter = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', \$k)));\n";
		$ctrl .= "            if (method_exists(\$obj, \$setter)) \$obj->\$setter(\$v);\n";
		$ctrl .= "        }\n";
		$ctrl .= "        return \$obj;\n    }\n\n";

		$ctrl .= "    public static function getAll()\n    {\n";
		$ctrl .= "        \$_q = \"SELECT `$pk` FROM `$table`\";\n";
		$ctrl .= "        \$res = Database::getInstance()->get(\$_q);\n";
		$ctrl .= "        \$r = [];\n";
		$ctrl .= "        if (\$res) foreach (\$res as \$row) \$r[] = new $className(\$row['$pk']);\n";
		$ctrl .= "        return \$r;\n    }\n\n";

		// getByField
		$ctrl .= "    public static function getByField(\$field, \$value)\n    {\n";
		$ctrl .= "        \$_q = \"SELECT `$pk` FROM `$table` WHERE `\$field` = '\" . Database::getInstance()->filter(\$value) . \"'\";\n";
		$ctrl .= "        \$res = Database::getInstance()->get(\$_q);\n";
		$ctrl .= "        \$r = [];\n";
		$ctrl .= "        if (\$res) foreach (\$res as \$row) \$r[] = new $className(\$row['$pk']);\n";
		$ctrl .= "        return \$r;\n    }\n\n";

		// delete(<class>)
		$ctrl .= "    public static function delete(\$instance)\n    {\n";
		$ctrl .= "        if (\$instance instanceof $className) {\n";
		$pkGetter = "get" . str_replace(' ', '', ucwords(str_replace('_', ' ', $pk)));
		$ctrl .= "            \$id = \$instance->$pkGetter();\n";
		$ctrl .= "            \$_q = \"DELETE FROM `$table` WHERE `$pk` = '\" . Database::getInstance()->filter(\$id) . \"' LIMIT 1\";\n";
		$ctrl .= "            return Database::getInstance()->query(\$_q);\n";
		$ctrl .= "        }\n        return false;\n    }\n";
		$ctrl .= "}\n";
		file_put_contents("controller/{$className}Controller.class.php", $ctrl);
	}
}

$db = new Database($envHost, $envUser, $envPass, $envName);
$generator = new Generator($db);
$generator->generate();

echo "Done.\n";
