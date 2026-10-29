# Builds public/app.css from tools/css/app.css with the standalone Tailwind CLI and daisyUI (no npm).
# Pinned downloads go to tools/bin (not committed) and are checked against known SHA-256 hashes.
param([switch]$Watch)
$ErrorActionPreference = 'Stop'
$bin = Join-Path $PSScriptRoot 'bin'
$files = @{
  'tailwindcss.exe' = @('https://github.com/tailwindlabs/tailwindcss/releases/download/v4.3.3/tailwindcss-windows-x64.exe', 'e0e260ce048014e9268f6237ff18f8ccf02cef521cbd0ae04e82c2cdf7aa3955')
  'daisyui.mjs' = @('https://github.com/saadeghi/daisyui/releases/download/v5.7.50/daisyui.mjs', 'd69de07c7463bb1f41548e2688f88ee105cdadf9779fce5b3c47b3bfaa6be472')
  'daisyui-theme.mjs' = @('https://github.com/saadeghi/daisyui/releases/download/v5.7.50/daisyui-theme.mjs', 'e48a35d8326d650181ca600560e8520cf6b44cf9242bbd9d45825607e641f251')
}
New-Item -ItemType Directory -Force $bin | Out-Null
foreach ($name in $files.Keys) {
  $path = Join-Path $bin $name
  [string]$url, [string]$hash = $files[$name]
  if (-not (Test-Path $path)) { Invoke-WebRequest $url -OutFile $path -UseBasicParsing }
  if ((Get-FileHash $path -Algorithm SHA256).Hash -ne $hash) { Remove-Item $path; throw "Checksum mismatch for $name; the download was removed." }
}
$cliArgs = @('-i', (Join-Path $PSScriptRoot 'css\app.css'), '-o', (Join-Path $PSScriptRoot '..\public\app.css'), '--minify')
if ($Watch) { $cliArgs += '--watch' }
& (Join-Path $bin 'tailwindcss.exe') @cliArgs
