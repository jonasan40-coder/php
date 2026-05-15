<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';
$user = eol_require_login();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>一括処理</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:16px;color:#111827}
  a{color:#2563eb}
  .panel{border:1px solid #ddd;padding:14px;margin:12px 0;max-width:720px}
  .small{font-size:12px;color:#6b7280}
</style>
</head>
<body>
<p><a href="index.php">ダッシュボードへ戻る</a></p>
<h2>一括処理</h2>
<div class="panel">
  <h3><a href="target_add.php">一括対象追加</a></h3>
  <p>複数の製品番号を貼り付けて、共通の製品群・理由・申請書で対象品目を追加します。</p>
</div>
<div class="panel">
  <h3><a href="bulk_status.php">ステータス一括更新</a></h3>
  <p>ダッシュボードと同じ表示単位で対象を選び、詳細画面と同じ項目を一括登録します。</p>
</div>
<p class="small"><?= h($user['name']) ?>（<?= h($user['login_id']) ?>）</p>
</body>
</html>
