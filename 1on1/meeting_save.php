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
$theme_id = intval($_POST['theme_id'] ?? 0);

if ($session_id <= 0 || $theme_id <= 0) {
    exit('session_id または theme_id が不正です');
}

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

$fields = [
    'person_comment',
    'interviewer_comment',
    'insight',
    'decision_text',
    'next_action',
    'due_date',
    'follow_required'
];

$inserted = false;

foreach ($fields as $field_name) {
    if ($field_name === 'follow_required') {
        if (!isset($_POST[$field_name])) {
            continue;
        }
        $note_text = 'フォロー要';
    } else {
        $note_text = trim($_POST[$field_name] ?? '');
        if ($note_text === '') {
            continue;
        }
    }

    $sql = "
        INSERT INTO meeting_note_entries
        (session_id, theme_id, field_name, note_text, created_by)
        VALUES (?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iissi",
        $session_id,
        $theme_id,
        $field_name,
        $note_text,
        $login_user_id
    );
    $stmt->execute();
    $inserted = true;

    if ($field_name === 'next_action') {
        $due_date = trim($_POST['due_date'] ?? '');
        $due_date = $due_date !== '' ? $due_date : null;

        $sql = "
            INSERT INTO actions
            (session_id, user_id, theme_id, action_owner, content, due_date, status, is_deleted)
            VALUES (?, ?, ?, 'member', ?, ?, '未着手', 0)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iiiss",
            $session_id,
            $session['user_id'],
            $theme_id,
            $note_text,
            $due_date
        );
        $stmt->execute();
    }
}

if ($inserted) {
    $sql = "
        UPDATE oneonone_sessions
        SET status = CASE
            WHEN status IN ('事前未入力', '事前入力済') THEN '面談済'
            ELSE status
        END
        WHERE session_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
}

header("Location: meeting_form.php?session_id=" . $session_id . "#theme-" . $theme_id);
exit;
