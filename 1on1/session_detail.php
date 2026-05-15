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

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function support_select($name, $support_types, $selected_value) {
    $html = '<select name="' . h($name) . '">';
    $html .= '<option value="">選択してください</option>';

    foreach ($support_types as $st) {
        $selected = ((string)$selected_value === (string)$st['support_type_id']) ? ' selected' : '';
        $html .= '<option value="' . h($st['support_type_id']) . '"' . $selected . '>';
        $html .= h($st['support_type_name']);
        $html .= '</option>';
    }

    $html .= '</select>';
    return $html;
}

function getThemeHistory($conn, $user_id, $theme_id, $current_session_id) {
    $sql = "
        SELECT 
            s.session_id,
            s.session_date,
            s.target_month,
            p.comment,
            m.person_comment,
            m.interviewer_comment,
            m.insight,
            m.next_action,
            m.due_date
        FROM pre_survey_themes p
        INNER JOIN oneonone_sessions s
            ON p.session_id = s.session_id
        LEFT JOIN meeting_notes m
            ON m.session_id = s.session_id
           AND m.theme_id = p.theme_id
        WHERE s.user_id = ?
          AND p.theme_id = ?
          AND s.session_id <> ?
          AND s.is_deleted = 0
        ORDER BY s.session_date DESC, s.session_id DESC
        LIMIT 3
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $user_id, $theme_id, $current_session_id);
    $stmt->execute();

    return $stmt->get_result();
}

function getCustomThemeHistory($conn, $user_id, $current_session_id) {
    $sql = "
        SELECT 
            s.session_id,
            s.session_date,
            s.target_month,
            p.custom_theme_name,
            p.comment
        FROM pre_survey_themes p
        INNER JOIN oneonone_sessions s
            ON p.session_id = s.session_id
        WHERE s.user_id = ?
          AND p.custom_theme_name IS NOT NULL
          AND p.custom_theme_name <> ''
          AND s.session_id <> ?
          AND s.is_deleted = 0
        ORDER BY s.session_date DESC, s.session_id DESC
        LIMIT 3
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $current_session_id);
    $stmt->execute();

    return $stmt->get_result();
}

function renderThemeHistory($history) {
    if ($history->num_rows === 0) {
        echo '<div class="history-empty">過去履歴なし</div>';
        return;
    }

    while ($hrow = $history->fetch_assoc()) {
        echo '<div class="history-item">';
        echo '<div><b>' . h($hrow['target_month']) . '</b>（' . h($hrow['session_date']) . '）</div>';

        if (!empty($hrow['comment'])) {
            echo '<div><b>事前コメント：</b><br>' . nl2br(h($hrow['comment'])) . '</div>';
        }

        if (!empty($hrow['person_comment'])) {
            echo '<div><b>本人コメント：</b><br>' . nl2br(h($hrow['person_comment'])) . '</div>';
        }

        if (!empty($hrow['interviewer_comment'])) {
            echo '<div><b>面談者コメント：</b><br>' . nl2br(h($hrow['interviewer_comment'])) . '</div>';
        }

        if (!empty($hrow['insight'])) {
            echo '<div><b>気づき：</b><br>' . nl2br(h($hrow['insight'])) . '</div>';
        }

        if (!empty($hrow['next_action'])) {
            echo '<div><b>前回アクション：</b><br>' . nl2br(h($hrow['next_action'])) . '</div>';
        }

        if (!empty($hrow['due_date'])) {
            echo '<div><b>期限：</b>' . h($hrow['due_date']) . '</div>';
        }

        echo '<div><a href="session_detail.php?session_id=' . h($hrow['session_id']) . '">詳細を見る</a></div>';
        echo '</div>';
    }
}

// セッション取得
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
$result = $stmt->get_result();
$session = $result->fetch_assoc();

if (!$session) {
    exit('閲覧権限がない、またはデータが存在しません');
}

// 面談者候補
$sql = "
    SELECT user_id, name
    FROM users
    WHERE can_be_mentor = 1
      AND is_active = 1
    ORDER BY user_id
";
$mentor_result = $conn->query($sql);

// テーマ取得
$sql = "
    SELECT theme_id, theme_name, is_required
    FROM themes
    WHERE is_active = 1
    ORDER BY sort_order, theme_id
";
$theme_result = $conn->query($sql);

$required_themes = [];
$optional_themes = [];

while ($row = $theme_result->fetch_assoc()) {
    if (intval($row['is_required']) === 1) {
        $required_themes[] = $row;
    } else {
        $optional_themes[] = $row;
    }
}

// 対応種別
$sql = "
    SELECT support_type_id, support_type_name
    FROM support_types
    WHERE is_active = 1
    ORDER BY sort_order, support_type_id
";
$support_result = $conn->query($sql);

$support_types = [];
while ($row = $support_result->fetch_assoc()) {
    $support_types[] = $row;
}

// 既存事前アンケート
$sql = "
    SELECT *
    FROM pre_survey_themes
    WHERE session_id = ?
    ORDER BY sort_order, id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$pre_result = $stmt->get_result();

$pre_by_theme = [];
$custom_pre = [
    'custom_theme_name' => '',
    'support_type_id' => '',
    'comment' => ''
];

while ($row = $pre_result->fetch_assoc()) {
    if (!empty($row['custom_theme_name'])) {
        $custom_pre = $row;
    } elseif (!empty($row['theme_id'])) {
        $pre_by_theme[intval($row['theme_id'])] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>1on1詳細</title>
<style>
body {
    font-family: Arial, sans-serif;
    line-height: 1.6;
}
.section {
    border: 1px solid #ccc;
    padding: 14px;
    margin-bottom: 18px;
}
.theme-box {
    border: 1px solid #ddd;
    padding: 10px;
    margin: 10px 0;
}
.theme-detail {
    margin-top: 10px;
    padding: 10px;
    background: #fafafa;
}
.history-box {
    margin-top: 10px;
    padding: 10px;
    background: #f3f3f3;
    border: 1px solid #ddd;
}
.history-item {
    border-top: 1px solid #ccc;
    padding-top: 8px;
    margin-top: 8px;
}
.history-empty {
    color: #666;
}
textarea {
    width: 98%;
    height: 80px;
}
.required-label {
    font-weight: bold;
}
</style>

<script>
function toggleOptionalTheme(themeId) {
    const check = document.querySelector('input[value="' + themeId + '"].optional-theme-check');
    const detail = document.getElementById('optional_detail_' + themeId);

    if (!check || !detail) return;

    if (check.checked) {
        detail.style.display = 'block';
    } else {
        detail.style.display = 'none';
    }
}

function checkOptionalLimit(clickedCheckbox) {
    const checks = document.querySelectorAll('.optional-theme-check');
    let count = 0;

    checks.forEach(c => {
        if (c.checked) count++;
    });

    if (count > 3) {
        alert('任意テーマは最大3つまでです');
        clickedCheckbox.checked = false;

        const themeId = clickedCheckbox.value;
        const detail = document.getElementById('optional_detail_' + themeId);
        if (detail) {
            detail.style.display = 'none';
        }
    }
}
</script>
</head>
<body>

<h2>1on1詳細</h2>

<p>
ログイン中：<?= h($_SESSION['name'] ?? '') ?>　
<a href="dashboard.php">ダッシュボードへ戻る</a>
</p>

<form method="post" action="session_detail_save.php">
<input type="hidden" name="session_id" value="<?= h($session_id) ?>">

<div class="section">
    <h3>基本情報</h3>

    <p>
        対象者：<?= h($session['member_name']) ?>
    </p>

    <p>
        面談希望日（必須）<br>
        <input type="date" name="session_date"
               value="<?= h($session['session_date'] ?? '') ?>"
               required>
    </p>

    <p>
        面談者<br>
        <select name="mentor_id">
            <option value="">選択してください</option>
            <?php while($row = $mentor_result->fetch_assoc()): ?>
                <option value="<?= h($row['user_id']) ?>"
                    <?= ((string)($session['mentor_id'] ?? '') === (string)$row['user_id']) ? 'selected' : '' ?>>
                    <?= h($row['name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
    </p>

    <p>
        対象月：<?= h($session['target_month'] ?: '未設定') ?><br>
        ステータス：<?= h($session['status']) ?>
    </p>
</div>

<div class="section">
    <h3>事前アンケート</h3>

    <h4>必須テーマ</h4>

    <?php foreach ($required_themes as $theme): ?>
        <?php
            $theme_id = intval($theme['theme_id']);
            $saved = $pre_by_theme[$theme_id] ?? [];
            $support_id = $saved['support_type_id'] ?? '';
            $comment = $saved['comment'] ?? '';
            $history = getThemeHistory($conn, $session['user_id'], $theme_id, $session_id);
        ?>

        <div class="theme-box">
            <div class="required-label">
                <?= h($theme['theme_name']) ?>
                <input type="hidden" name="required_theme_ids[]" value="<?= h($theme_id) ?>">
            </div>

            <div class="theme-detail">
                <p>
                    面談者に求める対応<br>
                    <?= support_select("support_type_required[" . $theme_id . "]", $support_types, $support_id) ?>
                </p>

                <p>
                    コメント<br>
                    <textarea name="comment_required[<?= h($theme_id) ?>]"><?= h($comment) ?></textarea>
                </p>

                <div class="history-box">
                    <b>過去履歴</b>
                    <?php renderThemeHistory($history); ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <h4>任意テーマ（最大3つ）</h4>

    <?php foreach ($optional_themes as $theme): ?>
        <?php
            $theme_id = intval($theme['theme_id']);
            $saved = $pre_by_theme[$theme_id] ?? null;
            $checked = $saved ? 'checked' : '';
            $display = $saved ? 'block' : 'none';
            $support_id = $saved['support_type_id'] ?? '';
            $comment = $saved['comment'] ?? '';
            $history = getThemeHistory($conn, $session['user_id'], $theme_id, $session_id);
        ?>

        <div class="theme-box">
            <label>
                <input type="checkbox"
                       class="optional-theme-check"
                       name="optional_theme_ids[]"
                       value="<?= h($theme_id) ?>"
                       onclick="toggleOptionalTheme(<?= h($theme_id) ?>); checkOptionalLimit(this);"
                       <?= $checked ?>>
                <?= h($theme['theme_name']) ?>
            </label>

            <div id="optional_detail_<?= h($theme_id) ?>" class="theme-detail" style="display: <?= h($display) ?>;">
                <p>
                    面談者に求める対応<br>
                    <?= support_select("support_type_optional[" . $theme_id . "]", $support_types, $support_id) ?>
                </p>

                <p>
                    コメント<br>
                    <textarea name="comment_optional[<?= h($theme_id) ?>]"><?= h($comment) ?></textarea>
                </p>

                <div class="history-box">
                    <b>過去履歴</b>
                    <?php renderThemeHistory($history); ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <h4>自由テーマ</h4>

    <?php
        $custom_history = getCustomThemeHistory($conn, $session['user_id'], $session_id);
    ?>

    <div class="theme-box">
        <div class="theme-detail">
            <p>
                自由テーマ名<br>
                <input type="text" name="custom_theme_name" value="<?= h($custom_pre['custom_theme_name'] ?? '') ?>">
            </p>

            <p>
                面談者に求める対応<br>
                <?= support_select("support_type_custom", $support_types, $custom_pre['support_type_id'] ?? '') ?>
            </p>

            <p>
                コメント<br>
                <textarea name="comment_custom"><?= h($custom_pre['comment'] ?? '') ?></textarea>
            </p>

            <div class="history-box">
                <b>過去の自由テーマ</b>

                <?php if ($custom_history->num_rows === 0): ?>
                    <div class="history-empty">過去履歴なし</div>
                <?php else: ?>
                    <?php while($hrow = $custom_history->fetch_assoc()): ?>
                        <div class="history-item">
                            <div><b><?= h($hrow['custom_theme_name']) ?></b></div>
                            <div><?= h($hrow['target_month']) ?>（<?= h($hrow['session_date']) ?>）</div>
                            <div><?= nl2br(h($hrow['comment'])) ?></div>
                            <div>
                                <a href="session_detail.php?session_id=<?= h($hrow['session_id']) ?>">詳細を見る</a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<button type="submit">保存</button>
<a href="meeting_form.php?session_id=<?= h($session_id) ?>">当日面談フォームへ</a>

</form>

</body>
</html>
