<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

$user = eol_require_login();
$pdo = eol_pdo();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_batch') {
    if (!eol_user_can_edit()) {
        http_response_code(403);
        exit('権限がありません。');
    }

    $batchId = (int) ($_POST['batch_id'] ?? 0);
    $voidReason = trim((string) ($_POST['void_reason'] ?? ''));
    if ($batchId > 0) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE eol_batches
                 SET voided_at = NOW(), voided_by_login_id = :login_id, voided_by_name = :name, void_reason = :void_reason
                 WHERE id = :id AND voided_at IS NULL'
            );
            $stmt->execute([
                'login_id' => $user['login_id'],
                'name' => $user['name'],
                'void_reason' => $voidReason,
                'id' => $batchId,
            ]);

            $detailStmt = $pdo->prepare(
                'UPDATE eol_detail
                 SET voided_at = NOW(), voided_by_login_id = :login_id, voided_by_name = :name
                 WHERE batch_id = :batch_id AND voided_at IS NULL'
            );
            $detailStmt->execute([
                'login_id' => $user['login_id'],
                'name' => $user['name'],
                'batch_id' => $batchId,
            ]);

            $pdo->commit();
            eol_audit_log('bulk_cancel', 'batch', (string) $batchId, '一括登録を取消', ['reason' => $voidReason]);
            $message = "一括ID {$batchId} を取消しました。";
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

$stmt = $pdo->query(
    'SELECT id, product_group, reason, kubun, assignee, due_date, status, target_count, duplicate_count, created_by_name, created_at, voided_at, voided_by_name, void_reason, reference_url, attachment_note
     FROM eol_batches
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$batches = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>一括履歴</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:16px;color:#111827}
  a{color:#2563eb}
  table{border-collapse:collapse;width:100%;margin-top:12px}
  th,td{border:1px solid #ddd;padding:6px 8px;font-size:12px;vertical-align:top}
  th{background:#f3f4f6}
  input,button{padding:5px 7px}
  .nav{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
  .badge{display:inline-block;padding:2px 6px;border-radius:10px;color:#fff;font-weight:bold;font-size:12px}
  .small{font-size:12px;color:#6b7280}
  .void{background:#f9fafb;color:#6b7280}
  .ok{color:#166534}
</style>
</head>
<body>
<div class="nav">
  <a href="index.php">製品一覧</a>
  <a href="bulk.php">一括登録</a>
  <span class="small"><?= h($user['name']) ?>（<?= h($user['login_id']) ?>）</span>
</div>

<h2>一括履歴</h2>
<?php if (isset($_GET['created'])): ?><p class="ok">一括ID <?= h($_GET['created']) ?> を登録しました。</p><?php endif; ?>
<?php if ($message !== ''): ?><p class="ok"><?= h($message) ?></p><?php endif; ?>

<table>
  <tr>
    <th>ID</th>
    <th>製品群 / 理由</th>
    <th>区分</th>
    <th>内容</th>
    <th>件数</th>
    <th>登録者</th>
    <th>登録日</th>
    <th>取消</th>
  </tr>
  <?php foreach ($batches as $batch): ?>
    <?php $voided = $batch['voided_at'] !== null && $batch['voided_at'] !== ''; ?>
    <tr class="<?= $voided ? 'void' : '' ?>">
      <td><?= h($batch['id']) ?></td>
      <td><?= h($batch['product_group']) ?><br><span class="small"><?= h($batch['reason']) ?></span></td>
      <td><?= h($batch['kubun']) ?></td>
      <td>
        <span class="badge" style="background:<?= h(eol_status_color((string) $batch['status'])) ?>"><?= h($batch['status']) ?></span>
        <div><?= h($batch['assignee']) ?> / <?= h(eol_format_date($batch['due_date'])) ?></div>
        <?php if (!empty($batch['reference_url'])): ?><div><a href="<?= h($batch['reference_url']) ?>" target="_blank" rel="noopener">参照URL</a></div><?php endif; ?>
        <?php if (!empty($batch['attachment_note'])): ?><div class="small"><?= nl2br(h($batch['attachment_note'])) ?></div><?php endif; ?>
      </td>
      <td><?= h($batch['target_count']) ?>件<div class="small">既存 <?= h($batch['duplicate_count']) ?>件</div></td>
      <td><?= h($batch['created_by_name']) ?></td>
      <td><?= h(eol_format_datetime($batch['created_at'])) ?></td>
      <td>
        <?php if ($voided): ?>
          取消済<br><span class="small"><?= h($batch['voided_by_name']) ?> <?= h(eol_format_datetime($batch['voided_at'])) ?><br><?= h($batch['void_reason']) ?></span>
        <?php elseif (eol_user_can_edit()): ?>
          <form method="post" onsubmit="return confirm('この一括登録を取消しますか？');">
            <input type="hidden" name="action" value="void_batch">
            <input type="hidden" name="batch_id" value="<?= h($batch['id']) ?>">
            <input type="text" name="void_reason" placeholder="取消理由">
            <button type="submit">取消</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
