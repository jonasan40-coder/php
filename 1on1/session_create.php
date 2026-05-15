<?php
// session_create.php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$sql = "SELECT user_id, name FROM users WHERE can_be_mentor = 1 AND is_active = 1";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>1on1作成</title>
</head>
<body>

<h2>1on1作成</h2>

<form method="post" action="session_create_exec.php">

面談希望日（必須 希望がない場合は今日の日付）<br>
<input type="date" name="session_date" required><br><br>

面談者<br>
<select name="mentor_id">
<option value="">選択してください</option>
<?php while($row = $result->fetch_assoc()): ?>
<option value="<?= $row['user_id'] ?>">
<?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>
</option>
<?php endwhile; ?>
</select><br><br>

<button type="submit">作成</button>

</form>

<p><a href="dashboard.php">戻る</a></p>

</body>
</html>