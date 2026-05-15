<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

$user = eol_require_login();
if (!eol_user_can_edit()) {
    http_response_code(403);
    exit('権限がありません。');
}

$pdo = eol_pdo();
$kubunList = eol_kubun_list();
$view = (string) ($_GET['view'] ?? 'all');
$productGroup = trim((string) ($_GET['product_group'] ?? ''));
$reason = trim((string) ($_GET['reason'] ?? ''));
$requestNo = trim((string) ($_GET['request_no'] ?? ''));
$registrant = trim((string) ($_GET['registrant'] ?? ''));
$dueFrom = trim((string) ($_GET['due_from'] ?? ''));
$dueTo = trim((string) ($_GET['due_to'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$message = '';
$error = '';

function eol_bulk_status_products(PDO $pdo, array $filters): array
{
    $manualJoin = 'LEFT JOIN eol_group_members gm_add ON gm_add.product_no = p.plu_cd AND gm_add.action_type = "add"';
    $excludeJoin = 'LEFT JOIN eol_group_members gm_ex ON gm_ex.product_no = p.plu_cd AND gm_ex.action_type = "exclude"';
    $where = ['p.ins_datetime >= :from_date'];
    $params = ['from_date' => '2025-01-01 00:00:00'];

    if ($filters['product_group'] !== '') {
        $manualJoin .= ' AND gm_add.product_group LIKE :gm_add_product_group';
        $excludeJoin .= ' AND gm_ex.product_group LIKE :gm_ex_product_group';
        $where[] = '(EXISTS (SELECT 1 FROM ttimps210002 mx WHERE mx.`T$SPLI` = p.plu_cd AND mx.`T$PLNI` LIKE :product_group) OR gm_add.id IS NOT NULL)';
        $params['product_group'] = '%' . $filters['product_group'] . '%';
        $params['gm_add_product_group'] = '%' . $filters['product_group'] . '%';
        $params['gm_ex_product_group'] = '%' . $filters['product_group'] . '%';
    }
    if ($filters['reason'] !== '') {
        $manualJoin .= ' AND gm_add.reason LIKE :gm_add_reason';
        $excludeJoin .= ' AND gm_ex.reason LIKE :gm_ex_reason';
        $where[] = '(p.reason LIKE :reason OR gm_add.id IS NOT NULL)';
        $params['reason'] = '%' . $filters['reason'] . '%';
        $params['gm_add_reason'] = '%' . $filters['reason'] . '%';
        $params['gm_ex_reason'] = '%' . $filters['reason'] . '%';
    }
    if ($filters['product_group'] !== '' || $filters['reason'] !== '') {
        $where[] = 'gm_ex.id IS NULL';
    }
    if ($filters['request_no'] !== '') {
        $where[] = 'p.request_no LIKE :request_no';
        $params['request_no'] = '%' . $filters['request_no'] . '%';
    }
    if ($filters['registrant'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM eol_detail dx WHERE dx.product_no = p.plu_cd AND dx.voided_at IS NULL AND (dx.created_by_login_id LIKE :registrant OR dx.created_by_name LIKE :registrant))';
        $params['registrant'] = '%' . $filters['registrant'] . '%';
    }
    if ($filters['q'] !== '') {
        $where[] = '(p.plu_cd LIKE :q OR p.plu_name LIKE :q OR p.reason LIKE :q OR gm_add.reason LIKE :q OR p.request_no LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }

    $sql = '
SELECT p.plu_cd AS product_no, p.plu_name AS product_name, COALESCE(gm_add.reason, p.reason) AS reason,
       p.request_no, p.ins_datetime AS registered_at,
       GROUP_CONCAT(DISTINCT m.`T$PLNI` ORDER BY m.`T$PLNI` SEPARATOR ", ") AS product_groups
FROM eol_product_status p
LEFT JOIN ttimps210002 m ON m.`T$SPLI` = p.plu_cd
' . $manualJoin . '
' . $excludeJoin . '
WHERE ' . implode(' AND ', $where) . '
GROUP BY p.plu_cd, p.plu_name, COALESCE(gm_add.reason, p.reason), p.request_no, p.ins_datetime
ORDER BY p.ins_datetime DESC, p.plu_cd
LIMIT 3000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$filters = [
    'product_group' => $productGroup,
    'reason' => $reason,
    'request_no' => $requestNo,
    'registrant' => $registrant,
    'q' => $q,
];
$products = eol_bulk_status_products($pdo, $filters);
$latestByProduct = eol_fetch_latest_map($pdo, array_values(array_unique(array_column($products, 'product_no'))));

if ($dueFrom !== '' || $dueTo !== '') {
    $products = array_values(array_filter($products, function (array $product) use ($latestByProduct, $kubunList, $dueFrom, $dueTo): bool {
        foreach ($kubunList as $kubun) {
            $dueDate = $latestByProduct[$product['product_no']][$kubun]['due_date'] ?? null;
            if ($dueDate === null || $dueDate === '') {
                continue;
            }
            $date = substr((string) $dueDate, 0, 10);
            if ($dueFrom !== '' && $date < $dueFrom) {
                continue;
            }
            if ($dueTo !== '' && $date > $dueTo) {
                continue;
            }
            return true;
        }
        return false;
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kubun = trim((string) ($_POST['kubun'] ?? ''));
    $dueDate = trim((string) ($_POST['limit'] ?? ''));
    if ($kubun === '' || $products === []) {
        $error = '区分を選択してください。';
    } else {
        $latestForKubun = eol_fetch_latest_map($pdo, array_values(array_unique(array_column($products, 'product_no'))), $kubun);
        $duplicatePolicy = (string) ($_POST['duplicate_policy'] ?? 'add_all');
        $insertTargets = [];
        $duplicateCount = 0;
        foreach ($products as $product) {
            $currentStatus = $latestForKubun[$product['product_no']][$kubun]['status'] ?? null;
            if ($currentStatus !== null) {
                $duplicateCount++;
            }
            if ($currentStatus !== null && $duplicatePolicy === 'skip_existing') {
                continue;
            }
            if ($currentStatus === '完了' && $duplicatePolicy === 'skip_done') {
                continue;
            }
            $insertTargets[] = $product;
        }

        if ($insertTargets === []) {
            $error = '登録対象がありません。';
        } else {
            $pdo->beginTransaction();
            try {
                $batchStmt = $pdo->prepare(
                    'INSERT INTO eol_batches
                     (product_group, reason, kubun, assignee, due_date, status, comment, detail, duplicate_policy, target_count, duplicate_count, reference_url, attachment_note, created_by_login_id, created_by_name, created_at)
                     VALUES (:product_group, :reason, :kubun, :assignee, :due_date, :status, :comment, :detail, :duplicate_policy, :target_count, :duplicate_count, :reference_url, :attachment_note, :login_id, :name, NOW())'
                );
                $batchStmt->execute([
                    'product_group' => $productGroup,
                    'reason' => $reason,
                    'kubun' => $kubun,
                    'assignee' => trim((string) ($_POST['tanto'] ?? $user['name'])),
                    'due_date' => $dueDate === '' ? null : $dueDate,
                    'status' => trim((string) ($_POST['status'] ?? '未着手')),
                    'comment' => trim((string) ($_POST['comment'] ?? '')),
                    'detail' => trim((string) ($_POST['detail'] ?? '')),
                    'duplicate_policy' => $duplicatePolicy,
                    'target_count' => count($insertTargets),
                    'duplicate_count' => $duplicateCount,
                    'reference_url' => trim((string) ($_POST['reference_url'] ?? '')),
                    'attachment_note' => trim((string) ($_POST['attachment_note'] ?? '')),
                    'login_id' => $user['login_id'],
                    'name' => $user['name'],
                ]);
                $batchId = (int) $pdo->lastInsertId();

                $detailStmt = $pdo->prepare(
                    'INSERT INTO eol_detail
                     (batch_id, product_no, kubun, assignee, due_date, comment, detail, status, created_at, updated_at, created_by_login_id, created_by_name, reference_url, attachment_note)
                     VALUES (:batch_id, :product_no, :kubun, :assignee, :due_date, :comment, :detail, :status, NOW(), NOW(), :login_id, :name, :reference_url, :attachment_note)'
                );
                foreach ($insertTargets as $target) {
                    $detailStmt->execute([
                        'batch_id' => $batchId,
                        'product_no' => $target['product_no'],
                        'kubun' => $kubun,
                        'assignee' => trim((string) ($_POST['tanto'] ?? $user['name'])),
                        'due_date' => $dueDate === '' ? null : $dueDate,
                        'comment' => trim((string) ($_POST['comment'] ?? '')),
                        'detail' => trim((string) ($_POST['detail'] ?? '')),
                        'status' => trim((string) ($_POST['status'] ?? '未着手')),
                        'login_id' => $user['login_id'],
                        'name' => $user['name'],
                        'reference_url' => trim((string) ($_POST['reference_url'] ?? '')),
                        'attachment_note' => trim((string) ($_POST['attachment_note'] ?? '')),
                    ]);
                }
                $pdo->commit();
                eol_audit_log('bulk_status_update', 'batch', (string) $batchId, count($insertTargets) . '件をステータス一括更新');
                header('Location: batches.php?created=' . $batchId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
}

$today = (new DateTime('today'))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ステータス一括登録</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:12px;color:#111827}
  a{color:#2563eb}
  label{display:block;font-size:13px;margin-top:6px}
  input,select,textarea,button{padding:5px 7px;box-sizing:border-box}
  input[type=text],input[type=date],input[type=url],select,textarea{width:460px;max-width:100%}
  textarea{height:84px}
  table{border-collapse:collapse;width:100%;min-width:780px}
  th,td{border:1px solid #ddd;padding:5px 7px;font-size:12px}
  th{background:#f3f4f6}
  .layout{display:grid;grid-template-columns:500px 1fr;gap:12px}
  .panel{border:1px solid #ddd;padding:10px}
  .scroll{max-height:calc(100vh - 130px);overflow:auto;border:1px solid #ddd}
  .badge{display:inline-block;padding:2px 6px;border-radius:10px;color:#fff;font-weight:bold;font-size:12px}
  .small{font-size:12px;color:#6b7280}.err{color:#b91c1c}
</style>
</head>
<body>
<p><a href="bulk_status.php?<?= h(http_build_query($_GET)) ?>">ステータス一括更新へ戻る</a></p>
<h2>ステータス一括登録</h2>
<?php if ($error !== ''): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
<div class="layout">
  <form method="post" class="panel">
    <label>区分
      <select name="kubun" required>
        <option value="">選択してください</option>
        <?php foreach ($kubunList as $option): ?><option value="<?= h($option) ?>"><?= h($option) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>担当者<input type="text" name="tanto" value="<?= h($user['name']) ?>"></label>
    <label>期限<input type="date" name="limit"></label>
    <label>ステータス
      <select name="status"><?php foreach (eol_status_options() as $status): ?><option value="<?= h($status) ?>"><?= h($status) ?></option><?php endforeach; ?></select>
    </label>
    <label>重複時の処理
      <select name="duplicate_policy">
        <option value="add_all">全件追加</option>
        <option value="skip_existing">既存ありをスキップ</option>
        <option value="skip_done">完了済みだけスキップ</option>
      </select>
    </label>
    <label>コメント<input type="text" name="comment"></label>
    <label>詳細<textarea name="detail"></textarea></label>
    <label>参照URL<input type="url" name="reference_url"></label>
    <label>添付メモ<textarea name="attachment_note"></textarea></label>
    <p><button type="submit">表示中の対象 <?= h(count($products)) ?> 件に一括登録</button></p>
  </form>

  <div class="panel">
    <h3>対象 <?= h(count($products)) ?>件</h3>
    <div class="small">製品群: <?= h($productGroup ?: '-') ?> / 理由: <?= h($reason ?: '-') ?> / 申請書: <?= h($requestNo ?: '-') ?></div>
    <div class="scroll">
      <table>
        <tr><th>製品番号</th><th>製品名</th><th>製品群</th><th>申請書</th><th>理由</th></tr>
        <?php foreach ($products as $product): ?>
          <tr>
            <td><?= h($product['product_no']) ?></td>
            <td><?= h($product['product_name']) ?></td>
            <td><?= h($product['product_groups']) ?></td>
            <td><?= h($product['request_no']) ?></td>
            <td><?= h(eol_compact_reason($product['reason'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
</div>
</body>
</html>
