param(
    [string] $MdbPath = (Join-Path $PSScriptRoot 'EndOfLife.mdb'),
    [string] $OutputDir = (Join-Path $PSScriptRoot 'migration')
)

$resolvedMdb = Resolve-Path $MdbPath
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$connection = New-Object -ComObject ADODB.Connection
$connection.Open("Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$resolvedMdb;Persist Security Info=False;")

function Export-Table {
    param(
        [Parameter(Mandatory=$true)] $Connection,
        [Parameter(Mandatory=$true)] [string] $Sql,
        [Parameter(Mandatory=$true)] [string] $Path
    )

    $rs = $Connection.Execute($Sql)
    $rows = New-Object System.Collections.Generic.List[object]

    while (-not $rs.EOF) {
        $item = [ordered] @{}
        for ($i = 0; $i -lt $rs.Fields.Count; $i++) {
            $item[$rs.Fields.Item($i).Name] = $rs.Fields.Item($i).Value
        }
        $rows.Add([pscustomobject] $item)
        $rs.MoveNext()
    }

    $rs.Close()
    $rows | Export-Csv -Path $Path -Encoding UTF8 -NoTypeInformation
}

$tables = New-Object System.Collections.Generic.List[string]
$schema = $connection.OpenSchema(20)
while (-not $schema.EOF) {
    $tableName = [string] $schema.Fields.Item('TABLE_NAME').Value
    $tableType = [string] $schema.Fields.Item('TABLE_TYPE').Value
    if (($tableType -eq 'TABLE' -or $tableType -eq 'PASS-THROUGH') -and -not $tableName.StartsWith('MSys')) {
        $tables.Add($tableName)
    }
    $schema.MoveNext()
}
$schema.Close()

$productTable = $tables | Where-Object { $_ -eq 'dbo_trnProductStatus' } | Select-Object -First 1
$localTables = @($tables | Where-Object { $_ -ne 'dbo_trnProductStatus' })

if (-not $productTable) {
    throw 'dbo_trnProductStatus was not found.'
}

Export-Table -Connection $connection -Sql "SELECT * FROM [$productTable]" -Path (Join-Path $OutputDir 'eol_product_status.csv')

foreach ($table in $localTables) {
    $probe = $connection.Execute("SELECT TOP 1 * FROM [$table]")
    $columnCount = $probe.Fields.Count
    $probe.Close()

    if ($columnCount -eq 5) {
        Export-Table -Connection $connection -Sql "SELECT * FROM [$table]" -Path (Join-Path $OutputDir 'eol_summary.csv')
    } elseif ($columnCount -eq 10) {
        Export-Table -Connection $connection -Sql "SELECT * FROM [$table]" -Path (Join-Path $OutputDir 'eol_detail.csv')
    }
}

$connection.Close()
