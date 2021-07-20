<?php
//Kickstart the framework
require_once("vendor/autoload.php");
$f3 = Base::instance();

//Load configuration
$f3->config('config/config.ini');

//Load routes
$f3->config('config/routes.ini');

//Set debug level
$f3->set('DEBUG', $f3->get('debug_level'));

//Set cache directory
$f3->set('CACHE', 'folder=tmp/cache/');

//Set temp dir for compiled templates
$f3->set('TEMP', 'tmp/');

//Init database connection
$db_options = [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_PERSISTENT => TRUE,
    \PDO::MYSQL_ATTR_COMPRESS => TRUE,
];
$db = new DB\SQL(sprintf('mysql:host=%s;dbname=%s', $f3->get('db_host'), $f3->get('db_name')), $f3->get('db_user'), $f3->get('db_pass'), $db_options);
$f3->set('dbh', $db);

//Autoload classes
$f3->set('AUTOLOAD', 'app/classes/');

//Set template dir
$f3->set('UI', 'app/templates/');

//Allow up to 1GB memory usage
ini_set("memory_limit", "1024M");

//Set default timezone
date_default_timezone_set('America/New_York');

//Run dat code
$f3->run();