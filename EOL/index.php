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
$statusFilter = trim((string) ($_GET['status_filter'] ?? ''));
$showCompleted = (string) ($_GET['show_completed'] ?? '') === '1';

$where = ['p.ins_datetime >= :from_date'];
$params = ['from_date' => '2025-01-01 00:00:00'];

if ($productGroup !== '') {
    $where[] = '(EXISTS (SELECT 1 FROM ttimps210002 mx WHERE mx.`T$SPLI` = p.plu_cd AND mx.`T$PLNI` LIKE :product_group_master)
                 OR EXISTS (SELECT 1 FROM eol_group_members ga WHERE ga.product_no = p.plu_cd AND ga.action_type = "add" AND ga.product_group LIKE :product_group_manual))';
    $params['product_group_master'] = '%' . $productGroup . '%';
    $params['product_group_manual'] = '%' . $productGroup . '%';
}
if ($reason !== '') {
    $where[] = '(p.reason LIKE :reason_product
                 OR EXISTS (SELECT 1 FROM eol_group_members ga WHERE ga.product_no = p.plu_cd AND ga.action_type = "add" AND ga.reason LIKE :reason_manual))';
    $params['reason_product'] = '%' . $reason . '%';
    $params['reason_manual'] = '%' . $reason . '%';
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
if ($registrant !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM eol_detail dx WHERE dx.product_no = p.plu_cd AND dx.voided_at IS NULL AND (dx.created_by_login_id LIKE :registrant_login_id OR dx.created_by_name LIKE :registrant_name))';
    $params['registrant_login_id'] = '%' . $registrant . '%';
    $params['registrant_name'] = '%' . $registrant . '%';
}
if ($q !== '') {
    $where[] = '(p.plu_cd LIKE :q_product_no OR p.plu_name LIKE :q_product_name OR p.reason LIKE :q_reason OR p.request_no LIKE :q_request_no)';
    $params['q_product_no'] = '%' . $q . '%';
    $params['q_product_name'] = '%' . $q . '%';
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

$hasStatus = static function (array $product, array $latestByProduct, array $kubunList, string $status): bool {
    foreach ($kubunList as $kubun) {
        $current = (string) ($latestByProduct[$product['product_no']][$kubun]['status'] ?? '未着手');
        if ($current === $status) {
            return true;
        }
    }
    return false;
};

$isAllDone = static function (array $product, array $latestByProduct, array $kubunList): bool {
    foreach ($kubunList as $kubun) {
        if (($latestByProduct[$product['product_no']][$kubun]['status'] ?? '未着手') !== '完了') {
            return false;
        }
    }
    return true;
};

$isOverdueProduct = static function (array $product, array $latestByProduct, array $kubunList): bool {
    $today = (new DateTime('today'))->format('Y-m-d');
    foreach ($kubunList as $kubun) {
        $latest = $latestByProduct[$product['product_no']][$kubun] ?? null;
        $status = (string) ($latest['status'] ?? '未着手');
        $dueDate = substr((string) ($latest['due_date'] ?? ''), 0, 10);
        if ($dueDate !== '' && $dueDate < $today && $status !== '完了') {
            return true;
        }
    }
    return false;
};

if ($view === 'all' && !$showCompleted) {
    $products = array_values(array_filter($products, fn(array $product): bool => !$isAllDone($product, $latestByProduct, $kubunList)));
}

if ($statusFilter !== '') {
    $products = array_values(array_filter($products, function (array $product) use ($statusFilter, $latestByProduct, $kubunList, $hasStatus, $isOverdueProduct): bool {
        return match ($statusFilter) {
            'not_started' => $hasStatus($product, $latestByProduct, $kubunList, '未着手'),
            'started' => $hasStatus($product, $latestByProduct, $kubunList, '着手'),
            'pending' => $hasStatus($product, $latestByProduct, $kubunList, '保留'),
            'inquiry' => $hasStatus($product, $latestByProduct, $kubunList, '問い合わせ'),
            'overdue' => $isOverdueProduct($product, $latestByProduct, $kubunList),
            default => true,
        };
    }));
}

$today = (new DateTime('today'))->format('Y-m-d');
$summary = eol_calculate_progress($products, $latestByProduct, $kubunList, $today);
$digestGroups = eol_build_dashboard_groups($products, $latestByProduct, $kubunList, $today, $view);

$groups = $pdo->query('SELECT DISTINCT `T$PLNI` AS product_group FROM ttimps210002 WHERE `T$PLNI` IS NOT NULL AND `T$PLNI` <> "" ORDER BY `T$PLNI` LIMIT 500')->fetchAll();
$reasons = $pdo->query('SELECT DISTINCT reason FROM eol_product_status WHERE reason IS NOT NULL AND reason <> "" ORDER BY reason LIMIT 500')->fetchAll();
$requests = $pdo->query('SELECT DISTINCT request_no FROM eol_product_status WHERE request_no IS NOT NULL AND request_no <> "" ORDER BY request_no DESC LIMIT 500')->fetchAll();
$registrants = $pdo->query('SELECT DISTINCT created_by_login_id, created_by_name FROM eol_detail WHERE created_by_login_id IS NOT NULL AND created_by_login_id <> "" ORDER BY created_by_name, created_by_login_id LIMIT 500')->fetchAll();

function view_url(string $view, array $overrides = []): string
{
    $params = array_merge($_GET, ['view' => $view], $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return 'index.php?' . http_build_query($params);
}

function detail_url(array $group, array $extra = []): string
{
    $params = array_merge($_GET, $group['params'] ?? [], $extra);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return 'group_detail.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>終売案内 ダッシュボード</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:12px;color:#111827}
  a{color:#2563eb}
  table{border-collapse:collapse;width:100%}
  th,td{border:1px solid #ddd;padding:5px 7px;font-size:12px;vertical-align:top}
  th{background:#f3f4f6}
  input,button{padding:5px 7px;box-sizing:border-box}
  .nav,.filters,.cards,.tabs{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:8px}
  .nav{justify-content:space-between;align-items:center}
  .nav-links{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .topbar{display:flex;gap:10px;align-items:flex-start;flex-wrap:nowrap;border:1px solid #ddd;padding:8px;margin:8px 0;overflow-x:auto}
  .topbar .tabs{flex:0 0 auto;margin:0}
  .topbar .filters-wrap{flex:1 0 auto;display:flex;flex-direction:column;gap:6px}
  .topbar .filters{margin:0;flex-wrap:nowrap}
  .topbar label{white-space:nowrap}
  .topbar input{width:150px}
  .tab{display:inline-block;border:1px solid #d1d5db;padding:6px 12px;text-decoration:none;color:#111827;background:#fff}
  .tab.active{background:#111827;color:#fff;border-color:#111827}
  .card{border:1px solid #ddd;padding:8px 10px;min-width:128px;background:#fff}
  .card-link{display:block;text-decoration:none;color:#111827}
  .card.active{border-color:#111827;background:#f3f4f6}
  .card strong{display:block;font-size:18px}
  .panel{border:1px solid #ddd;padding:10px;margin:8px 0}
  .cards{flex-wrap:nowrap;overflow-x:auto}
  .progress-grid{display:flex;gap:6px;overflow-x:auto;white-space:nowrap}
  .progress-item{border:1px solid #e5e7eb;padding:6px;background:#fafafa;font-size:12px;min-width:230px}
  .progress-title{font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .digest-scroll{max-height:calc(100vh - 390px);min-height:340px;overflow:auto;border:1px solid #ddd;margin-top:8px}
  .digest-scroll th{position:sticky;top:0;z-index:2}
  .small{font-size:12px;color:#6b7280}
  .danger{color:#b91c1c;font-weight:bold}
  .button-link{display:inline-block;border:1px solid #2563eb;padding:4px 8px;text-decoration:none;background:#fff}
  .badge{display:inline-block;padding:2px 6px;border-radius:10px;color:#fff;font-weight:bold;font-size:12px}
  .celllink{display:inline-block;min-width:74px;text-decoration:none;color:#111}
</style>
</head>
<body>
<div class="nav">
  <div class="nav-links">
    <strong>終売案内</strong>
    <a href="index.php">ダッシュボード</a>
    <a href="target_add.php">一括対象追加</a>
    <a href="bulk_status.php?<?= h(http_build_query($_GET)) ?>">ステータス一括更新</a>
    <a href="batches.php">一括更新履歴</a>
  </div>
  <div class="small"><?= h($user['name']) ?>（<?= h($user['login_id']) ?>） <a href="guide.html">使い方</a> <a href="logout.php">ログアウト</a></div>
</div>

<div class="topbar">
  <div class="tabs">
    <a class="tab <?= $view === 'all' ? 'active' : '' ?>" href="<?= h(view_url('all')) ?>">全件</a>
    <a class="tab <?= $view === 'product_group' ? 'active' : '' ?>" href="<?= h(view_url('product_group')) ?>">製品群</a>
    <a class="tab <?= $view === 'request_no' ? 'active' : '' ?>" href="<?= h(view_url('request_no')) ?>">申請書</a>
    <a class="tab <?= $view === 'registrant' ? 'active' : '' ?>" href="<?= h(view_url('registrant')) ?>">登録者</a>
  </div>
  <form method="get" class="filters-wrap">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div class="filters">
    <label>製品群
      <input list="groups" name="product_group" value="<?= h($productGroup) ?>" onchange="this.form.submit()">
      <datalist id="groups"><?php foreach ($groups as $row): ?><option value="<?= h($row['product_group']) ?>"></option><?php endforeach; ?></datalist>
    </label>
    <label>理由
      <input list="reasons" name="reason" value="<?= h($reason) ?>" onchange="this.form.submit()">
      <datalist id="reasons"><?php foreach ($reasons as $row): ?><option value="<?= h($row['reason']) ?>"></option><?php endforeach; ?></datalist>
    </label>
    <label>申請書
      <input list="requests" name="request_no" value="<?= h($requestNo) ?>" onchange="this.form.submit()">
      <datalist id="requests"><?php foreach ($requests as $row): ?><option value="<?= h($row['request_no']) ?>"></option><?php endforeach; ?></datalist>
    </label>
    <label>登録者
      <input list="registrants" name="registrant" value="<?= h($registrant) ?>" onchange="this.form.submit()">
      <datalist id="registrants"><?php foreach ($registrants as $row): ?><option value="<?= h($row['created_by_login_id']) ?>"><?= h($row['created_by_name']) ?></option><?php endforeach; ?></datalist>
    </label>
  </div>
  <div class="filters">
    <label>期日From<input type="date" name="due_from" value="<?= h($dueFrom) ?>" onchange="this.form.submit()"></label>
    <label>期日To<input type="date" name="due_to" value="<?= h($dueTo) ?>" onchange="this.form.submit()"></label>
    <label>検索<input type="text" name="q" value="<?= h($q) ?>" placeholder="製品番号/製品名/理由"></label>
    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status_filter" value="<?= h($statusFilter) ?>"><?php endif; ?>
    <?php if ($showCompleted): ?><input type="hidden" name="show_completed" value="1"><?php endif; ?>
    <button type="submit">絞り込み</button>
    <?php if ($view === 'all'): ?>
      <a href="<?= h(view_url('all', ['show_completed' => $showCompleted ? '' : '1'])) ?>"><?= $showCompleted ? '完了済みを隠す' : '完了済みも表示' ?></a>
    <?php endif; ?>
    <a href="<?= h(view_url($view, ['product_group' => '', 'reason' => '', 'request_no' => '', 'registrant' => '', 'due_from' => '', 'due_to' => '', 'q' => '', 'status_filter' => ''])) ?>">条件クリア</a>
  </div>
  </form>
</div>

<div class="cards">
  <div class="card"><span>対象製品</span><strong><?= h($summary['products']) ?></strong></div>
  <div class="card"><span>全体進捗</span><strong><?= h($summary['done']) ?> / <?= h($summary['cells']) ?></strong></div>
  <div class="card <?= $statusFilter === 'not_started' ? 'active' : '' ?>"><a class="card-link" href="<?= h(view_url($view, ['status_filter' => 'not_started'])) ?>"><span>未着手</span><strong><?= h($summary['not_started']) ?></strong></a></div>
  <div class="card <?= $statusFilter === 'overdue' ? 'active' : '' ?>"><a class="card-link" href="<?= h(view_url($view, ['status_filter' => 'overdue'])) ?>"><span>期限切れ</span><strong class="danger"><?= h($summary['overdue']) ?></strong></a></div>
  <div class="card <?= $statusFilter === 'started' ? 'active' : '' ?>"><a class="card-link" href="<?= h(view_url($view, ['status_filter' => 'started'])) ?>"><span>着手</span><strong><?= h($summary['status']['着手'] ?? 0) ?></strong></a></div>
  <div class="card <?= $statusFilter === 'inquiry' ? 'active' : '' ?>"><a class="card-link" href="<?= h(view_url($view, ['status_filter' => 'inquiry'])) ?>"><span>問い合わせ</span><strong><?= h($summary['status']['問い合わせ'] ?? 0) ?></strong></a></div>
  <?php if ($statusFilter !== ''): ?><div class="card"><a class="card-link" href="<?= h(view_url($view, ['status_filter' => ''])) ?>"><span>解除</span><strong>ALL</strong></a></div><?php endif; ?>
</div>

<div class="panel">
  <div class="progress-grid">
    <?php foreach ($kubunList as $kubun): ?>
      <?php $row = $summary['kubun'][$kubun]; ?>
      <div class="progress-item">
        <div class="progress-title"><?= h($kubun) ?></div>
        <div><?= h($row['done']) ?>件完了 / <?= h($summary['products']) ?>件</div>
        <div class="small">未 <?= h($row['status']['未着手'] ?? 0) ?> / 着 <?= h($row['status']['着手'] ?? 0) ?> / 保 <?= h($row['status']['保留'] ?? 0) ?> / 問 <?= h($row['status']['問い合わせ'] ?? 0) ?> / <span class="danger">期限 <?= h($row['overdue']) ?></span></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel">
  <h3><?= $view === 'all' ? '全件詳細' : 'グループ概要' ?></h3>
  <div class="digest-scroll">
  <?php if ($view === 'all'): ?>
  <table style="min-width:1350px">
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
          $isOverdue = $dueDate !== '' && $dueDate < (new DateTime('today'))->format('Y-m-d') && $status !== '完了';
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
  <?php else: ?>
  <table>
    <tr>
      <th><?= $view === 'product_group' ? '製品群' : ($view === 'request_no' ? '申請書' : ($view === 'registrant' ? '登録者' : '対象')) ?></th>
      <th>申請書 / 申請日</th>
      <th style="width:90px">製品数</th>
      <th style="width:130px">進捗</th>
      <th style="width:90px">未着手</th>
      <th style="width:90px">期限切れ</th>
      <th style="width:90px">着手</th>
      <th style="width:110px">問い合わせ</th>
      <th style="width:90px"></th>
    </tr>
    <?php foreach ($digestGroups as $group): ?>
      <?php $groupSummary = $group['summary']; ?>
      <tr>
        <td><?= h($group['label']) ?></td>
        <td><?= h($group['sub_label']) ?></td>
        <td><?= h($groupSummary['products']) ?></td>
        <td><?= h($groupSummary['done']) ?>件完了 / <?= h($groupSummary['cells']) ?>件</td>
        <td><?= h($groupSummary['not_started']) ?></td>
        <td class="danger"><?= h($groupSummary['overdue']) ?></td>
        <td><?= h($groupSummary['status']['着手'] ?? 0) ?></td>
        <td><?= h($groupSummary['status']['問い合わせ'] ?? 0) ?></td>
        <td><a class="button-link" href="<?= h(detail_url($group)) ?>">詳細</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  </div>
</div>
</body>
</html>
