<?php
session_start();
require_once 'config.php';

$login_id = $_POST['login_id'] ?? '';
$password = $_POST['password'] ?? '';

$sql = "
    SELECT user_id, login_id, password_hash, name, role
    FROM users
    WHERE login_id = ?
      AND is_active = 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $login_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    $stored_password = $user['password_hash'];

    $is_ok = password_verify($password, $stored_password)
          || $password === $stored_password;

    if ($is_ok) {
        // 平文だった場合はログイン成功時にハッシュ化して更新
        if ($password === $stored_password) {
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $update->bind_param("si", $new_hash, $user['user_id']);
            $update->execute();
        }

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['login_id'] = $user['login_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];

        header("Location: dashboard.php");
        exit;
    }
}

echo "ログイン失敗";