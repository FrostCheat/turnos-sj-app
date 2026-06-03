<?php
define('DB_HOST', 'sql109.infinityfree.com');
define('DB_PORT', '3306');
define('DB_NAME', 'if0_42090933_turnos_sj');
define('DB_USER', 'if0_42090933');
define('DB_PASS', '7y9nEuFiDbl9');
define('DB_CHARSET', 'utf8mb4');

function getDbConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}