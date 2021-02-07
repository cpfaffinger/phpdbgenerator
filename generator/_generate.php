<?php
require_once(__DIR__ . "/../lib/core.inc.php");

if (count($argv) != 5)
	die("INVALID ARGUMENTS: host user pwd database");
print_r($argv);


//database
$database = new database_mysqli($argv[1], $argv[2], $argv[3], $argv[4]);


if (!is_dir(__DIR__.'/generated'))
	if (!mkdir(__DIR__.'/generated') && !is_dir(__DIR__.'/generated')) {
		throw new \RuntimeException(sprintf('Directory "%s" was not created', 'generated'));
	}

if (!is_dir(__DIR__.'/generated/dbschema')) {
	if (!mkdir(__DIR__.'/generated/dbschema') && !is_dir(__DIR__.'/generated/dbschema')) {
		throw new \RuntimeException(sprintf('Directory "%s" was not created', 'generated/schema'));
	}
}

if (!is_dir(__DIR__.'/generated/dbmodel')) {
	if (!mkdir(__DIR__.'/generated/dbmodel') && !is_dir(__DIR__.'/generated/dbmodel')) {
		throw new \RuntimeException(sprintf('Directory "%s" was not created', 'generated/model'));
	}
}

if (!is_dir(__DIR__.'/generated/distrib')) {
	if (!mkdir(__DIR__.'/generated/distrib') && !is_dir(__DIR__.'/generated/distrib')) {
		throw new \RuntimeException(sprintf('Directory "%s" was not created', 'generated/distrib'));
	}
}

if (!is_dir(__DIR__.'/generated/controller')) {
	if (!mkdir(__DIR__.'/generated/controller') && !is_dir(__DIR__.'/generated/controller')) {
		throw new \RuntimeException(sprintf('Directory "%s" was not created', 'generated/controller'));
	}
}

putImportFile(__DIR__.'/generated/dbschema');
putImportFile(__DIR__.'/generated/dbmodel');
putImportFile(__DIR__.'/generated/distrib');
putImportFile(__DIR__.'/generated/controller');

$tableNames = [];
$allTables = $database->get("SHOW TABLES;");
foreach ($allTables as $table) {
	$tableNames[] = array_values($table)[0];
	foreach ($tableNames as $tableName) {

		echo "rendering \dbschema\\" . $tableName . PHP_EOL;
		$path = __DIR__."/generated/dbschema/$tableName.class.php";
		if (file_exists($path))
			unlink($path);
		file_put_contents($path, generateDBSchema($database, $tableName));

		echo "rendering \dbmodel\\" . $tableName . PHP_EOL;
		$path = __DIR__."/generated/dbmodel/$tableName.class.php";
		if (file_exists($path))
			unlink($path);
		file_put_contents($path, generateDbModel($database, $tableName));

		echo "rendering \distrib\\" . $tableName . PHP_EOL;
		$path = __DIR__."/generated/distrib/$tableName.class.php";
		if (file_exists($path))
			unlink($path);
		file_put_contents($path, generateDistrib($database, $tableName));

		echo "rendering \controller\\" . $tableName . PHP_EOL;
		$path = __DIR__."/generated/controller/$tableName.class.php";
		if (file_exists($path))
			unlink($path);
		file_put_contents($path, generateController($database, $tableName));

	}
}

function transposeSqlType(string $typeFlag)
{
	//all of there will become string
	if (
		$typeFlag == "varchar"
		|| $typeFlag == "text" || $typeFlag == "longtext" || $typeFlag == "mediumtext" || $typeFlag == "tinytext"
		|| $typeFlag == "datetime"
		|| $typeFlag == "enum"
		|| $typeFlag == "blob" || $typeFlag == "longblob" || $typeFlag == "mediumblob" || $typeFlag == "tinyblob"
	)
		$typeFlag = "string";

	//all of these will become double
	if ($typeFlag == "decimal")
		$typeFlag = "double";

	return $typeFlag;
}

function generateDBSchema($database, $table)
{

	$fields = $database->get("DESC `" . $table . "`");

	$allFieldsColl = [];
	foreach ($fields as $f)
		$allFieldsColl[] = $f['Field'];

	$fieldMapByType = [];
	foreach ($fields as $field) {
		$typeFlag = explode("(", $field["Type"])[0];
		$typeFlag = transposeSqlType($typeFlag);
		$fieldMapByType[$typeFlag][] = $field["Field"];
	}


	$s = [];

	$s[] = '<?php';
	$s[] = '';
	$s[] = 'namespace dbschema;';
	$s[] = '';
	$s[] = 'class ' . strtolower($table);
	$s[] = '{';
	$s[] = '';

	foreach ($allFieldsColl as $field)
		$s[] = '    protected $' . $field . ';';

	$s[] = '';

	foreach ($allFieldsColl as $field) {
		$s[] = "    public function get" . ucwords($field) . '() {';
		$s[] = '        return $this->' . $field . ';';
		$s[] = '    }';
	}

	$s[] = '';
	$s[] = '}';
	// ...
	return implode(PHP_EOL, $s);
}

function generateDbModel($database, $table)
{
	$fields = $database->get("DESC `" . $table . "`");
	$firstFieldName = $fields[0]['Field'];

	$hasGuidField = false;
	foreach ($fields as $f)
		if (strtolower($f['Field']) == "guid")
			$hasGuidField = true;

	$allFieldsColl = [];
	foreach ($fields as $f)
		$allFieldsColl[] = $f['Field'];

	$allFieldsCollWithSqlTerminators = [];
	foreach ($allFieldsColl as $afsc)
		$allFieldsCollWithSqlTerminators[] = "`$afsc`";

	$fieldMapByType = [];
	foreach ($fields as $field) {
		$typeFlag = explode("(", $field["Type"])[0];
		$typeFlag = transposeSqlType($typeFlag);
		$fieldMapByType[$typeFlag][] = $field["Field"];
	}


	$s = [];

	$s[] = '<?php';
	$s[] = '';
	$s[] = 'namespace dbmodel;';
	$s[] = '';
	$s[] = 'class ' . strtolower($table) . ' extends \dbschema\\' . strtolower($table) . "";
	$s[] = '{';
	$s[] = '';

	//BEGIN FUNCTION __CONSTRUCT
	$s[] = '    public function __construct($key) {';
	$s[] = '';
	$s[] = '        if (is_int($key) && $key != 0)';
	$s[] = '            $this->' . $firstFieldName . ' = (int)$key;';

	if ($hasGuidField) {
		$s[] = '';
		$s[] = '        if (is_guid($key)) {';
		$s[] = '            $this->' . $firstFieldName . ' = self::resolveGuidToId($key);';
		$s[] = '        }';
	}

	$s[] = '		$this->spawn();';
	$s[] = '    }';
	$s[] = '';
	//END FUNCTION __CONSTRUCT

	//BEGIN FUNCTION resolveGuidToId
	if ($hasGuidField) {

		$s[] = '    public static function resolveGuidToId(string $guid): int {';
		$s[] = '       $_q = "SELECT ' . $firstFieldName . ' FROM `' . $table . '` WHERE guid = \"".\Database::filter($guid)."\" LIMIT 1";';
		$s[] = '       $t = \Database::get($_q, true);';
		$s[] = '       return (int)$t[\'' . $firstFieldName . '\'];';
		$s[] = '    }';
		$s[] = '';
	}
	//END FUNCTION resolveGuidToId

	//BEGIN FUNCTION isValid
	$s[] = '    public function isValid(): bool {';
	$s[] = '    	return (bool)$this->' . $firstFieldName . ';';
	$s[] = '    }';
	$s[] = '';
	//END FUNCTION isValid

	//BEGIN FUNCTION spawn
	$s[] = '    public function spawn(): bool {';
	$s[] = '        if (!$this->isValid())';
	$s[] = '           return false;';
	$s[] = '';

	$s[] = '        $_q = "SELECT ' . implode(", ", $allFieldsCollWithSqlTerminators) . ' FROM `' . $table . '` WHERE `' . $firstFieldName . '` = ".\Database::filter($this->' . $firstFieldName . ')." LIMIT 1";';
	$s[] = '        $t = \Database::get($_q, true);';
	$s[] = '';

	$s[] = '        if (empty($t))';
	$s[] = '            return false;';
	$s[] = '';

	foreach ($fields as $f) {
		if (strstr($f['Type'], 'int'))
			$s[] = '        $this->' . $f['Field'] . ' = (int)$t[\'' . $f['Field'] . '\'];';
		else if (strstr($f['Type'], 'double') || strstr($f['Type'], 'decimal'))
			$s[] = '        $this->' . $f['Field'] . ' = (double)$t[\'' . $f['Field'] . '\'];';
		else
			$s[] = '        $this->' . $f['Field'] . ' = $t[\'' . $f['Field'] . '\'];';
	}

	$s[] = '';
	$s[] = '        return true;';
	$s[] = '}';
	$s[] = '';

	$s[] = '    public function update($key, $value): bool {';
	$s[] = '        if (!$this->isUpdateAllowedKey($key))';
	$s[] = '            return false;';
	$s[] = '';

	$s[] = '        switch ($key) {';

	foreach ($fieldMapByType as $type => $fields) {
		foreach ($fields as $field)
			$s[] = "            case '$field':";

		if ($type == "string")
			$s[] = '                $value = "\"".\Database::filter((' . $type . ')$value)."\"";';
		else
			$s[] = '                $value = \Database::filter((' . $type . ')$value);';

		$s[] = '                break;';
		$s[] = '';
	}

	$s[] = '            default:';
	$s[] = '    	        return false;';
	$s[] = '        }';

	$s[] = '        $_q = "UPDATE `' . $table . '` SET `$key` = $value WHERE guid = \"$this->guid\";";';
	$s[] = '        return \Database::query($_q);';
	$s[] = '    }';
	$s[] = '';
	//END FUNCTION spawn

	//BEGIN FUNCTION isUpdateAllowedKey
	$s[] = 'function isUpdateAllowedKey($key) :bool {';
	$s[] = '    $a = \helper\updateable::' . strtolower($table) . '();';
	$s[] = '    return in_array($key, $a);';
	$s[] = '}';
	$s[] = '';
	//END FUNCTION isUpdateAllowedKey


	$s[] = '}';

	// ...
	return implode(PHP_EOL, $s);
}

function generateDistrib($database, $table)
{
	$fields = $database->get("DESC `" . $table . "`");
	$firstFieldName = $fields[0]['Field'];

	$hasGuidField = false;
	foreach ($fields as $f)
		if (strtolower($f['Field']) == "guid")
			$hasGuidField = true;

	$allFieldsColl = [];
	foreach ($fields as $f)
		$allFieldsColl[] = $f['Field'];

	$allFieldsCollWithSqlTerminators = [];
	foreach ($allFieldsColl as $afsc)
		$allFieldsCollWithSqlTerminators[] = "`$afsc`";

	$fieldMapByType = [];
	foreach ($fields as $field) {
		$typeFlag = explode("(", $field["Type"])[0];
		$typeFlag = transposeSqlType($typeFlag);
		$fieldMapByType[$typeFlag][] = $field["Field"];
	}


	$s = [];

	$s[] = '<?php';
	$s[] = 'namespace distrib;';
	$s[] = '';
	$s[] = 'class ' . strtolower($table) . ' extends \dbmodel\\' . strtolower($table) . "";
	$s[] = '{';
	$s[] = '';
	$s[] = '}';

	// ...
	return implode(PHP_EOL, $s);
}

function generateController($database, $table)
{
	$fields = $database->get("DESC `" . $table . "`");
	$firstFieldName = $fields[0]['Field'];

	$s = [];

	$s[] = '<?php';
	$s[] = '';
	$s[] = 'namespace controller;';
	$s[] = '';
	$s[] = 'class ' . strtolower($table) . '';
	$s[] = '{';
	$s[] = '';
	$s[] = '	public static function getAll(): array';
	$s[] = '	{';
	$s[] = '		$_q = "SELECT '.$firstFieldName.' FROM `'.$table.'`;";';
	$s[] = '		$t = \Database::get($_q);';
	$s[] = '		$r = [];';
	$s[] = '		foreach ($t as $u)';
	$s[] = '			$r[] = new \\distrib\\'.$table.'((int)$u["'.$firstFieldName.'"]);';
	$s[] = '		return $r;';
	$s[] = '	}';
	$s[] = '';
	$s[] = '}';

	// ...
	return implode(PHP_EOL, $s);
}


function putImportFile(string $folder) {
	$importFile = '<?php
foreach (glob(dirname(__FILE__)."/*.class.php") as $file)
{
	if (!strstr($file, "._"))
	{
		require_once($file);
	}
}';
	echo "placing import file in ".$folder."/_import.php".PHP_EOL;
	file_put_contents($folder."/_import.php", $importFile);
}