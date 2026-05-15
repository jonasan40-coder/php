<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = trim($_POST['login_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $can_be_mentor = isset($_POST['can_be_mentor']) ? 1 : 0;

    if ($login_id === '' || $name === '' || $password === '') {
        $error = 'ログインID、氏名、パスワードは必須です。';
    } elseif (!in_array($role, ['user', 'manager', 'mentor', 'admin'], true)) {
        $error = '権限が不正です。';
    } elseif (strlen($password) < 6) {
        $error = 'パスワードは6文字以上にしてください。';
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE login_id = ?");
        $stmt->bind_param("s", $login_id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();

        if ($exists) {
            $error = 'このログインIDは既に使用されています。';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "
                INSERT INTO users
                    (login_id, name, password_hash, role, can_be_mentor, is_active)
                VALUES
                    (?, ?, ?, ?, ?, 1)
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ssssi",
                $login_id,
                $name,
                $password_hash,
                $role,
                $can_be_mentor
            );
            $stmt->execute();

            $success = 'ユーザーを登録しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>新規ユーザー登録</title>
<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
}
.form-box {
    max-width: 480px;
    border: 1px solid #ccc;
    padding: 20px;
    background: #fafafa;
}
label {
    display: block;
    margin-top: 12px;
    font-weight: bold;
}
input, select {
    width: 100%;
    padding: 8px;
    box-sizing: border-box;
}
.checkbox-label {
    display: flex;
    gap: 8px;
    align-items: center;
    font-weight: normal;
}
.checkbox-label input {
    width: auto;
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

<h2>新規ユーザー登録</h2>

<div class="form-box">

<?php if ($error): ?>
    <p class="error"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="success"><?= h($success) ?></p>
<?php endif; ?>

<form method="post">
    <label>ログインID</label>
    <input type="text" name="login_id" required>

    <label>氏名</label>
    <input type="text" name="name" required>

    <label>初期パスワード</label>
    <input type="password" name="password" required>

    <label>権限</label>
    <select name="role">
        <option value="user">一般ユーザー</option>
        <option value="manager">面談者</option>
        <option value="mentor">メンター</option>
        <option value="admin">管理者</option>
    </select>

    <label class="checkbox-label">
        <input type="checkbox" name="can_be_mentor" value="1">
        面談者として選択可能
    </label>

    <button class="btn btn-main" type="submit">登録する</button>
    <a class="btn" href="dashboard.php">戻る</a>
</form>

</div>

</body>
</html>