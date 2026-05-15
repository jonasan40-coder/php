<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$files = [
    'product' => 'C:/Users/di2413/Documents/dbo_trnProductStatus.txt',
    'summary' => 'C:/Users/di2413/Documents/終売案内一覧.txt',
    'detail' => 'C:/Users/di2413/Documents/終売案内詳細.txt',
];
$out = __DIR__ . '/migration/import_eol_data.sql';

function read_csv_assoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("Cannot open {$path}");
    }

    $headers = fgetcsv($fp, 0, ',', '"', '\\');
    if ($headers === false) {
        throw new RuntimeException("No headers in {$path}");
    }
    $headers = array_map('to_utf8', $headers);
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

    $rows = [];
    while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
        $row = array_map('to_utf8', $row);
        $rows[] = array_combine($headers, array_pad($row, count($headers), null));
    }

    fclose($fp);
    return $rows;
}

function to_utf8(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = (string) $value;
    if (preg_match('//u', $value) === 1) {
        return $value;
    }

    $converted = iconv('CP932', 'UTF-8//IGNORE', $value);
    return $converted === false ? $value : $converted;
}

function mysql_value(mixed $value, string $type = 'string'): string
{
    if ($value === null || $value === '') {
        return 'NULL';
    }

    if ($type === 'datetime') {
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? 'NULL' : "'" . date('Y-m-d H:i:s', $timestamp) . "'";
    }

    if ($type === 'date') {
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? 'NULL' : "'" . date('Y-m-d', $timestamp) . "'";
    }

    if ($type === 'number') {
        return is_numeric($value) ? (string) $value : 'NULL';
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function insert_rows($fh, string $table, array $columns, array $rows, array $types, string $duplicateSql): void
{
    if ($rows === []) {
        return;
    }

    fwrite($fh, "\n-- {$table}\n");
    $destColumns = array_values($columns);

    foreach (array_chunk($rows, 200) as $chunk) {
        fwrite($fh, 'INSERT INTO ' . $table . ' (`' . implode('`, `', $destColumns) . "`) VALUES\n");

        $values = [];
        foreach ($chunk as $row) {
            $parts = [];
            foreach ($columns as $source => $dest) {
                $parts[] = mysql_value($row[$source] ?? null, $types[$dest] ?? 'string');
            }
            $values[] = '  (' . implode(', ', $parts) . ')';
        }

        fwrite($fh, implode(",\n", $values) . "\n{$duplicateSql};\n");
    }
}

$productRows = read_csv_assoc($files['product']);
$summaryRows = read_csv_assoc($files['summary']);
$detailRows = read_csv_assoc($files['detail']);

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}

$fh = fopen($out, 'wb');
if ($fh === false) {
    throw new RuntimeException("Cannot write {$out}");
}

fwrite($fh, "SET NAMES utf8mb4;\nSET time_zone = '+09:00';\nSTART TRANSACTION;\n");

insert_rows($fh, 'eol_product_status', [
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
], $productRows, [
    'prod_discon_date' => 'datetime',
    'stock_money' => 'number',
    'total_stock' => 'number',
    'sale_plan' => 'number',
    'product_plan' => 'number',
    'disp_plan' => 'number',
    'stock_001' => 'number',
    'stock_002' => 'number',
    'ins_datetime' => 'datetime',
], 'ON DUPLICATE KEY UPDATE status=VALUES(status), status_nm=VALUES(status_nm), plu_name=VALUES(plu_name), reason=VALUES(reason), prod_discon_date=VALUES(prod_discon_date), stock_money=VALUES(stock_money), total_stock=VALUES(total_stock), sale_plan=VALUES(sale_plan), product_plan=VALUES(product_plan), disp_plan=VALUES(disp_plan), stock_001=VALUES(stock_001), signal_001=VALUES(signal_001), stock_002=VALUES(stock_002), signal_002=VALUES(signal_002), ins_datetime=VALUES(ins_datetime), ins_user=VALUES(ins_user), request_no=VALUES(request_no), notes_link=VALUES(notes_link)');

insert_rows($fh, 'eol_summary', [
    'ID' => 'id',
    '製品番号' => 'product_no',
    '製品名' => 'product_name',
    '登録日' => 'registered_at',
    '理由' => 'reason',
], $summaryRows, [
    'id' => 'number',
    'registered_at' => 'datetime',
], 'ON DUPLICATE KEY UPDATE product_no=VALUES(product_no), product_name=VALUES(product_name), registered_at=VALUES(registered_at), reason=VALUES(reason)');

insert_rows($fh, 'eol_detail', [
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
], $detailRows, [
    'id' => 'number',
    'due_date' => 'date',
    'created_at' => 'datetime',
    'updated_at' => 'datetime',
], 'ON DUPLICATE KEY UPDATE product_no=VALUES(product_no), kubun=VALUES(kubun), assignee=VALUES(assignee), due_date=VALUES(due_date), comment=VALUES(comment), detail=VALUES(detail), status=VALUES(status), created_at=VALUES(created_at), updated_at=VALUES(updated_at)');

fwrite($fh, "\nCOMMIT;\n");
fclose($fh);

echo "Wrote {$out}\n";
echo 'product=' . count($productRows) . ' summary=' . count($summaryRows) . ' detail=' . count($detailRows) . PHP_EOL;
