<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$configCandidates = [__DIR__ . '/config.php', dirname(__DIR__) . '/config.php'];
foreach ($configCandidates as $configPath) {
    if (!is_file($configPath)) {
        continue;
    }
    if (basename(dirname($configPath)) === 'EOL' && !class_exists('mysqli')) {
        continue;
    }
    require_once $configPath;
    break;
}

if (!defined('EOL_DB_HOST') && isset($host, $db, $user, $pass) && is_string($host) && is_string($db) && is_string($user)) {
    define('EOL_DB_HOST', $host);
    define('EOL_DB_NAME', $db);
    define('EOL_DB_USER', $user);
    define('EOL_DB_PASSWORD', (string) $pass);
}

function eol_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASSWORD')) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $user = DB_USER;
        $password = DB_PASSWORD;
    } elseif (defined('dsn') && defined('username') && defined('password')) {
        $dsn = (string) constant('dsn');
        $user = (string) constant('username');
        $password = (string) constant('password');
    } elseif (defined('EOL_DB_HOST') && defined('EOL_DB_NAME') && defined('EOL_DB_USER') && defined('EOL_DB_PASSWORD')) {
        $dsn = 'mysql:host=' . EOL_DB_HOST . ';dbname=' . EOL_DB_NAME . ';charset=utf8mb4';
        $user = EOL_DB_USER;
        $password = EOL_DB_PASSWORD;
    } else {
        throw new RuntimeException('DB connection settings are not defined.');
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function eol_current_user(): ?array
{
    if (empty($_SESSION['eol_user'])) {
        return null;
    }
    return $_SESSION['eol_user'];
}

function eol_require_login(): array
{
    $user = eol_current_user();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function eol_user_can_edit(): bool
{
    $user = eol_current_user();
    return $user !== null && in_array($user['role'] ?? 'editor', ['admin', 'editor'], true);
}

function eol_status_color(string $status): string
{
    return match ($status) {
        '完了' => '#16a34a',
        '着手' => '#2563eb',
        '保留' => '#f59e0b',
        '問い合わせ' => '#a855f7',
        default => '#9ca3af',
    };
}

function eol_status_options(): array
{
    return ['未着手', '着手', '完了', '保留', '問い合わせ'];
}

function eol_kubun_list(): array
{
    return [
        'カウントダウン数の確認',
        'オーダー見直し/リワーク',
        '専用部品の抽出',
        '不要なオーダーのキャンセル',
        '品目メンテ(ロット見直し含む)',
        '補充オーダー解除(親基板忘れずに)',
        '棚卸',
        '後継機',
        '後継機の先行手配確認',
    ];
}

function eol_format_date(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    try {
        return (new DateTime((string) $value))->format('Y/m/d');
    } catch (Throwable) {
        return (string) $value;
    }
}

function eol_format_datetime(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    try {
        return (new DateTime((string) $value))->format('Y/m/d H:i');
    } catch (Throwable) {
        return (string) $value;
    }
}

function eol_compact_reason(mixed $value): string
{
    $reason = trim((string) $value);
    if ($reason === '') {
        return '';
    }

    $parts = preg_split('/\s*\/\s*/u', $reason) ?: [];
    $unique = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $unique[$part] = $part;
        }
    }

    return implode('/', array_values($unique));
}

function eol_audit_log(string $action, ?string $targetType = null, ?string $targetId = null, ?string $summary = null, array $detail = []): void
{
    try {
        $user = eol_current_user();
        $stmt = eol_pdo()->prepare(
            'INSERT INTO eol_audit_logs
             (user_login_id, user_name, action, target_type, target_id, summary, detail_json, ip_address, user_agent, created_at)
             VALUES (:login_id, :name, :action, :target_type, :target_id, :summary, :detail_json, :ip, :ua, NOW())'
        );
        $stmt->execute([
            'login_id' => $user['login_id'] ?? null,
            'name' => $user['name'] ?? null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => $summary,
            'detail_json' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable) {
        // Logging must not block the main workflow.
    }
}

function eol_parse_product_numbers(string $text): array
{
    $tokens = preg_split('/[\s,\t;]+/u', trim($text)) ?: [];
    $products = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token !== '') {
            $products[$token] = $token;
        }
    }
    return array_values($products);
}

function eol_fetch_targets(PDO $pdo, string $productGroup, string $reason): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT p.plu_cd AS product_no, p.plu_name AS product_name, p.reason, m.`T$PLNI` AS product_group
         FROM eol_product_status p
         INNER JOIN ttimps210002 m ON p.plu_cd = m.`T$SPLI`
         WHERE m.`T$PLNI` = :product_group AND p.reason = :reason
         ORDER BY p.plu_cd'
    );
    $stmt->execute(['product_group' => $productGroup, 'reason' => $reason]);

    $targets = [];
    foreach ($stmt as $row) {
        $targets[$row['product_no']] = $row;
    }

    $adjustStmt = $pdo->prepare(
        'SELECT gm.*, p.plu_name AS product_name
         FROM eol_group_members gm
         LEFT JOIN eol_product_status p ON p.plu_cd = gm.product_no
         WHERE gm.product_group = :product_group AND gm.reason = :reason
         ORDER BY gm.created_at, gm.id'
    );
    $adjustStmt->execute(['product_group' => $productGroup, 'reason' => $reason]);

    foreach ($adjustStmt as $row) {
        if ($row['action_type'] === 'exclude') {
            unset($targets[$row['product_no']]);
            continue;
        }

        $targets[$row['product_no']] = [
            'product_no' => $row['product_no'],
            'product_name' => $row['product_name'] ?? '',
            'reason' => $reason,
            'product_group' => $productGroup,
        ];
    }

    return array_values($targets);
}

function eol_fetch_latest_map(PDO $pdo, array $productNos, ?string $kubun = null): array
{
    if ($productNos === []) {
        return [];
    }

    $params = $productNos;
    $placeholders = implode(',', array_fill(0, count($productNos), '?'));
    $kubunWhere = '';
    if ($kubun !== null && $kubun !== '') {
        $kubunWhere = ' AND kubun = ?';
        $params[] = $kubun;
    }

    $sql = <<<SQL
SELECT d.product_no, d.kubun, d.status, d.due_date, d.assignee, d.updated_at, d.created_by_login_id, d.created_by_name
FROM eol_detail d
INNER JOIN (
  SELECT product_no, kubun, MAX(CONCAT(DATE_FORMAT(updated_at, '%Y%m%d%H%i%s'), LPAD(id, 10, '0'))) AS latest_key
  FROM eol_detail
  WHERE voided_at IS NULL AND product_no IN ($placeholders) $kubunWhere
  GROUP BY product_no, kubun
) x
  ON x.product_no = d.product_no
 AND x.kubun = d.kubun
 AND x.latest_key = CONCAT(DATE_FORMAT(d.updated_at, '%Y%m%d%H%i%s'), LPAD(d.id, 10, '0'))
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $latest = [];
    foreach ($stmt as $row) {
        $latest[$row['product_no']][$row['kubun']] = $row;
    }

    return $latest;
}

function eol_calculate_progress(array $products, array $latestByProduct, array $kubunList, string $today): array
{
    $summary = [
        'products' => count($products),
        'cells' => count($products) * count($kubunList),
        'done' => 0,
        'not_started' => 0,
        'overdue' => 0,
        'status' => array_fill_keys(eol_status_options(), 0),
        'kubun' => [],
    ];

    foreach ($kubunList as $kubun) {
        $summary['kubun'][$kubun] = [
            'done' => 0,
            'not_started' => 0,
            'overdue' => 0,
            'status' => array_fill_keys(eol_status_options(), 0),
        ];
    }

    foreach ($products as $product) {
        foreach ($kubunList as $kubun) {
            $latest = $latestByProduct[$product['product_no']][$kubun] ?? null;
            $status = (string) ($latest['status'] ?? '未着手');
            $dueDate = substr((string) ($latest['due_date'] ?? ''), 0, 10);

            $summary['status'][$status] = ($summary['status'][$status] ?? 0) + 1;
            $summary['kubun'][$kubun]['status'][$status] = ($summary['kubun'][$kubun]['status'][$status] ?? 0) + 1;

            if ($status === '完了') {
                $summary['done']++;
                $summary['kubun'][$kubun]['done']++;
            }
            if ($status === '未着手') {
                $summary['not_started']++;
                $summary['kubun'][$kubun]['not_started']++;
            }
            if ($dueDate !== '' && $dueDate < $today && $status !== '完了') {
                $summary['overdue']++;
                $summary['kubun'][$kubun]['overdue']++;
            }
        }
    }

    return $summary;
}

function eol_build_dashboard_groups(array $products, array $latestByProduct, array $kubunList, string $today, string $view): array
{
    $groups = [];

    foreach ($products as $product) {
        if ($view === 'product_group') {
            $productGroups = array_filter(array_map('trim', explode(',', (string) ($product['product_groups'] ?? ''))));
            if ($productGroups === []) {
                $productGroups = ['製品群なし'];
            }
            $request = (string) ($product['request_no'] ?? '') ?: '申請書なし';
            $date = eol_format_date($product['registered_at'] ?? null) ?: '申請日なし';
            foreach ($productGroups as $group) {
                $key = $group . '|' . $request . '|' . $date;
                $groups[$key]['label'] = $group;
                $groups[$key]['sub_label'] = $request . ' / ' . $date;
                $groups[$key]['params'] = [
                    'view' => 'product_group',
                    'product_group' => $group,
                    'request_no' => (string) ($product['request_no'] ?? ''),
                    'reason' => (string) ($product['reason'] ?? ''),
                ];
                $groups[$key]['products'][] = $product;
            }
            continue;
        }

        if ($view === 'request_no') {
            $request = (string) ($product['request_no'] ?? '') ?: '申請書なし';
            $date = eol_format_date($product['registered_at'] ?? null) ?: '申請日なし';
            $key = $request . '|' . $date;
            $groups[$key]['label'] = $request;
            $groups[$key]['sub_label'] = $date;
            $groups[$key]['params'] = ['view' => 'request_no', 'request_no' => (string) ($product['request_no'] ?? '')];
            $groups[$key]['products'][] = $product;
            continue;
        }

        if ($view === 'registrant') {
            $latestNames = [];
            foreach ($kubunList as $kubun) {
                $name = $latestByProduct[$product['product_no']][$kubun]['created_by_name'] ?? '';
                if ($name !== '') {
                    $latestNames[$name] = $name;
                }
            }
            if ($latestNames === []) {
                $latestNames = ['登録者なし' => '登録者なし'];
            }
            foreach ($latestNames as $name) {
                $groups[$name]['label'] = $name;
                $groups[$name]['sub_label'] = '登録者';
                $groups[$name]['params'] = ['view' => 'registrant', 'registrant' => $name];
                $groups[$name]['products'][] = $product;
            }
            continue;
        }

        $key = 'all';
        $groups[$key]['label'] = '全件';
        $groups[$key]['sub_label'] = '';
        $groups[$key]['params'] = ['view' => 'all'];
        $groups[$key]['products'][] = $product;
    }

    foreach ($groups as $key => $group) {
        $groups[$key]['summary'] = eol_calculate_progress($group['products'], $latestByProduct, $kubunList, $today);
    }

    return $groups;
}
