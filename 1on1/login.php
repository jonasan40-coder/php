<?php
session_start();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>1on1 ログイン</title>
</head>
<body>

<h2>1on1管理ツール ログイン</h2>

<form method="post" action="login_exec.php">
    <div>
        ログインID：<br>
        <input type="text" name="login_id" required>
    </div>
    <div>
        パスワード：<br>
        <input type="password" name="password" required>
    </div>
    <br>
    <button type="submit">ログイン</button>
</form>

</body>
</html>