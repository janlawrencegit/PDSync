# One-time local setup: creates the pdsync database and user on your MySQL, imports the tables, and writes config.local.php.
# You type the MySQL root password here; it is not saved. The app's own DB password is randomly generated.
$root = Resolve-Path "$PSScriptRoot\.."
$config = Join-Path $root 'config.local.php'
if (Test-Path $config) { Write-Host 'config.local.php already exists; delete it first to redo the setup.'; exit 1 }
$mysql = Get-ChildItem 'C:\Program Files\MySQL\MySQL Server *\bin\mysql.exe' | Sort-Object FullName -Descending | Select-Object -First 1
if (-not $mysql) { Write-Host 'mysql.exe not found under C:\Program Files\MySQL.'; exit 1 }
$port = Read-Host 'MySQL port (Enter for 3306)'
if (-not $port) { $port = '3306' }
$rootPass = Read-Host 'MySQL root password' -AsSecureString
$env:MYSQL_PWD = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($rootPass))
$appPass = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
$sql = "CREATE DATABASE IF NOT EXISTS pdsync CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'pdsync'@'localhost' IDENTIFIED BY '$appPass'; ALTER USER 'pdsync'@'localhost' IDENTIFIED BY '$appPass'; GRANT ALL ON pdsync.* TO 'pdsync'@'localhost';"
$conn = @('--user=root', '--host=localhost', "--port=$port")
& $mysql.FullName @conn -e $sql
if ($LASTEXITCODE) { Remove-Item Env:MYSQL_PWD; exit 1 }
Get-Content (Join-Path $root 'sql\schema.sql') -Raw | & $mysql.FullName @conn pdsync
Remove-Item Env:MYSQL_PWD
$php = @"
<?php
// Local settings written by tools/setup-local.ps1; never commit this file.
return [
    'APP_URL' => 'http://localhost:8000',
    'DB_DSN' => 'mysql:host=localhost;port=$port;dbname=pdsync;charset=utf8mb4',
    'DB_USER' => 'pdsync',
    'DB_PASS' => '$appPass',
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
];
"@
# Written without a BOM, since PHP would print one before every page.
[IO.File]::WriteAllText($config, $php)
$ext = Join-Path (Split-Path (Get-Command php).Source) 'ext'
php -d "extension_dir=$ext" -d extension=pdo_mysql (Join-Path $root 'tools\migrate-json.php')
Write-Host 'Done. Database pdsync is ready, config.local.php was written, and old saved PDS were copied in. Next: tools\serve.ps1'
