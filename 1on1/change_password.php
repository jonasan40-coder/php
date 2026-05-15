<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    if ($new_password === '' || $new_password_confirm === '') {
        $error = '新しいパスワードを入力してください。';
    } elseif ($new_password !== $new_password_confirm) {
        $error = '新しいパスワードが一致しません。';
    } elseif (strlen($new_password) < 6) {
        $error = 'パスワードは6文字以上にしてください。';
    } else {
        $user_id = intval($_SESSION['user_id']);

        $sql = "SELECT password_hash FROM users WHERE user_id = ? AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            $error = 'ユーザー情報が見つかりません。';
        } else {
            $stored_password = $user['password_hash'];

            // 既存が平文パスワードでも一応対応
            $is_ok = password_verify($current_password, $stored_password)
                  || $current_password === $stored_password;

            if (!$is_ok) {
                $error = '現在のパスワードが違います。';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

                $sql = "UPDATE users SET password_hash = ? WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $new_hash, $user_id);
                $stmt->execute();

                $success = 'パスワードを変更しました。';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>パスワード変更</title>
<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
}
.form-box {
    max-width: 420px;
    border: 1px solid #ccc;
    padding: 20px;
    background: #fafafa;
}
label {
    display: block;
    margin-top: 12px;
    font-weight: bold;
}
input {
    width: 100%;
    padding: 8px;
    box-sizing: border-box;
}
.btn {
    display: inline-block;
    padding: 8px 12px;
    margin-top: 16px;
    text-decoration: none;
    border: 1px solid #999;
    background: #fff;
    color: #333;
    border-radius: 4px;
}
.btn-main {
    background: #333;
    color: #fff;
}
.error {
    color: #b30000;
    font-weight: bold;
}
.success {
    color: #1b6b2a;
    font-weight: bold;
}
</style>
</head>
<body>

<h2>パスワード変更</h2>

<div class="form-box">

<?php if ($error): ?>
    <p class="error"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="success"><?= h($success) ?></p>
<?php endif; ?>

<form method="post">
    <label>現在のパスワード</label>
    <input type="password" name="current_password" required>

    <label>新しいパスワード</label>
    <input type="password" name="new_password" required>

    <label>新しいパスワード確認</label>
    <input type="password" name="new_password_confirm" required>

    <button class="btn btn-main" type="submit">変更する</button>
    <a class="btn" href="dashboard.php">戻る</a>
</form>

</div>

</body>
</html>