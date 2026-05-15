<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

eol_audit_log('logout', 'user', eol_current_user()['login_id'] ?? null, 'ログアウト');
$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
