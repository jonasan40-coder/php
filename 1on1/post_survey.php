<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$login_user_id = intval($_SESSION['user_id']);
$role = $_SESSION['role'] ?? 'user';
$session_id = intval($_GET['session_id'] ?? 0);

if ($session_id <= 0) {
    exit('session_idが不正です');
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// セッション取得・権限確認
if ($role === 'admin') {
    $sql = "
        SELECT s.*, u.name AS member_name, m.name AS mentor_name
        FROM oneonone_sessions s
        INNER JOIN users u ON s.user_id = u.user_id
        LEFT JOIN users m ON s.mentor_id = m.user_id
        WHERE s.session_id = ?
          AND s.is_deleted = 0
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $session_id);
} else {
    $sql = "
        SELECT s.*, u.name AS member_name, m.name AS mentor_name
        FROM oneonone_sessions s
        INNER JOIN users u ON s.user_id = u.user_id
        LEFT JOIN users m ON s.mentor_id = m.user_id
        WHERE s.session_id = ?
          AND s.is_deleted = 0
          AND (
              s.user_id = ?
              OR s.manager_id = ?
              OR s.mentor_id = ?
          )
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $session_id, $login_user_id, $login_user_id, $login_user_id);
}

$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    exit('閲覧権限がない、またはデータが存在しません');
}

// 既存事後アンケート取得
$sql = "SELECT * FROM post_surveys WHERE session_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();

if (!$post) {
    $post = [
        'good_point' => '',
        'next_topic' => '',
        'interviewer_good' => '',
        'interviewer_request' => '',
        'improvement' => '',
        'self_action' => '',
        'satisfaction' => ''
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>事後アンケート</title>
<style>
body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
    margin: 20px;
}

.header {
    margin-bottom: 20px;
}

.section {
    border: 1px solid #ccc;
    padding: 14px;
    margin-bottom: 18px;
    background: #fafafa;
}

label {
    font-weight: bold;
}

textarea {
    width: 98%;
    height: 90px;
}

select {
    padding: 6px;
}

.btn {
    display: inline-block;
    padding: 8px 12px;
    margin: 2px;
    text-decoration: none;
    border: 1px solid #999;
    background: #fff;
    color: #333;
    border-radius: 4px;
}

.btn-main {
    background: #333;
    color: #fff;
    border-color: #333;
}
</style>
</head>
<body>

<div class="header">
    <h2>事後アンケート</h2>
    <p>
        対象者：<?= h($session['member_name']) ?>　
        面談日：<?= h($session['session_date']) ?>　
        面談者：<?= h($session['mentor_name'] ?? '') ?>　
        ステータス：<?= h($session['status']) ?>
    </p>
    <p>
        <a class="btn" href="dashboard.php">ダッシュボードへ戻る</a>
        <a class="btn" href="meeting_form.php?session_id=<?= h($session_id) ?>">当日面談フォームへ</a>
    </p>
</div>

<form method="post" action="post_survey_save.php">
<input type="hidden" name="session_id" value="<?= h($session_id) ?>">

<div class="section">
    <p>
        <label>話してよかったこと</label><br>
        <textarea name="good_point"><?= h($post['good_point']) ?></textarea>
    </p>

    <p>
        <label>次回話したいこと</label><br>
        <textarea name="next_topic"><?= h($post['next_topic']) ?></textarea>
    </p>

    <p>
        <label>面談者の良かった点</label><br>
        <textarea name="interviewer_good"><?= h($post['interviewer_good']) ?></textarea>
    </p>

    <p>
        <label>面談者に改善してほしい点</label><br>
        <textarea name="interviewer_request"><?= h($post['interviewer_request']) ?></textarea>
    </p>

    <p>
        <label>面談改善要望</label><br>
        <textarea name="improvement"><?= h($post['improvement']) ?></textarea>
    </p>

    <p>
        <label>自分の次アクション</label><br>
        <textarea name="self_action"><?= h($post['self_action']) ?></textarea>
    </p>

    <p>
        <label>満足度</label><br>
        <select name="satisfaction">
            <option value="">選択してください</option>
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <option value="<?= $i ?>" <?= ((string)$post['satisfaction'] === (string)$i) ? 'selected' : '' ?>>
                    <?= $i ?>
                </option>
            <?php endfor; ?>
        </select>
    </p>
</div>

<button class="btn btn-main" type="submit">保存して完了</button>

</form>

</body>
</html>
