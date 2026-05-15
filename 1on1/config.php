<?php
// config.php

$host = "mysql78.conoha.ne.jp";
$user = "k5juu_koya_chida";
$pass = "password2413!";
$db   = "k5juu_digi";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("DB接続エラー: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>