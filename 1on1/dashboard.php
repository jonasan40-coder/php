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

$user_id = intval($_SESSION['user_id']);
$role = $_SESSION['role'] ?? 'user';

if ($role === 'admin') {
    $sql = "
        SELECT
            s.*,
            u.name AS member_name,
            m.name AS mentor_name
        FROM oneonone_sessions s
        INNER JOIN users u ON s.user_id = u.user_id
        LEFT JOIN users m ON s.mentor_id = m.user_id
        WHERE s.is_deleted = 0
        ORDER BY s.session_date DESC, s.session_id DESC
    ";
    $stmt = $conn->prepare($sql);
} else {
    $sql = "
        SELECT
            s.*,
            u.name AS member_name,
            m.name AS mentor_name
        FROM oneonone_sessions s
        INNER JOIN users u ON s.user_id = u.user_id
        LEFT JOIN users m ON s.mentor_id = m.user_id
        WHERE s.is_deleted = 0
          AND (
              s.user_id = ?
              OR s.manager_id = ?
              OR s.mentor_id = ?
          )
        ORDER BY s.session_date DESC, s.session_id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $user_id, $user_id, $user_id);
}

$stmt->execute();
$sessions = $stmt->get_result();

$sql = "
    SELECT COUNT(*) AS cnt
    FROM actions a
    INNER JOIN oneonone_sessions s ON a.session_id = s.session_id
    WHERE a.status <> '完了'
      AND a.is_deleted = 0
";

if ($role !== 'admin') {
    $sql .= "
      AND (
          s.user_id = ?
          OR s.manager_id = ?
          OR s.mentor_id = ?
      )
    ";
    $action_stmt = $conn->prepare($sql);
    $action_stmt->bind_param("iii", $user_id, $user_id, $user_id);
} else {
    $action_stmt = $conn->prepare($sql);
}

$action_stmt->execute();
$action_count = $action_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;

function status_class($status) {
    switch ($status) {
        case '事前未入力':
            return 'status-gray';
        case '事前入力済':
            return 'status-blue';
        case '面談済':
            return 'status-orange';
        case '事後未入力':
            return 'status-purple';
        case '完了':
            return 'status-green';
        default:
            return 'status-gray';
    }
}

if ($role === 'admin') {
    $sql = "
        SELECT
            a.*,
            u.name AS member_name,
            s.session_date,
            s.target_month,
            t.theme_name
        FROM actions a
        INNER JOIN oneonone_sessions s ON a.session_id = s.session_id
        INNER JOIN users u ON a.user_id = u.user_id
        LEFT JOIN themes t ON a.theme_id = t.theme_id
        WHERE a.status <> '完了'
          AND a.is_deleted = 0
        ORDER BY
            CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END,
            a.due_date ASC,
            a.action_id DESC
    ";
    $actions_stmt = $conn->prepare($sql);
} else {
    $sql = "
        SELECT
            a.*,
            u.name AS member_name,
            s.session_date,
            s.target_month,
            t.theme_name
        FROM actions a
        INNER JOIN oneonone_sessions s ON a.session_id = s.session_id
        INNER JOIN users u ON a.user_id = u.user_id
        LEFT JOIN themes t ON a.theme_id = t.theme_id
        WHERE a.status <> '完了'
          AND a.is_deleted = 0
          AND (
              s.user_id = ?
              OR s.manager_id = ?
              OR s.mentor_id = ?
          )
        ORDER BY
            CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END,
            a.due_date ASC,
            a.action_id DESC
    ";
    $actions_stmt = $conn->prepare($sql);
    $actions_stmt->bind_param("iii", $user_id, $user_id, $user_id);
}

$actions_stmt->execute();
$actions = $actions_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>1on1 ダッシュボード</title>
<style>
body {
    font-family: Arial, sans-serif;
    line-height: 1.5;
    margin: 20px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.card-area {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
}

.card {
    border: 1px solid #ccc;
    padding: 12px;
    min-width: 180px;
    background: #fafafa;
}

.card-title {
    font-size: 13px;
    color: #555;
}

.card-value {
    font-size: 24px;
    font-weight: bold;
}

.btn,
button {
    display: inline-block;
    padding: 6px 10px;
    margin: 2px;
    text-decoration: none;
    border-radius: 4px;
    border: 1px solid #999;
    color: #333;
    background: #fff;
    font-size: 13px;
    cursor: pointer;
}

.btn-main {
    background: #333;
    color: #fff;
    border-color: #333;
}

.btn-pre {
    background: #eef5ff;
}

.btn-meeting {
    background: #fff7e6;
}

.btn-logout {
    background: #eee;
}

table {
    border-collapse: collapse;
    width: 100%;
}

th, td {
    border: 1px solid #ccc;
    padding: 8px;
    vertical-align: middle;
}

th {
    background: #eee;
}

.status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
}

.status-gray {
    background: #eee;
    color: #333;
}

.status-blue {
    background: #e6f0ff;
    color: #004a99;
}

.status-orange {
    background: #fff0d6;
    color: #9a5a00;
}

.status-purple {
    background: #f0e6ff;
    color: #5a2ca0;
}

.status-green {
    background: #e5f7e9;
    color: #1b6b2a;
}

.operation {
    white-space: nowrap;
}

.action-scroll {
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid #ccc;
    margin-top: 8px;
}

.action-scroll table {
    width: 100%;
    border-collapse: collapse;
}

.action-scroll th {
    position: sticky;
    top: 0;
    background: #eee;
    z-index: 1;
}

.inline-form {
    margin: 0;
}
</style>
</head>
<body>

<div class="header">
    <div>
        <h2>1on1 ダッシュボード</h2>
        <div>
            ログイン中：<?= h($_SESSION['name'] ?? '') ?>
            （<?= h($role) ?>）
        </div>
    </div>

    <div>
        <a class="btn btn-main" href="session_create.php">新規1on1作成</a>
        <a class="btn" href="change_password.php">パスワード変更</a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a class="btn" href="user_create.php">ユーザー管理</a>
        <?php endif; ?>
        <a class="btn btn-logout" href="logout.php">ログアウト</a>
        <a class="btn" href="1on1_manual.html">マニュアル</a>
    </div>
</div>

<div class="card-area">
    <div class="card">
        <div class="card-title">未完了アクション</div>
        <div class="card-value"><?= h($action_count) ?></div>
    </div>
</div>

<table>
<tr>
    <th>ID</th>
    <th>対象者</th>
    <th>面談日</th>
    <th>対象月</th>
    <th>面談者</th>
    <th>ステータス</th>
    <th>操作</th>
</tr>

<?php while ($row = $sessions->fetch_assoc()): ?>
<tr>
    <td><?= h($row['session_id']) ?></td>
    <td><?= h($row['member_name']) ?></td>
    <td><?= h($row['session_date']) ?></td>
    <td><?= h($row['target_month']) ?></td>
    <td><?= h($row['mentor_name'] ?? '') ?></td>
    <td>
        <span class="status <?= h(status_class($row['status'])) ?>">
            <?= h($row['status']) ?>
        </span>
    </td>
    <td class="operation">
        <a class="btn btn-pre" href="session_detail.php?session_id=<?= h($row['session_id']) ?>">
            事前アンケート
        </a>
        <a class="btn btn-meeting" href="meeting_form.php?session_id=<?= h($row['session_id']) ?>">
            当日面談
        </a>
        <a class="btn" href="post_survey.php?session_id=<?= h($row['session_id']) ?>">
            事後アンケート
        </a>
    </td>
</tr>
<?php endwhile; ?>

</table>

<h3>未完了アクション</h3>

<div class="action-scroll">
<table>
<tr>
    <th>対象者</th>
    <th>対象月</th>
    <th>面談日</th>
    <th>テーマ</th>
    <th>アクション</th>
    <th>期限</th>
    <th>状態</th>
    <th>操作</th>
</tr>

<?php if ($actions->num_rows === 0): ?>
<tr>
    <td colspan="8">未完了アクションはありません</td>
</tr>
<?php else: ?>
    <?php while ($a = $actions->fetch_assoc()): ?>
    <tr>
        <td><?= h($a['member_name']) ?></td>
        <td><?= h($a['target_month']) ?></td>
        <td><?= h($a['session_date']) ?></td>
        <td><?= h($a['theme_name'] ?? '') ?></td>
        <td><?= nl2br(h($a['content'])) ?></td>
        <td><?= h($a['due_date']) ?></td>
        <td><?= h($a['status']) ?></td>
        <td>
            <form class="inline-form" method="post" action="action_done.php">
                <input type="hidden" name="session_id" value="<?= h($a['session_id']) ?>">
                <input type="hidden" name="action_id" value="<?= h($a['action_id']) ?>">
                <input type="hidden" name="return_to" value="dashboard">
                <button type="submit">完了</button>
            </form>
        </td>
    </tr>
    <?php endwhile; ?>
<?php endif; ?>

</table>
</div>

</body>
</html>
