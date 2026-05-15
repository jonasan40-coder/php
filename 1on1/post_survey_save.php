<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$login_user_id = intval($_SESSION['user_id']);
$role = $_SESSION['role'] ?? 'user';
$session_id = intval($_POST['session_id'] ?? 0);

if ($session_id <= 0) {
    exit('session_idが不正です');
}

// 権限確認
if ($role === 'admin') {
    $sql = "
        SELECT *
        FROM oneonone_sessions
        WHERE session_id = ?
          AND is_deleted = 0
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $session_id);
} else {
    $sql = "
        SELECT *
        FROM oneonone_sessions
        WHERE session_id = ?
          AND is_deleted = 0
          AND (
              user_id = ?
              OR manager_id = ?
              OR mentor_id = ?
          )
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $session_id, $login_user_id, $login_user_id, $login_user_id);
}

$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    exit('更新権限がない、またはデータが存在しません');
}

$good_point = $_POST['good_point'] ?? '';
$next_topic = $_POST['next_topic'] ?? '';
$interviewer_good = $_POST['interviewer_good'] ?? '';
$interviewer_request = $_POST['interviewer_request'] ?? '';
$improvement = $_POST['improvement'] ?? '';
$self_action = $_POST['self_action'] ?? '';
$satisfaction = ($_POST['satisfaction'] ?? '') !== '' ? intval($_POST['satisfaction']) : null;

// 既存有無確認
$sql = "SELECT id FROM post_surveys WHERE session_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

if ($existing) {
    $sql = "
        UPDATE post_surveys
        SET
            good_point = ?,
            next_topic = ?,
            interviewer_good = ?,
            interviewer_request = ?,
            improvement = ?,
            self_action = ?,
            satisfaction = ?
        WHERE session_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssssii",
        $good_point,
        $next_topic,
        $interviewer_good,
        $interviewer_request,
        $improvement,
        $self_action,
        $satisfaction,
        $session_id
    );
    $stmt->execute();
} else {
    $sql = "
        INSERT INTO post_surveys
        (
            session_id,
            good_point,
            next_topic,
            interviewer_good,
            interviewer_request,
            improvement,
            self_action,
            satisfaction
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "issssssi",
        $session_id,
        $good_point,
        $next_topic,
        $interviewer_good,
        $interviewer_request,
        $improvement,
        $self_action,
        $satisfaction
    );
    $stmt->execute();
}

// 自分の次アクションがあればactionsにも登録
if (trim($self_action) !== '') {
    $sql = "
        INSERT INTO actions
        (session_id, user_id, action_owner, content, due_date, status, is_deleted)
        VALUES (?, ?, 'member', ?, NULL, '未着手', 0)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iis",
        $session_id,
        $session['user_id'],
        $self_action
    );
    $stmt->execute();
}

// ステータス完了
$sql = "
    UPDATE oneonone_sessions
    SET status = '完了'
    WHERE session_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();

header("Location: dashboard.php");
exit;
