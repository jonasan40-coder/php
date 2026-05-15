<?php
declare(strict_types=1);

require_once __DIR__ . '/eol_db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$baseDir = $argv[1] ?? (__DIR__ . '/migration');
$schemaPath = __DIR__ . '/schema.sql';
$pdo = eol_pdo();

if (!is_file($schemaPath)) {
    throw new RuntimeException('schema.sql not found.');
}

$schemaSql = (string) file_get_contents($schemaPath);
foreach (preg_split('/;\s*(?:\r?\n|$)/', $schemaSql) ?: [] as $statement) {
    $statement = trim($statement);
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}

function import_csv(PDO $pdo, string $path, string $table, array $columns, array $dateColumns = []): int
{
    if (!is_file($path)) {
        return 0;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Cannot open {$path}");
    }

    $headers = fgetcsv($handle);
    if ($headers === false) {
        fclose($handle);
        return 0;
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

    $columnNames = array_values($columns);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $columnNames),
        implode(', ', array_fill(0, count($columnNames), '?'))
    );
    $stmt = $pdo->prepare($sql);
    $count = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $source = array_combine($headers, $row);
        if ($source === false) {
            continue;
        }

        $values = [];
        foreach ($columns as $sourceName => $destName) {
            $value = $source[$sourceName] ?? null;
            if ($value === '') {
                $value = null;
            }
            if ($value !== null && in_array($destName, $dateColumns, true)) {
                $timestamp = strtotime((string) $value);
                $value = $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
            }
            $values[] = $value;
        }

        $stmt->execute($values);
        $count++;
    }

    fclose($handle);
    return $count;
}

$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM eol_detail');
    $pdo->exec('DELETE FROM eol_summary');
    $pdo->exec('DELETE FROM eol_product_status');

    $productCount = import_csv($pdo, $baseDir . '/eol_product_status.csv', 'eol_product_status', [
        'RecUNID' => 'rec_unid',
        'PLUcd' => 'plu_cd',
        'Status' => 'status',
        'StatusNm' => 'status_nm',
        'PLUname' => 'plu_name',
        'Reason' => 'reason',
        'ProdDisconDate' => 'prod_discon_date',
        'StockMoney' => 'stock_money',
        'TotalStock' => 'total_stock',
        'SalePlan' => 'sale_plan',
        'ProductPlan' => 'product_plan',
        'DispPlan' => 'disp_plan',
        '001Stock' => 'stock_001',
        '001Signal' => 'signal_001',
        '002Stock' => 'stock_002',
        '002Signal' => 'signal_002',
        'InsDateTime' => 'ins_datetime',
        'InsUser' => 'ins_user',
        'RequestNo' => 'request_no',
        'NotesLink' => 'notes_link',
    ], ['prod_discon_date', 'ins_datetime']);

    $summaryCount = import_csv($pdo, $baseDir . '/eol_summary.csv', 'eol_summary', [
        'ID' => 'id',
        '製品番号' => 'product_no',
        '製品名' => 'product_name',
        '登録日' => 'registered_at',
        '理由' => 'reason',
    ], ['registered_at']);

    $detailCount = import_csv($pdo, $baseDir . '/eol_detail.csv', 'eol_detail', [
        'ID' => 'id',
        '製品番号' => 'product_no',
        '区分' => 'kubun',
        '担当者' => 'assignee',
        '期限' => 'due_date',
        'コメント' => 'comment',
        '詳細' => 'detail',
        'ステータス' => 'status',
        '登録日' => 'created_at',
        '更新日' => 'updated_at',
    ], ['due_date', 'created_at', 'updated_at']);

    $pdo->commit();
    echo "Imported product_status={$productCount}, summary={$summaryCount}, detail={$detailCount}\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
