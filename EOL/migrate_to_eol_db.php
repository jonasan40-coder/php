<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this migration from CLI only.');
}

require_once __DIR__ . '/config.php';

$sourceDb = getenv('EOL_SOURCE_DB') ?: ($oldDb ?? 'k5juu_digi');
$targetDb = getenv('EOL_TARGET_DB') ?: ($db ?? 'k5juu_eol');

$baseTables = [
    'users',
    'ttimps210002',
    'eol_product_status',
    'eol_summary',
    'eol_detail',
    'eol_group_members',
    'eol_batches',
    'eol_audit_logs',
    'eol_kubun_master',
];

function migration_pdo(string $host, string $user, string $pass, string $db): PDO
{
    return new PDO('mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function quote_ident(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function table_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . quote_ident($table))->fetchColumn();
}

$source = migration_pdo($host, $user, $pass, $sourceDb);
$target = migration_pdo($host, $user, $pass, $targetDb);
$eolTables = $source->query("SHOW TABLES LIKE 'eol\\_%'")->fetchAll(PDO::FETCH_COLUMN);
$tables = array_values(array_unique(array_merge($baseTables, array_map('strval', $eolTables))));

echo "source={$sourceDb} target={$targetDb}\n";

$target->exec('SET FOREIGN_KEY_CHECKS = 0');
try {
    foreach ($tables as $table) {
        if (!table_exists($source, $table)) {
            echo "skip {$table}: source table not found\n";
            continue;
        }

        $createRow = $source->query('SHOW CREATE TABLE ' . quote_ident($table))->fetch(PDO::FETCH_NUM);
        if ($createRow === false || !isset($createRow[1])) {
            echo "skip {$table}: SHOW CREATE TABLE failed\n";
            continue;
        }

        $target->exec('DROP TABLE IF EXISTS ' . quote_ident($table));
        $target->exec((string) $createRow[1]);
        $target->exec(
            'INSERT INTO ' . quote_ident($targetDb) . '.' . quote_ident($table) .
            ' SELECT * FROM ' . quote_ident($sourceDb) . '.' . quote_ident($table)
        );

        echo "copied {$table}: " . table_count($target, $table) . " rows\n";
    }

    if (!table_exists($target, 'eol_kubun_master')) {
        $target->exec(
            'CREATE TABLE eol_kubun_master (
              id int NOT NULL AUTO_INCREMENT,
              kubun_name varchar(255) NOT NULL,
              sort_order int NOT NULL DEFAULT 0,
              is_active tinyint(1) NOT NULL DEFAULT 1,
              created_at datetime DEFAULT CURRENT_TIMESTAMP,
              updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_eol_kubun_master_name (kubun_name),
              KEY idx_eol_kubun_master_active_order (is_active, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    $kubunStmt = $target->prepare(
        'INSERT INTO eol_kubun_master (kubun_name, sort_order, is_active)
         VALUES (:kubun_name, :sort_order, 1)
         ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = VALUES(is_active)'
    );
    foreach ([
        'カウントダウン数の確認',
        'サービス確認',
        'オーダー見直し/リワーク',
        '専用部品の抽出',
        '不要なオーダーのキャンセル',
        '品目メンテ(ロット見直し含む)',
        '補充オーダー解除(親基板忘れずに)',
        '棚卸',
        '後継機',
        '後継機の先行手配確認',
    ] as $index => $kubun) {
        $kubunStmt->execute([
            'kubun_name' => $kubun,
            'sort_order' => ($index + 1) * 10,
        ]);
    }
    echo 'seeded eol_kubun_master: ' . table_count($target, 'eol_kubun_master') . " rows\n";
} finally {
    $target->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "done\n";
