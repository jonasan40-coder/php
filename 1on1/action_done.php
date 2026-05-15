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
$action_id = intval($_POST['action_id'] ?? 0);
$return_to = $_POST['return_to'] ?? '';

if ($session_id <= 0 || $action_id <= 0) {
    exit('session_id または action_id が不正です');
}

if ($role === 'admin') {
    $sql = "
        SELECT a.action_id
        FROM actions a
        INNER JOIN oneonone_sessions s
            ON a.session_id = s.session_id
        WHERE a.action_id = ?
          AND a.session_id = ?
          AND a.is_deleted = 0
          AND s.is_deleted = 0
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $action_id, $session_id);
} else {
    $sql = "
        SELECT a.action_id
        FROM actions a
        INNER JOIN oneonone_sessions s
            ON a.session_id = s.session_id
        WHERE a.action_id = ?
          AND a.session_id = ?
          AND a.is_deleted = 0
          AND s.is_deleted = 0
          AND (
              s.user_id = ?
              OR s.manager_id = ?
              OR s.mentor_id = ?
          )
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiii", $action_id, $session_id, $login_user_id, $login_user_id, $login_user_id);
}

$stmt->execute();
$action = $stmt->get_result()->fetch_assoc();

if (!$action) {
    exit('更新権限がない、またはデータが存在しません');
}

$sql = "
    UPDATE actions
    SET status = '完了'
    WHERE action_id = ?
      AND session_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $action_id, $session_id);
$stmt->execute();

if ($return_to === 'dashboard') {
    header("Location: dashboard.php");
} else {
    header("Location: meeting_form.php?session_id=" . $session_id);
}
exit;
