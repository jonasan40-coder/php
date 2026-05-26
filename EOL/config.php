<?php
// config.php

$host = "mysql78.conoha.ne.jp";
$user = "k5juu_koya_chida";
$pass = "password2413!";
$oldDb = "k5juu_digi";
$db   = "k5juu_eol";

if (!defined('EOL_DB_HOST')) {
    define('EOL_DB_HOST', $host);
    define('EOL_DB_NAME', $db);
    define('EOL_DB_USER', $user);
    define('EOL_DB_PASSWORD', $pass);
}

if (class_exists('mysqli')) {
    $conn = new mysqli($host, $user, $pass, $db);

    if ($conn->connect_error) {
        die("DB connection error: " . $conn->connect_error);
    }

    $conn->set_charset("utf8mb4");
}
?>
