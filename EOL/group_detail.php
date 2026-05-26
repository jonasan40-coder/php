<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

$user = eol_require_login();
$pdo = eol_pdo();
$kubunList = eol_kubun_list();

$view = (string) ($_GET['view'] ?? 'all');
$productGroup = trim((string) ($_GET['product_group'] ?? ''));
$reason = trim((string) ($_GET['reason'] ?? ''));
$requestNo = trim((string) ($_GET['request_no'] ?? ''));
$registeredDate = trim((string) ($_GET['registered_date'] ?? ''));
$registrant = trim((string) ($_GET['registrant'] ?? ''));
$dueFrom = trim((string) ($_GET['due_from'] ?? ''));
$dueTo = trim((string) ($_GET['due_to'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['p.ins_datetime >= :from_date'];
$params = ['from_date' => '2025-01-01 00:00:00'];

if ($productGroup !== '') {
    $where[] = '(EXISTS (SELECT 1 FROM ttimps210002 mx WHERE mx.`T$SPLI` = p.plu_cd AND mx.`T$PLNI` LIKE :product_group_master)
                 OR EXISTS (SELECT 1 FROM eol_group_members ga WHERE ga.product_no = p.plu_cd AND ga.action_type = "add" AND ga.product_group LIKE :product_group_add))';
    $params['product_group_master'] = '%' . $productGroup . '%';
    $params['product_group_add'] = '%' . $productGroup . '%';
}
if ($reason !== '') {
    $where[] = '(p.reason LIKE :reason_main
                 OR EXISTS (SELECT 1 FROM eol_group_members ga WHERE ga.product_no = p.plu_cd AND ga.action_type = "add" AND ga.reason LIKE :reason_add))';
    $params['reason_main'] = '%' . $reason . '%';
    $params['reason_add'] = '%' . $reason . '%';
}
if ($productGroup !== '' || $reason !== '') {
    $exclude = 'NOT EXISTS (SELECT 1 FROM eol_group_members ge WHERE ge.product_no = p.plu_cd AND ge.action_type = "exclude"';
    if ($productGroup !== '') {
        $exclude .= ' AND ge.product_group LIKE :exclude_product_group';
        $params['exclude_product_group'] = '%' . $productGroup . '%';
    }
    if ($reason !== '') {
        $exclude .= ' AND ge.reason LIKE :exclude_reason';
        $params['exclude_reason'] = '%' . $reason . '%';
    }
    $exclude .= ')';
    $where[] = $exclude;
}
if ($requestNo !== '') {
    $where[] = 'p.request_no LIKE :request_no';
    $params['request_no'] = '%' . $requestNo . '%';
}
if ($registeredDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $registeredDate) === 1) {
    $where[] = 'DATE(p.ins_datetime) = :registered_date';
    $params['registered_date'] = $registeredDate;
}
if ($registrant !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM eol_detail dx WHERE dx.product_no = p.plu_cd AND dx.voided_at IS NULL AND (dx.created_by_login_id LIKE :registrant_login OR dx.created_by_name LIKE :registrant_name))';
    $params['registrant_login'] = '%' . $registrant . '%';
    $params['registrant_name'] = '%' . $registrant . '%';
}
if ($q !== '') {
    $where[] = '(p.plu_cd LIKE :q_plu_cd OR p.plu_name LIKE :q_plu_name OR p.reason LIKE :q_reason OR p.request_no LIKE :q_request_no)';
    $params['q_plu_cd'] = '%' . $q . '%';
    $params['q_plu_name'] = '%' . $q . '%';
    $params['q_reason'] = '%' . $q . '%';
    $params['q_request_no'] = '%' . $q . '%';
}

$sql = '
SELECT
  p.plu_cd AS product_no,
  p.plu_name AS product_name,
  p.reason,
  p.request_no,
  p.ins_datetime AS registered_at,
  COALESCE(
    GROUP_CONCAT(DISTINCT gm_display.product_group ORDER BY gm_display.product_group SEPARATOR ", "),
    GROUP_CONCAT(DISTINCT m.`T$PLNI` ORDER BY m.`T$PLNI` SEPARATOR ", ")
  ) AS product_groups
FROM eol_product_status p
LEFT JOIN ttimps210002 m ON m.`T$SPLI` = p.plu_cd
LEFT JOIN eol_group_members gm_display ON gm_display.product_no = p.plu_cd AND gm_display.action_type = "add"
WHERE ' . implode(' AND ', $where) . '
GROUP BY p.plu_cd, p.plu_name, p.reason, p.request_no, p.ins_datetime
ORDER BY p.ins_datetime DESC, p.plu_cd
LIMIT 3000';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
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

$today = (new DateTime('today'))->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>グループ詳細</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:12px;color:#111827}
  a{color:#2563eb}
  table{border-collapse:collapse;width:100%;min-width:1350px}
  th,td{border:1px solid #ddd;padding:5px 7px;font-size:12px;vertical-align:top}
  th{background:#f3f4f6;position:sticky;top:0;z-index:2}
  .nav{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
  .scroll{max-height:calc(100vh - 95px);overflow:auto;border:1px solid #ddd}
  .badge{display:inline-block;padding:2px 6px;border-radius:10px;color:#fff;font-weight:bold;font-size:12px}
  .small{font-size:12px;color:#6b7280}
  .danger{color:#b91c1c;font-weight:bold}
  .celllink{display:inline-block;min-width:74px;text-decoration:none;color:#111}
</style>
</head>
<body>
<div class="nav">
  <a href="index.php?view=<?= h($view) ?>">ダッシュボードへ戻る</a>
  <strong>グループ詳細</strong>
  <span class="small">
    製品群: <?= h($productGroup ?: '-') ?> /
    申請書: <?= h($requestNo ?: '-') ?> /
    申請日: <?= h(eol_format_date($registeredDate) ?: '-') ?> /
    登録者: <?= h($registrant ?: '-') ?> /
    <?= h(count($products)) ?>件
  </span>
</div>

<div class="scroll">
<table>
  <tr>
    <th style="width:110px">製品番号</th>
    <th>製品名</th>
    <th style="width:120px">製品群</th>
    <th style="width:120px">申請書</th>
    <th style="width:100px">申請日</th>
    <th style="width:130px">理由</th>
    <th style="width:100px">進捗</th>
    <?php foreach ($kubunList as $kubun): ?>
      <th><?= h($kubun) ?></th>
    <?php endforeach; ?>
  </tr>
  <?php foreach ($products as $product): ?>
    <?php
    $done = 0;
    foreach ($kubunList as $kubun) {
        if (($latestByProduct[$product['product_no']][$kubun]['status'] ?? '') === '完了') {
            $done++;
        }
    }
    ?>
    <tr>
      <td><?= h($product['product_no']) ?></td>
      <td><?= h($product['product_name']) ?></td>
      <td><?= h($product['product_groups']) ?></td>
      <td><?= h($product['request_no']) ?></td>
      <td><?= h(eol_format_date($product['registered_at'])) ?></td>
      <td><?= h(eol_compact_reason($product['reason'])) ?></td>
      <td><?= h($done) ?> / <?= h(count($kubunList)) ?></td>
      <?php foreach ($kubunList as $kubun): ?>
        <?php
        $latest = $latestByProduct[$product['product_no']][$kubun] ?? null;
        $status = (string) ($latest['status'] ?? '未着手');
        $dueDate = substr((string) ($latest['due_date'] ?? ''), 0, 10);
        $isOverdue = $dueDate !== '' && $dueDate < $today && $status !== '完了';
        ?>
        <td>
          <a class="celllink" href="detail.php?product=<?= urlencode((string) $product['product_no']) ?>&kubun=<?= urlencode($kubun) ?>">
            <span class="badge" style="background:<?= h(eol_status_color($status)) ?>"><?= h($status) ?></span>
          </a>
          <div class="small <?= $isOverdue ? 'danger' : '' ?>"><?= h(eol_format_date($latest['due_date'] ?? null)) ?></div>
          <div class="small"><?= h($latest['assignee'] ?? '') ?></div>
        </td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>
</div>
</body>
</html>
