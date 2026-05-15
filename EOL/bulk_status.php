<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

$user = eol_require_login();
$pdo = eol_pdo();
$kubunList = eol_kubun_list();
$validViews = ['all', 'product_group', 'request_no', 'registrant'];

$view = (string) ($_GET['view'] ?? 'all');
if (!in_array($view, $validViews, true)) {
    $view = 'all';
}
$productGroup = trim((string) ($_GET['product_group'] ?? ''));
$reason = trim((string) ($_GET['reason'] ?? ''));
$requestNo = trim((string) ($_GET['request_no'] ?? ''));
$registrant = trim((string) ($_GET['registrant'] ?? ''));
$dueFrom = trim((string) ($_GET['due_from'] ?? ''));
$dueTo = trim((string) ($_GET['due_to'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$manualJoin = 'LEFT JOIN eol_group_members gm_add ON gm_add.product_no = p.plu_cd AND gm_add.action_type = "add"';
$excludeJoin = 'LEFT JOIN eol_group_members gm_ex ON gm_ex.product_no = p.plu_cd AND gm_ex.action_type = "exclude"';
$where = ['p.ins_datetime >= :from_date'];
$params = ['from_date' => '2025-01-01 00:00:00'];

if ($productGroup !== '') {
    $manualJoin .= ' AND gm_add.product_group LIKE :gm_add_product_group';
    $excludeJoin .= ' AND gm_ex.product_group LIKE :gm_ex_product_group';
    $where[] = '(EXISTS (SELECT 1 FROM ttimps210002 mx WHERE mx.`T$SPLI` = p.plu_cd AND mx.`T$PLNI` LIKE :product_group) OR gm_add.id IS NOT NULL)';
    $params['product_group'] = '%' . $productGroup . '%';
    $params['gm_add_product_group'] = '%' . $productGroup . '%';
    $params['gm_ex_product_group'] = '%' . $productGroup . '%';
}
if ($reason !== '') {
    $manualJoin .= ' AND gm_add.reason LIKE :gm_add_reason';
    $excludeJoin .= ' AND gm_ex.reason LIKE :gm_ex_reason';
    $where[] = '(p.reason LIKE :reason OR gm_add.id IS NOT NULL)';
    $params['reason'] = '%' . $reason . '%';
    $params['gm_add_reason'] = '%' . $reason . '%';
    $params['gm_ex_reason'] = '%' . $reason . '%';
}
if ($productGroup !== '' || $reason !== '') {
    $where[] = 'gm_ex.id IS NULL';
}
if ($requestNo !== '') {
    $where[] = 'p.request_no LIKE :request_no';
    $params['request_no'] = '%' . $requestNo . '%';
}
if ($registrant !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM eol_detail dx WHERE dx.product_no = p.plu_cd AND dx.voided_at IS NULL AND (dx.created_by_login_id LIKE :registrant OR dx.created_by_name LIKE :registrant))';
    $params['registrant'] = '%' . $registrant . '%';
}
if ($q !== '') {
    $where[] = '(p.plu_cd LIKE :q OR p.plu_name LIKE :q OR p.reason LIKE :q OR gm_add.reason LIKE :q OR p.request_no LIKE :q)';
    $params['q'] = '%' . $q . '%';
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
$digestGroups = eol_build_dashboard_groups($products, $latestByProduct, $kubunList, $today, $view);
$groups = $pdo->query('SELECT DISTINCT `T$PLNI` AS product_group FROM ttimps210002 WHERE `T$PLNI` IS NOT NULL AND `T$PLNI` <> "" ORDER BY `T$PLNI` LIMIT 500')->fetchAll();
$reasons = $pdo->query('SELECT DISTINCT reason FROM eol_product_status WHERE reason IS NOT NULL AND reason <> "" ORDER BY reason LIMIT 500')->fetchAll();
$requests = $pdo->query('SELECT DISTINCT request_no FROM eol_product_status WHERE request_no IS NOT NULL AND request_no <> "" ORDER BY request_no DESC LIMIT 500')->fetchAll();

function bulk_status_url(string $view, array $overrides = []): string
{
    $params = array_merge($_GET, ['view' => $view], $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return 'bulk_status.php?' . http_build_query($params);
}

function bulk_status_detail_url(array $group): string
{
    $params = array_merge($_GET, $group['params'] ?? []);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return 'bulk_status_detail.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ステータス一括更新</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:12px;color:#111827}
  a{color:#2563eb}
  table{border-collapse:collapse;width:100%}
  th,td{border:1px solid #ddd;padding:5px 7px;font-size:12px}
  th{background:#f3f4f6}
  input,button{padding:5px 7px}
  .nav,.tabs,.filters{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px}
  .filters-wrap{display:flex;flex-direction:column;gap:6px}
  .tab{display:inline-block;border:1px solid #d1d5db;padding:6px 12px;text-decoration:none;color:#111827;background:#fff}
  .tab.active{background:#111827;color:#fff}
  .panel{border:1px solid #ddd;padding:10px;margin:8px 0}
  .scroll{max-height:calc(100vh - 250px);overflow:auto;border:1px solid #ddd}
  .small{font-size:12px;color:#6b7280}.danger{color:#b91c1c;font-weight:bold}
  .button-link{display:inline-block;border:1px solid #2563eb;padding:4px 8px;text-decoration:none;background:#fff}
</style>
</head>
<body>
<div class="nav">
  <a href="index.php?view=<?= h($view) ?>">ダッシュボード</a>
  <a href="target_add.php">一括対象追加</a>
  <strong>ステータス一括更新</strong>
</div>
<div class="tabs">
  <a class="tab <?= $view === 'all' ? 'active' : '' ?>" href="<?= h(bulk_status_url('all')) ?>">全件</a>
  <a class="tab <?= $view === 'product_group' ? 'active' : '' ?>" href="<?= h(bulk_status_url('product_group')) ?>">製品群</a>
  <a class="tab <?= $view === 'request_no' ? 'active' : '' ?>" href="<?= h(bulk_status_url('request_no')) ?>">申請書</a>
  <a class="tab <?= $view === 'registrant' ? 'active' : '' ?>" href="<?= h(bulk_status_url('registrant')) ?>">登録者</a>
</div>
<form method="get" class="panel filters-wrap">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div class="filters">
    <label>製品群<input list="groups" name="product_group" value="<?= h($productGroup) ?>" onchange="this.form.submit()"><datalist id="groups"><?php foreach ($groups as $row): ?><option value="<?= h($row['product_group']) ?>"></option><?php endforeach; ?></datalist></label>
    <label>理由<input list="reasons" name="reason" value="<?= h($reason) ?>" onchange="this.form.submit()"><datalist id="reasons"><?php foreach ($reasons as $row): ?><option value="<?= h($row['reason']) ?>"></option><?php endforeach; ?></datalist></label>
    <label>申請書<input list="requests" name="request_no" value="<?= h($requestNo) ?>" onchange="this.form.submit()"><datalist id="requests"><?php foreach ($requests as $row): ?><option value="<?= h($row['request_no']) ?>"></option><?php endforeach; ?></datalist></label>
  </div>
  <div class="filters">
    <label>期日From<input type="date" name="due_from" value="<?= h($dueFrom) ?>" onchange="this.form.submit()"></label>
    <label>期日To<input type="date" name="due_to" value="<?= h($dueTo) ?>" onchange="this.form.submit()"></label>
    <label>検索<input type="text" name="q" value="<?= h($q) ?>"></label>
    <button type="submit">絞り込み</button>
  </div>
</form>
<div class="scroll">
<table>
  <tr><th>対象</th><th>申請書 / 申請日</th><th>製品数</th><th>進捗</th><th>期限切れ</th><th></th></tr>
  <?php foreach ($digestGroups as $group): ?>
    <?php $s = $group['summary']; ?>
    <tr>
      <td><?= h($group['label']) ?></td>
      <td><?= h($group['sub_label']) ?></td>
      <td><?= h($s['products']) ?></td>
      <td><?= h($s['done']) ?>件完了 / <?= h($s['cells']) ?>件</td>
      <td class="danger"><?= h($s['overdue']) ?></td>
      <td><a class="button-link" href="<?= h(bulk_status_detail_url($group)) ?>">詳細・一括登録</a></td>
    </tr>
  <?php endforeach; ?>
</table>
</div>
</body>
</html>
