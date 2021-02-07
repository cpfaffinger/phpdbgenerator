<?php
//core classes
require_once(__DIR__ . '/functions.inc.php');
require_once(__DIR__ . '/config.inc.php');

require_once(__DIR__ . '/database.mysqli.class.php');

//database
global $d;
$d = new database_mysqli(_MYSQL_HOST, _MYSQL_DBAS, _MYSQL_USER, _MYSQL_PASS);
