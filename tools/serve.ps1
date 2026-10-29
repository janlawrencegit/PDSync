# Runs the app locally with PHP's built-in server, enabling the extensions it needs (no php.ini required).
param([string]$Listen = '0.0.0.0:8000')
$ext = Join-Path (Split-Path (Get-Command php).Source) 'ext'
php -d "extension_dir=$ext" -d extension=openssl -d extension=curl -d extension=pdo_mysql -S $Listen -t "$PSScriptRoot\..\public"
