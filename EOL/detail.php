<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

$user = eol_require_login();
$pdo = eol_pdo();
$product = trim((string) ($_GET['product'] ?? ''));
$kubun = trim((string) ($_GET['kubun'] ?? ''));

if ($product === '' || $kubun === '') {
    http_response_code(400);
    exit('product and kubun are required.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!eol_user_can_edit()) {
        http_response_code(403);
        exit('権限がありません。');
    }

    $dueDate = trim((string) ($_POST['limit'] ?? ''));
    $stmt = $pdo->prepare(
        'INSERT INTO eol_detail
         (product_no, kubun, assignee, due_date, comment, detail, status, created_at, updated_at, created_by_login_id, created_by_name, reference_url, attachment_note)
         VALUES (:product_no, :kubun, :assignee, :due_date, :comment, :detail, :status, NOW(), NOW(), :created_by_login_id, :created_by_name, :reference_url, :attachment_note)'
    );
    $stmt->execute([
        'product_no' => $product,
        'kubun' => $kubun,
        'assignee' => trim((string) ($_POST['tanto'] ?? $user['name'])),
        'due_date' => $dueDate === '' ? null : $dueDate,
        'comment' => trim((string) ($_POST['comment'] ?? '')),
        'detail' => trim((string) ($_POST['detail'] ?? '')),
        'status' => trim((string) ($_POST['status'] ?? '未着手')),
        'created_by_login_id' => $user['login_id'],
        'created_by_name' => $user['name'],
        'reference_url' => trim((string) ($_POST['reference_url'] ?? '')),
        'attachment_note' => trim((string) ($_POST['attachment_note'] ?? '')),
    ]);

    eol_audit_log('detail_create', 'product', $product, "{$product} / {$kubun} に個別履歴を追加", ['kubun' => $kubun]);
    header('Location: detail.php?product=' . urlencode($product) . '&kubun=' . urlencode($kubun));
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, batch_id, assignee, due_date, comment, detail, status, created_at, updated_at, created_by_name, reference_url, attachment_note
     FROM eol_detail
     WHERE product_no = :product_no AND kubun = :kubun AND voided_at IS NULL
     ORDER BY updated_at DESC, id DESC'
);
$stmt->execute(['product_no' => $product, 'kubun' => $kubun]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>終売案内 詳細</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:16px;color:#111827}
  label{display:block;margin-top:6px;font-size:13px}
  input[type=text],input[type=date],input[type=url],select,textarea{width:460px;max-width:100%;box-sizing:border-box}
  input,select,textarea{padding:5px 7px}
  textarea{height:100px}
  table{border-collapse:collapse;width:100%;margin-top:16px}
  th,td{border:1px solid #ddd;padding:6px 8px;font-size:12px;vertical-align:top}
  th{background:#f3f4f6}
  .badge{display:inline-block;padding:2px 6px;border-radius:10px;color:#fff;font-weight:bold;font-size:12px}
  .actions,.nav{margin-top:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .small{font-size:12px;color:#6b7280}
</style>
</head>
<body>
<div class="nav">
  <a href="index.php">一覧へ戻る</a>
  <a href="target_add.php">一括対象追加</a>
  <a href="bulk_status.php">ステータス一括更新</a>
  <span class="small"><?= h($user['name']) ?>（<?= h($user['login_id']) ?>）</span>
</div>

<h2>詳細登録：<?= h($product) ?> ／ <?= h($kubun) ?></h2>
<?php if (eol_user_can_edit()): ?>
<form method="post" action="detail.php?product=<?= urlencode($product) ?>&kubun=<?= urlencode($kubun) ?>">
  <label>担当者<input type="text" name="tanto" value="<?= h($user['name']) ?>"></label>
  <label>期限<input type="date" name="limit"></label>
  <label>ステータス
    <select name="status">
      <?php foreach (eol_status_options() as $status): ?>
        <option value="<?= h($status) ?>"><?= h($status) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>コメント<input type="text" name="comment"></label>
  <label>詳細<textarea name="detail"></textarea></label>
  <label>参照URL<input type="url" name="reference_url"></label>
  <label>添付メモ<textarea name="attachment_note"></textarea></label>
  <div class="actions">
    <button type="submit">登録</button>
  </div>
</form>
<?php endif; ?>

<table>
  <tr>
    <th style="width:60px">ID</th>
    <th style="width:80px">一括ID</th>
    <th style="width:90px">担当者</th>
    <th style="width:110px">期限</th>
    <th style="width:120px">ステータス</th>
    <th>コメント</th>
    <th>詳細</th>
    <th style="width:110px">登録者</th>
    <th style="width:130px">更新日</th>
  </tr>
  <?php foreach ($history as $row): ?>
    <?php $status = (string) ($row['status'] ?? '未着手'); ?>
    <tr>
      <td><?= h($row['id']) ?></td>
      <td><?= h($row['batch_id']) ?></td>
      <td><?= h($row['assignee']) ?></td>
      <td><?= h(eol_format_date($row['due_date'])) ?></td>
      <td><span class="badge" style="background:<?= h(eol_status_color($status)) ?>"><?= h($status) ?></span></td>
      <td><?= nl2br(h($row['comment'])) ?></td>
      <td><?= nl2br(h($row['detail'])) ?>
        <?php if (!empty($row['reference_url'])): ?><div><a href="<?= h($row['reference_url']) ?>" target="_blank" rel="noopener">参照URL</a></div><?php endif; ?>
        <?php if (!empty($row['attachment_note'])): ?><div class="small"><?= nl2br(h($row['attachment_note'])) ?></div><?php endif; ?>
      </td>
      <td><?= h($row['created_by_name']) ?></td>
      <td><?= h(eol_format_datetime($row['updated_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
