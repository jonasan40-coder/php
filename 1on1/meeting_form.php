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
    exit('session_id が不正です');
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fieldLabels() {
    return [
        'person_comment' => '本人コメント',
        'interviewer_comment' => '面談者コメント',
        'insight' => '気づき',
        'decision_text' => '決定事項',
        'next_action' => '次回アクション',
        'due_date' => '期限',
        'follow_required' => 'フォロー要'
    ];
}

function renderEntryList($entries, $field_name) {
    if (empty($entries[$field_name])) {
        return;
    }

    echo '<div class="entry-list">';
    foreach ($entries[$field_name] as $entry) {
        echo '<div class="entry-item">';
        echo '<div class="entry-meta">' . h($entry['created_at']) . ' / ' . h($entry['created_by_name'] ?? '') . '</div>';
        echo '<div>' . nl2br(h($entry['note_text'])) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

function renderLegacyNote($note, $field_name) {
    if (empty($note[$field_name])) {
        return;
    }

    echo '<div class="legacy-note">';
    echo '<div class="entry-meta">既存メモ</div>';
    if ($field_name === 'follow_required') {
        echo '<div>フォロー要</div>';
    } else {
        echo '<div>' . nl2br(h($note[$field_name])) . '</div>';
    }
    echo '</div>';
}

function appendPastValue(&$past_data, $session_id, $theme_id, $field_name, $value) {
    if (!isset($past_data[$session_id][$theme_id])) {
        $past_data[$session_id][$theme_id] = [
            'pre_comment' => '',
            'person_comment' => '',
            'interviewer_comment' => '',
            'insight' => '',
            'decision_text' => '',
            'next_action' => '',
            'due_date' => '',
            'follow_required' => ''
        ];
    }

    if (isset($past_data[$session_id][$theme_id][$field_name])) {
        $past_data[$session_id][$theme_id][$field_name] = trim($past_data[$session_id][$theme_id][$field_name] . "\n\n" . $value);
    }
}

function renderPastCell($row) {
    if (
        empty($row['pre_comment']) &&
        empty($row['person_comment']) &&
        empty($row['interviewer_comment']) &&
        empty($row['insight']) &&
        empty($row['decision_text']) &&
        empty($row['next_action']) &&
        empty($row['due_date']) &&
        empty($row['follow_required'])
    ) {
        echo '<span class="empty">記録なし</span>';
        return;
    }

    $labels = fieldLabels();

    if (!empty($row['pre_comment'])) {
        echo '<div><b>事前</b><br>' . nl2br(h($row['pre_comment'])) . '</div>';
    }

    foreach ($labels as $field => $label) {
        if (!empty($row[$field])) {
            echo '<div><b>' . h($label) . '</b><br>' . nl2br(h($row[$field])) . '</div>';
        }
    }
}

if ($role === 'admin') {
    $sql = "
        SELECT s.*, u.name AS member_name
        FROM oneonone_sessions s
        INNER JOIN users u ON s.user_id = u.user_id
        WHERE s.session_id = ?
          AND s.is_deleted = 0
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $session_id);
} else {
    $sql = "
        SELECT s.*, u.name AS member_name
        FROM oneonone_sessions s
        INNER JOIN users u ON s.user_id = u.user_id
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

$member_user_id = intval($session['user_id']);

$sql = "
    SELECT theme_id, theme_name, is_required, sort_order
    FROM themes
    WHERE is_active = 1
    ORDER BY sort_order, theme_id
";
$res = $conn->query($sql);

$all_themes = [];
while ($row = $res->fetch_assoc()) {
    $all_themes[intval($row['theme_id'])] = $row;
}

$sql = "
    SELECT
        p.theme_id,
        p.comment AS pre_comment,
        p.support_type_id,
        st.support_type_name
    FROM pre_survey_themes p
    LEFT JOIN support_types st
        ON p.support_type_id = st.support_type_id
    WHERE p.session_id = ?
      AND p.theme_id IS NOT NULL
    ORDER BY p.sort_order, p.id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$res = $stmt->get_result();

$pre_survey_data = [];
$selected_theme_ids = [];

while ($row = $res->fetch_assoc()) {
    $tid = intval($row['theme_id']);
    $selected_theme_ids[$tid] = true;
    $pre_survey_data[$tid] = $row;
}

$upper_themes = [];
$lower_themes = [];

foreach ($all_themes as $theme_id => $theme) {
    if (intval($theme['is_required']) === 1 || isset($selected_theme_ids[$theme_id])) {
        $upper_themes[$theme_id] = $theme;
    } else {
        $lower_themes[$theme_id] = $theme;
    }
}

$sql = "
    SELECT session_id, session_date, target_month
    FROM oneonone_sessions
    WHERE user_id = ?
      AND session_id <> ?
      AND is_deleted = 0
    ORDER BY session_date DESC, session_id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $member_user_id, $session_id);
$stmt->execute();
$past_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$current_notes = [];
$sql = "
    SELECT *
    FROM meeting_notes
    WHERE session_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $current_notes[intval($row['theme_id'])] = $row;
}

$current_entries = [];
$sql = "
    SELECT
        e.*,
        u.name AS created_by_name
    FROM meeting_note_entries e
    LEFT JOIN users u
        ON e.created_by = u.user_id
    WHERE e.session_id = ?
      AND e.is_deleted = 0
    ORDER BY e.created_at, e.entry_id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $current_entries[intval($row['theme_id'])][$row['field_name']][] = $row;
}

$sql = "
    SELECT
        a.*,
        t.theme_name
    FROM actions a
    LEFT JOIN themes t
        ON a.theme_id = t.theme_id
    WHERE a.session_id = ?
      AND a.status <> '完了'
      AND a.is_deleted = 0
    ORDER BY
        CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END,
        a.due_date ASC,
        a.action_id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$open_actions = $stmt->get_result();

$past_data = [];

foreach ($past_sessions as $ps) {
    $psid = intval($ps['session_id']);

    $sql = "
        SELECT
            p.theme_id,
            p.comment AS pre_comment,
            m.person_comment,
            m.interviewer_comment,
            m.insight,
            m.decision_text,
            m.next_action,
            m.due_date,
            m.follow_required
        FROM pre_survey_themes p
        LEFT JOIN meeting_notes m
            ON m.session_id = p.session_id
           AND m.theme_id = p.theme_id
        WHERE p.session_id = ?
          AND p.theme_id IS NOT NULL

        UNION

        SELECT
            m.theme_id,
            NULL AS pre_comment,
            m.person_comment,
            m.interviewer_comment,
            m.insight,
            m.decision_text,
            m.next_action,
            m.due_date,
            m.follow_required
        FROM meeting_notes m
        WHERE m.session_id = ?
          AND m.theme_id IS NOT NULL
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $psid, $psid);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $past_data[$psid][intval($row['theme_id'])] = $row;
    }

    $sql = "
        SELECT
            e.theme_id,
            e.field_name,
            e.note_text,
            e.created_at,
            u.name AS created_by_name
        FROM meeting_note_entries e
        LEFT JOIN users u
            ON e.created_by = u.user_id
        WHERE e.session_id = ?
          AND e.is_deleted = 0
        ORDER BY e.created_at, e.entry_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $psid);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $line = '[' . $row['created_at'] . ' / ' . ($row['created_by_name'] ?? '') . "]\n" . $row['note_text'];
        appendPastValue($past_data, $psid, intval($row['theme_id']), $row['field_name'], $line);
    }
}

function renderThemeRows($themes, $past_sessions, $current_notes, $current_entries, $past_data, $pre_survey_data, $session_id) {
    $labels = fieldLabels();

    foreach ($themes as $theme_id => $theme) {
        $note = $current_notes[$theme_id] ?? [];
        $entries = $current_entries[$theme_id] ?? [];
        $pre = $pre_survey_data[$theme_id] ?? [];

        echo '<tr id="theme-' . h($theme_id) . '">';

        echo '<th class="theme-col">';
        echo '<div class="theme-name">' . h($theme['theme_name']) . '</div>';

        echo '<div class="pre-box">';
        echo '<div class="pre-title">事前アンケート</div>';

        if (!empty($pre)) {
            echo '<div><span class="label">求める対応</span><br>';
            echo h($pre['support_type_name'] ?? '未選択');
            echo '</div>';

            echo '<div class="pre-comment">';
            echo '<span class="label">本人コメント</span><br>';
            echo !empty($pre['pre_comment']) ? nl2br(h($pre['pre_comment'])) : '<span class="empty">記入なし</span>';
            echo '</div>';
        } else {
            echo '<span class="empty">今回選択なし</span>';
        }

        echo '</div>';
        echo '</th>';

        echo '<td class="current-col">';
        echo '<form method="post" action="meeting_save.php" class="section-form">';
        echo '<input type="hidden" name="session_id" value="' . h($session_id) . '">';
        echo '<input type="hidden" name="theme_id" value="' . h($theme_id) . '">';

        foreach ($labels as $field => $label) {
            echo '<div class="note-field">';
            echo '<b>' . h($label) . '</b>';
            renderLegacyNote($note, $field);
            renderEntryList($entries, $field);

            if ($field === 'due_date') {
                echo '<input type="date" name="' . h($field) . '">';
            } elseif ($field === 'follow_required') {
                echo '<label><input type="checkbox" name="' . h($field) . '" value="1"> フォロー要として追記</label>';
            } else {
                echo '<textarea name="' . h($field) . '" placeholder="追記する内容を入力"></textarea>';
            }

            echo '</div>';
        }

        echo '<button type="submit" class="section-save">このセクションを更新</button>';
        echo '</form>';
        echo '</td>';

        foreach ($past_sessions as $ps) {
            $psid = intval($ps['session_id']);
            echo '<td>';
            $row = $past_data[$psid][$theme_id] ?? [];
            renderPastCell($row);
            echo '</td>';
        }

        echo '</tr>';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>当日面談フォーム</title>
<style>
body {
    font-family: Arial, sans-serif;
    line-height: 1.5;
}

h2, h3 {
    margin-bottom: 8px;
}

.table-wrap {
    overflow-x: auto;
    border: 1px solid #ccc;
    margin-bottom: 28px;
}

.meeting-table {
    border-collapse: collapse;
    min-width: 1200px;
}

.meeting-table th,
.meeting-table td {
    border: 1px solid #ccc;
    padding: 8px;
    vertical-align: top;
    min-width: 300px;
}

.meeting-table thead th {
    position: sticky;
    top: 0;
    background: #eee;
    z-index: 4;
}

.theme-col {
    position: sticky;
    left: 0;
    min-width: 260px !important;
    width: 260px;
    background: #f7f7f7;
    z-index: 3;
}

.current-col {
    position: sticky;
    left: 260px;
    min-width: 330px;
    width: 330px;
    background: #f8fbff;
    z-index: 2;
}

thead .theme-col {
    z-index: 6;
    background: #eee;
}

thead .current-col {
    z-index: 5;
    background: #eee;
}

tbody .theme-col {
    background: #f7f7f7;
}

tbody .current-col {
    background: #f8fbff;
}

.theme-name {
    font-weight: bold;
    font-size: 15px;
    margin-bottom: 8px;
}

.pre-box {
    margin-top: 8px;
    padding: 8px;
    background: #fff7ed;
    border-left: 4px solid #f97316;
    font-size: 13px;
    line-height: 1.5;
    font-weight: normal;
}

.pre-title {
    font-weight: bold;
    color: #9a3412;
    margin-bottom: 6px;
}

.pre-comment {
    margin-top: 8px;
}

.label {
    font-weight: bold;
    color: #475569;
}

.note-field {
    margin-bottom: 12px;
}

.entry-list,
.legacy-note {
    margin: 4px 0 6px;
}

.entry-item,
.legacy-note {
    border-left: 3px solid #94a3b8;
    background: #fff;
    padding: 6px 8px;
    margin-top: 6px;
    font-size: 13px;
}

.entry-meta {
    color: #64748b;
    font-size: 12px;
    margin-bottom: 3px;
}

textarea {
    width: 96%;
    height: 55px;
}

input[type="date"] {
    width: 96%;
}

.section-save {
    margin-top: 6px;
    padding: 6px 14px;
    cursor: pointer;
}

.empty {
    color: #777;
    font-weight: normal;
}

.group-title {
    background: #333;
    color: #fff;
    padding: 8px;
    margin-top: 24px;
}

.action-table {
    border-collapse: collapse;
    margin: 12px 0 20px;
    width: 100%;
}

.action-table th,
.action-table td {
    border: 1px solid #ccc;
    padding: 8px;
    vertical-align: top;
}

.action-table th {
    background: #eee;
}
</style>
</head>
<body>

<h2>当日面談フォーム</h2>

<p>
対象者：<?= h($session['member_name']) ?>　
面談日：<?= h($session['session_date']) ?>　
対象月：<?= h($session['target_month']) ?>
</p>

<p>
<a href="session_detail.php?session_id=<?= h($session_id) ?>">事前アンケートへ戻る</a> |
<a href="dashboard.php">ダッシュボードへ戻る</a>
</p>

<h3>未完了アクション</h3>
<table class="action-table">
<tr>
    <th>テーマ</th>
    <th>アクション</th>
    <th>期限</th>
    <th>状態</th>
    <th>操作</th>
</tr>
<?php if ($open_actions->num_rows === 0): ?>
<tr>
    <td colspan="5">未完了アクションはありません</td>
</tr>
<?php else: ?>
    <?php while ($action = $open_actions->fetch_assoc()): ?>
    <tr>
        <td><?= h($action['theme_name'] ?? '') ?></td>
        <td><?= nl2br(h($action['content'])) ?></td>
        <td><?= h($action['due_date'] ?? '') ?></td>
        <td><?= h($action['status']) ?></td>
        <td>
            <form method="post" action="action_done.php">
                <input type="hidden" name="session_id" value="<?= h($session_id) ?>">
                <input type="hidden" name="action_id" value="<?= h($action['action_id']) ?>">
                <button type="submit">完了</button>
            </form>
        </td>
    </tr>
    <?php endwhile; ?>
<?php endif; ?>
</table>

<div class="group-title">今回の主要テーマ</div>
<div class="table-wrap">
<table class="meeting-table">
<thead>
<tr>
    <th class="theme-col">テーマ / 事前アンケート</th>
    <th class="current-col">今回入力</th>
    <?php foreach ($past_sessions as $ps): ?>
        <th>
            <?= h($ps['target_month']) ?><br>
            <?= h($ps['session_date']) ?>
        </th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php renderThemeRows($upper_themes, $past_sessions, $current_notes, $current_entries, $past_data, $pre_survey_data, $session_id); ?>
</tbody>
</table>
</div>

<div class="group-title">その他テーマ</div>
<div class="table-wrap">
<table class="meeting-table">
<thead>
<tr>
    <th class="theme-col">テーマ / 事前アンケート</th>
    <th class="current-col">今回入力</th>
    <?php foreach ($past_sessions as $ps): ?>
        <th>
            <?= h($ps['target_month']) ?><br>
            <?= h($ps['session_date']) ?>
        </th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php renderThemeRows($lower_themes, $past_sessions, $current_notes, $current_entries, $past_data, $pre_survey_data, $session_id); ?>
</tbody>
</table>
</div>

</body>
</html>
