<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

$user = eol_require_login();
if (!eol_user_can_edit()) {
    http_response_code(403);
    exit('権限がありません。');
}

$pdo = eol_pdo();
$message = '';
$error = '';
$productGroup = trim((string) ($_REQUEST['product_group'] ?? ''));
$reason = trim((string) ($_REQUEST['reason'] ?? ''));
$requestNo = trim((string) ($_REQUEST['request_no'] ?? ''));
$registeredAt = trim((string) ($_REQUEST['registered_at'] ?? date('Y-m-d')));

function eol_parse_product_rows(string $text, array &$errors): array
{
    $rows = [];
    $lineNo = 0;
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) ?: [] as $line) {
        $lineNo++;
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\t|,/', $line);
        $productNo = trim((string) ($parts[0] ?? ''));
        $productName = trim((string) ($parts[1] ?? ''));
        if ($productNo === '' || $productName === '') {
            $errors[] = "{$lineNo}行目: 製品番号と製品名は必須です。";
            continue;
        }
        $rows[$productNo] = ['product_no' => $productNo, 'product_name' => $productName];
    }
    return array_values($rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rowErrors = [];
    $rows = eol_parse_product_rows((string) ($_POST['products'] ?? ''), $rowErrors);

    if ($reason === '' || $rows === [] || $rowErrors !== []) {
        $error = $rowErrors !== [] ? implode("\n", $rowErrors) : '理由、製品番号、製品名を入力してください。';
    } else {
        $pdo->beginTransaction();
        try {
            $productStmt = $pdo->prepare(
                'INSERT INTO eol_product_status
                 (rec_unid, plu_cd, status, status_nm, plu_name, reason, ins_datetime, ins_user, request_no)
                 VALUES (:rec_unid, :plu_cd, :status, :status_nm, :plu_name, :reason, :ins_datetime, :ins_user, :request_no)
                 ON DUPLICATE KEY UPDATE
                   plu_name = COALESCE(NULLIF(VALUES(plu_name), ""), plu_name),
                   reason = VALUES(reason),
                   ins_datetime = VALUES(ins_datetime),
                   ins_user = VALUES(ins_user),
                   request_no = VALUES(request_no)'
            );
            $memberStmt = $pdo->prepare(
                'INSERT INTO eol_group_members
                 (product_group, reason, product_no, action_type, exclude_reason, note, created_by_login_id, created_by_name, created_at)
                 VALUES (:product_group, :reason, :product_no, "add", NULL, :note, :login_id, :name, NOW())
                 ON DUPLICATE KEY UPDATE note=VALUES(note), created_by_login_id=VALUES(created_by_login_id), created_by_name=VALUES(created_by_name), created_at=NOW()'
            );

            foreach ($rows as $row) {
                $recUnid = 'MANUAL-' . substr(sha1($row['product_no'] . '|' . $reason . '|' . $requestNo . '|' . $registeredAt), 0, 32);
                $productStmt->execute([
                    'rec_unid' => $recUnid,
                    'plu_cd' => $row['product_no'],
                    'status' => '1',
                    'status_nm' => '入力済',
                    'plu_name' => $row['product_name'],
                    'reason' => $reason,
                    'ins_datetime' => $registeredAt === '' ? date('Y-m-d H:i:s') : $registeredAt . ' 00:00:00',
                    'ins_user' => $user['name'],
                    'request_no' => $requestNo === '' ? null : $requestNo,
                ]);

                if ($productGroup !== '') {
                    $memberStmt->execute([
                        'product_group' => $productGroup,
                        'reason' => $reason,
                        'product_no' => $row['product_no'],
                        'note' => '一括対象追加',
                        'login_id' => $user['login_id'],
                        'name' => $user['name'],
                    ]);
                }
            }

            $pdo->commit();
            eol_audit_log('target_bulk_add', 'products', null, count($rows) . '件を対象追加', [
                'product_group' => $productGroup,
                'reason' => $reason,
                'request_no' => $requestNo,
            ]);
            $message = count($rows) . '件を対象品目として追加しました。';
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

$groups = $pdo->query(
    'SELECT product_group
     FROM (
       SELECT DISTINCT `T$PLNI` AS product_group
       FROM ttimps210002
       WHERE `T$PLNI` IS NOT NULL AND `T$PLNI` <> ""
       UNION
       SELECT DISTINCT product_group
       FROM eol_group_members
       WHERE product_group IS NOT NULL AND product_group <> ""
     ) x
     ORDER BY product_group
     LIMIT 500'
)->fetchAll();
if ($productGroup !== '' && !in_array($productGroup, array_column($groups, 'product_group'), true)) {
    $groups[] = ['product_group' => $productGroup];
}
if ($productGroup !== '') {
    $reasonStmt = $pdo->prepare(
        'SELECT DISTINCT p.reason
         FROM eol_product_status p
         INNER JOIN ttimps210002 m ON m.`T$SPLI` = p.plu_cd
         WHERE m.`T$PLNI` = :product_group_master AND p.reason IS NOT NULL AND p.reason <> ""
         UNION
         SELECT DISTINCT reason
         FROM eol_group_members
         WHERE product_group = :product_group_manual AND reason IS NOT NULL AND reason <> ""
         ORDER BY reason
         LIMIT 500'
    );
    $reasonStmt->execute([
        'product_group_master' => $productGroup,
        'product_group_manual' => $productGroup,
    ]);
    $reasons = $reasonStmt->fetchAll();
} else {
    $reasons = $pdo->query('SELECT DISTINCT reason FROM eol_product_status WHERE reason IS NOT NULL AND reason <> "" ORDER BY reason LIMIT 500')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>一括対象追加</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:16px;color:#111827}
  a{color:#2563eb}
  label{display:block;font-size:13px;margin-top:8px}
  input,textarea,button{padding:6px 8px;box-sizing:border-box}
  input{width:360px;max-width:100%}
  textarea{width:720px;max-width:100%;height:220px}
  .nav,.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
  .panel{border:1px solid #ddd;padding:14px;margin-top:12px}
  .ok{color:#166534}.err{color:#b91c1c}.small{font-size:12px;color:#6b7280}
</style>
</head>
<body>
<div class="nav">
  <a href="index.php">ダッシュボード</a>
  <a href="bulk_status.php">ステータス一括更新</a>
  <span class="small"><?= h($user['name']) ?>（<?= h($user['login_id']) ?>）</span>
</div>

<h2>一括対象追加</h2>
<?php if ($message !== ''): ?><p class="ok"><?= h($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="err"><?= nl2br(h($error)) ?></p><?php endif; ?>

<form method="post" class="panel">
  <label>共通の製品群（任意）
    <input list="groups" name="product_group" value="<?= h($productGroup) ?>" onchange="location.href='target_add.php?product_group='+encodeURIComponent(this.value)">
    <datalist id="groups"><?php foreach ($groups as $row): ?><option value="<?= h($row['product_group']) ?>"></option><?php endforeach; ?></datalist>
  </label>
  <label>共通の理由
    <input list="reasons" name="reason" value="<?= h($reason) ?>" required>
    <datalist id="reasons"><?php foreach ($reasons as $row): ?><option value="<?= h($row['reason']) ?>"></option><?php endforeach; ?></datalist>
  </label>
  <label>申請書 request_no（任意）
    <input type="text" name="request_no" value="<?= h($requestNo) ?>">
  </label>
  <label>申請日
    <input type="date" name="registered_at" value="<?= h($registeredAt) ?>">
  </label>
  <label>対象品目（Excelから貼り付け可：1列目=製品番号、2列目=製品名、どちらも必須）
    <textarea name="products" placeholder="33801&#9;VERIFONE P400&#10;92305&#9;ECOA-V100S/SW BAR-SCANNER"></textarea>
  </label>
  <div class="actions">
    <button type="submit">対象品目を一括追加</button>
  </div>
</form>
</body>
</html>
