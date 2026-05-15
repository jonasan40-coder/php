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
$session_date = $_POST['session_date'] ?? '';
$mentor_id = ($_POST['mentor_id'] ?? '') !== '' ? intval($_POST['mentor_id']) : null;

if ($session_id <= 0 || $session_date === '') {
    exit('入力内容が不正です');
}

$target_month = date('Y-m', strtotime($session_date));

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
$result = $stmt->get_result();
$session = $result->fetch_assoc();

if (!$session) {
    exit('更新権限がない、またはデータが存在しません');
}

// 編集履歴保存関数
function add_edit_log($conn, $table_name, $record_id, $field_name, $old_value, $new_value, $edited_by) {
    if ((string)$old_value === (string)$new_value) {
        return;
    }

    $sql = "
        INSERT INTO edit_logs
        (table_name, record_id, field_name, old_value, new_value, edited_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sisssi",
        $table_name,
        $record_id,
        $field_name,
        $old_value,
        $new_value,
        $edited_by
    );
    $stmt->execute();
}

add_edit_log($conn, 'oneonone_sessions', $session_id, 'session_date', $session['session_date'] ?? '', $session_date, $login_user_id);
add_edit_log($conn, 'oneonone_sessions', $session_id, 'target_month', $session['target_month'] ?? '', $target_month, $login_user_id);
add_edit_log($conn, 'oneonone_sessions', $session_id, 'mentor_id', $session['mentor_id'] ?? '', $mentor_id ?? '', $login_user_id);

// 基本情報更新
$sql = "
    UPDATE oneonone_sessions
    SET session_date = ?,
        target_month = ?,
        mentor_id = ?,
        status = CASE
            WHEN status = '事前未入力' THEN '事前入力済'
            ELSE status
        END
    WHERE session_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssii", $session_date, $target_month, $mentor_id, $session_id);
$stmt->execute();

// 事前アンケートは一旦削除して再登録
$sql = "DELETE FROM pre_survey_themes WHERE session_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();

$sort_order = 10;

// 必須テーマ
$required_theme_ids = $_POST['required_theme_ids'] ?? [];
$support_required = $_POST['support_type_required'] ?? [];
$comment_required = $_POST['comment_required'] ?? [];

foreach ($required_theme_ids as $theme_id_raw) {
    $theme_id = intval($theme_id_raw);
    $support_type_id = isset($support_required[$theme_id]) && $support_required[$theme_id] !== ''
        ? intval($support_required[$theme_id])
        : null;
    $comment = $comment_required[$theme_id] ?? '';

    $sql = "
        INSERT INTO pre_survey_themes
        (session_id, theme_id, support_type_id, comment, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiisi", $session_id, $theme_id, $support_type_id, $comment, $sort_order);
    $stmt->execute();

    $sort_order += 10;
}

// 任意テーマ 最大3つ
$optional_theme_ids = $_POST['optional_theme_ids'] ?? [];
$optional_theme_ids = array_slice($optional_theme_ids, 0, 3);

$support_optional = $_POST['support_type_optional'] ?? [];
$comment_optional = $_POST['comment_optional'] ?? [];

foreach ($optional_theme_ids as $theme_id_raw) {
    $theme_id = intval($theme_id_raw);
    $support_type_id = isset($support_optional[$theme_id]) && $support_optional[$theme_id] !== ''
        ? intval($support_optional[$theme_id])
        : null;
    $comment = $comment_optional[$theme_id] ?? '';

    $sql = "
        INSERT INTO pre_survey_themes
        (session_id, theme_id, support_type_id, comment, sort_order)
        VALUES (?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiisi", $session_id, $theme_id, $support_type_id, $comment, $sort_order);
    $stmt->execute();

    $sort_order += 10;
}

// 自由テーマ
$custom_theme_name = trim($_POST['custom_theme_name'] ?? '');
$support_type_custom = ($_POST['support_type_custom'] ?? '') !== '' ? intval($_POST['support_type_custom']) : null;
$comment_custom = $_POST['comment_custom'] ?? '';

if ($custom_theme_name !== '' || trim($comment_custom) !== '') {
    $sql = "
        INSERT INTO pre_survey_themes
        (session_id, theme_id, custom_theme_name, support_type_id, comment, sort_order)
        VALUES (?, NULL, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isisi", $session_id, $custom_theme_name, $support_type_custom, $comment_custom, $sort_order);
    $stmt->execute();
}

$meeting_person_comment = $_POST['meeting_person_comment'] ?? '';
$meeting_interviewer_comment = $_POST['meeting_interviewer_comment'] ?? '';
$meeting_insight = $_POST['meeting_insight'] ?? '';
$meeting_decision = $_POST['meeting_decision'] ?? '';
$meeting_next_action = $_POST['meeting_next_action'] ?? '';
$meeting_due_date = ($_POST['meeting_due_date'] ?? '') !== '' ? $_POST['meeting_due_date'] : null;
$meeting_follow_required = isset($_POST['meeting_follow_required']) ? 1 : 0;

if (
    trim($meeting_person_comment) !== '' ||
    trim($meeting_interviewer_comment) !== '' ||
    trim($meeting_insight) !== '' ||
    trim($meeting_decision) !== '' ||
    trim($meeting_next_action) !== ''
) {
    $sql = "
        INSERT INTO meeting_notes
        (
            session_id,
            person_comment,
            interviewer_comment,
            insight,
            decision_text,
            next_action,
            due_date,
            follow_required
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "issssssi",
        $session_id,
        $meeting_person_comment,
        $meeting_interviewer_comment,
        $meeting_insight,
        $meeting_decision,
        $meeting_next_action,
        $meeting_due_date,
        $meeting_follow_required
    );
    $stmt->execute();

    if (trim($meeting_next_action) !== '') {
        $sql = "
            INSERT INTO actions
            (session_id, user_id, action_owner, content, due_date, status)
            VALUES (?, ?, 'member', ?, ?, '未着手')
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iiss",
            $session_id,
            $session['user_id'],
            $meeting_next_action,
            $meeting_due_date
        );
        $stmt->execute();
    }
}

header("Location: session_detail.php?session_id=" . $session_id);
exit;
