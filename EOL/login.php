<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

function eol_login_default_role(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query(
            'SELECT COLUMN_TYPE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "users"
               AND COLUMN_NAME = "role"
             LIMIT 1'
        );
        $column = $stmt->fetch();
        if (!$column) {
            return null;
        }

        $default = $column['COLUMN_DEFAULT'] ?? null;
        if (is_string($default) && $default !== '') {
            return $default;
        }

        $values = [];
        if (preg_match_all("/'((?:''|[^'])*)'/", (string) $column['COLUMN_TYPE'], $matches)) {
            foreach ($matches[1] as $value) {
                $values[] = str_replace("''", "'", $value);
            }
        }

        foreach (['editor', 'admin', 'viewer'] as $preferred) {
            if (in_array($preferred, $values, true)) {
                return $preferred;
            }
        }

        return $values[0] ?? null;
    } catch (Throwable) {
        return null;
    }
}

function eol_login_table_has_column(PDO $pdo, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "users"
               AND COLUMN_NAME = :column_name
             LIMIT 1'
        );
        $stmt->execute(['column_name' => $columnName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

if (eol_current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$notice = '';
$mode = (string) ($_POST['mode'] ?? 'login');
$postedLoginId = '';
$postedName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = trim((string) ($_POST['login_id'] ?? ''));
    $postedLoginId = $loginId;
    $postedName = trim((string) ($_POST['name'] ?? ''));

    if ($mode === 'register') {
        if ($loginId === '' || $postedName === '') {
            $error = '社員コードと名前を入力してください。';
        } else {
            $pdo = eol_pdo();
            $stmt = $pdo->prepare('SELECT login_id, name, COALESCE(role, "editor") AS role, COALESCE(is_active, 1) AS is_active FROM users WHERE login_id = :login_id LIMIT 1');
            $stmt->execute(['login_id' => $loginId]);
            $existing = $stmt->fetch();

            if ($existing && (int) $existing['is_active'] !== 1) {
                eol_audit_log('register_failed', 'user', $loginId, '無効ユーザーのため登録不可');
                $error = 'この社員コードは無効化されています。管理者に確認してください。';
            } elseif ($existing) {
                $notice = '既に登録済みのため、そのままログインしました。';
                $user = [
                    'login_id' => (string) $existing['login_id'],
                    'name' => (string) $existing['name'],
                    'role' => (string) $existing['role'],
                ];
            } else {
                $newRole = eol_login_default_role($pdo);
                $insertParams = [
                    'login_id' => $loginId,
                    'name' => $postedName,
                ];
                $hasPasswordHash = eol_login_table_has_column($pdo, 'password_hash');
                if ($hasPasswordHash) {
                    $insertParams['password_hash'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                }

                if ($newRole === null) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (login_id, name' . ($hasPasswordHash ? ', password_hash' : '') . ', is_active)
                         VALUES (:login_id, :name' . ($hasPasswordHash ? ', :password_hash' : '') . ', 1)'
                    );
                    $sessionRole = 'editor';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (login_id, name, role' . ($hasPasswordHash ? ', password_hash' : '') . ', is_active)
                         VALUES (:login_id, :name, :role' . ($hasPasswordHash ? ', :password_hash' : '') . ', 1)'
                    );
                    $insertParams['role'] = $newRole;
                    $sessionRole = $newRole;
                }
                $stmt->execute($insertParams);
                $user = [
                    'login_id' => $loginId,
                    'name' => $postedName,
                    'role' => $sessionRole,
                ];
                eol_audit_log('register_success', 'user', $loginId, 'ユーザー新規登録');
            }

            if (isset($user)) {
                session_regenerate_id(true);
                $_SESSION['eol_user'] = $user;
                eol_audit_log('login_success', 'user', $loginId, $notice !== '' ? $notice : 'ログイン成功');
                header('Location: index.php');
                exit;
            }
        }
    } elseif ($loginId !== '') {
        $stmt = eol_pdo()->prepare(
            'SELECT login_id, name, COALESCE(role, "editor") AS role
             FROM users
             WHERE login_id = :login_id AND COALESCE(is_active, 1) = 1
             LIMIT 1'
        );
        $stmt->execute(['login_id' => $loginId]);
        $user = $stmt->fetch();

        if ($user) {
            session_regenerate_id(true);
            $_SESSION['eol_user'] = [
                'login_id' => (string) $user['login_id'],
                'name' => (string) $user['name'],
                'role' => (string) $user['role'],
            ];
            eol_audit_log('login_success', 'user', $loginId, 'ログイン成功');
            header('Location: index.php');
            exit;
        }
        eol_audit_log('login_failed', 'user', $loginId, 'ログイン失敗');
        $error = '社員コードが見つからないか、無効なユーザーです。未登録の場合は下の新規登録を使ってください。';
    } elseif ($error === '') {
        $error = '社員コードを入力してください。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>タスク管理表 ログイン</title>
<style>
  body{font-family:Meiryo,"Yu Gothic",sans-serif;margin:0;color:#111827;background:#f3f4f6}
  main{max-width:360px;margin:12vh auto;background:#fff;border:1px solid #ddd;padding:24px}
  label{display:block;font-size:13px;margin-bottom:12px}
  input{width:100%;box-sizing:border-box;padding:8px}
  button{padding:8px 14px}
  .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .error{color:#b91c1c;font-size:13px;margin-bottom:12px}
  .notice{color:#047857;font-size:13px;margin-bottom:12px}
  .register{border-top:1px solid #e5e7eb;margin-top:18px;padding-top:18px}
  .small{font-size:12px;color:#6b7280}
</style>
</head>
<body>
<main>
  <h1>タスク管理表</h1>
  <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="mode" value="login">
    <label>社員コード
      <input type="text" name="login_id" value="<?= h($postedLoginId) ?>" autofocus autocomplete="username">
    </label>
    <div class="actions">
      <button type="submit">ログイン</button>
    </div>
  </form>

  <div class="register">
    <h2>未登録の方</h2>
    <form method="post">
      <input type="hidden" name="mode" value="register">
      <label>社員コード
        <input type="text" name="login_id" value="<?= h($postedLoginId) ?>" autocomplete="username">
      </label>
      <label>名前
        <input type="text" name="name" value="<?= h($postedName) ?>" autocomplete="name">
      </label>
      <div class="actions">
        <button type="submit">新規登録してログイン</button>
      </div>
      <p class="small">登録後は編集者として利用できます。</p>
    </form>
  </div>
</main>
</body>
</html>
