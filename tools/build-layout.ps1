# Builds the official-look preview assets from the xlsx template using Excel (run on Windows with Excel installed).
# Usage: powershell -File tools\build-layout.ps1
param(
  [string]$Root = (Resolve-Path "$PSScriptRoot\..").Path,
  [string]$Work = (Join-Path $env:TEMP 'pds-layout')
)
$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force $Work | Out-Null
$template = "$Root\lib\templates\pds-2026.xlsx"
$targets = (& php "$Root\tools\layout-targets.php") -join "`n" | ConvertFrom-Json

# Renders pages 1-4 of a PDF to PNG files named <prefix><n>.png with Windows.Data.Pdf.
function Render-Pdf([string]$Pdf, [string]$Prefix, [int]$Width = 1700) {
  Add-Type -AssemblyName System.Runtime.WindowsRuntime
  $ext = [System.WindowsRuntimeSystemExtensions].GetMethods()
  $op = $ext | Where-Object { $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1' } | Select-Object -First 1
  $act = $ext | Where-Object { $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction' } | Select-Object -First 1
  $await = { param($o, [Type]$t) $task = $op.MakeGenericMethod($t).Invoke($null, @($o)); $task.Wait(); $task.Result }
  [void][Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime]
  [void][Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType = WindowsRuntime]
  [void][Windows.Storage.Streams.IRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime]
  $file = & $await ([Windows.Storage.StorageFile]::GetFileFromPathAsync($Pdf)) ([Windows.Storage.StorageFile])
  $doc = & $await ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
  $dir = & $await ([Windows.Storage.StorageFolder]::GetFolderFromPathAsync((Split-Path $Prefix))) ([Windows.Storage.StorageFolder])
  for ($i = 0; $i -lt [Math]::Min(4, $doc.PageCount); $i++) {
    $page = $doc.GetPage($i)
    $png = & $await ($dir.CreateFileAsync("$(Split-Path $Prefix -Leaf)$($i + 1).png", 'ReplaceExisting')) ([Windows.Storage.StorageFile])
    $stream = & $await ($png.OpenAsync('ReadWrite')) ([Windows.Storage.Streams.IRandomAccessStream])
    $opts = New-Object Windows.Data.Pdf.PdfPageRenderOptions
    $opts.DestinationWidth = $Width
    $act.Invoke($null, @($page.RenderToStreamAsync($stream, $opts))).Wait()
    $stream.Dispose()
    $page.Dispose()
  }
}

$xl = New-Object -ComObject Excel.Application
$xl.DisplayAlerts = $false
try {
  $clear = (& php -r "require '$($Root -replace '\\', '/')/lib/pds-xlsx.php'; echo implode(' ', PDS_CLEAR);").Split(' ')
  $wb = $xl.Workbooks.Open($template, 0, $true)
  foreach ($a in $clear) { $s, $r = $a.Split('!'); $wb.Worksheets.Item($s).Range($r).MergeArea.ClearContents() | Out-Null }
  $wb.ExportAsFixedFormat(0, "$Work\blank.pdf", 0, $true, $false, 1, 4)

  # Tick every checkbox; diffing against blank.pdf locates each box exactly.
  foreach ($t in $targets | Where-Object { $_.id -like 'c:*' }) {
    $ws = $wb.Worksheets.Item($t.sheet)
    $shape = $ws.Shapes | Where-Object { try { $_.ControlFormat.LinkedCell -replace '\$', '' -eq $t.ref } catch { $false } } | Select-Object -First 1
    $shape.ControlFormat.Value = 1
  }
  $wb.ExportAsFixedFormat(0, "$Work\checked.pdf", 0, $true, $false, 1, 4)
  $wb.Close($false)
  $wb = $xl.Workbooks.Open($template, 0, $true)
  foreach ($a in $clear) { $s, $r = $a.Split('!'); $wb.Worksheets.Item($s).Range($r).MergeArea.ClearContents() | Out-Null }

  # Paint each target cell a unique color and record geometry in points.
  $geo = @()
  $spans = @{}
  $i = 0
  foreach ($t in $targets) {
    $ws = $wb.Worksheets.Item($t.sheet)
    $g = [ordered]@{ id = $t.id; sheet = $t.sheet; kind = $t.kind }
    if ($t.kind -eq 'control') {
      $shape = $ws.Shapes | Where-Object { try { $_.ControlFormat.LinkedCell -replace '\$', '' -eq $t.ref } catch { $false } } | Select-Object -First 1
      $g.left = $shape.Left; $g.top = $shape.Top; $g.width = $shape.Width; $g.height = $shape.Height
    } elseif ($t.kind -eq 'shape') {
      # Image frames drawn as shapes: anchored to their top-left cell with point offsets for the Excel export.
      $shape = $ws.Shapes.Item($t.ref)
      $cell = $shape.TopLeftCell
      $g.left = $shape.Left; $g.top = $shape.Top; $g.width = $shape.Width; $g.height = $shape.Height
      $g.anchor = @($cell.Address(0, 0), ($shape.Left - $cell.Left), ($shape.Top - $cell.Top))
    } else {
      do { $i++ } while (($i % 11) -eq ([Math]::Floor($i / 11) % 11) -and ($i % 11) -eq [Math]::Floor($i / 121))
      $r = 30 + ($i % 11) * 20; $gr = 30 + ([Math]::Floor($i / 11) % 11) * 20; $b = 30 + [Math]::Floor($i / 121) * 20
      # Match first-row merges like pds_export does, since some template rows lack them.
      if ($t.id -match '^t:(\w+):(\d+):(\w+)$') {
        $key = "$($Matches[1]):$($Matches[3])"
        if ($Matches[2] -eq '0') { $spans[$key] = $ws.Range($t.ref).MergeArea.Columns.Count }
        elseif ($ws.Range($t.ref).MergeArea.Columns.Count -lt $spans[$key]) { $ws.Range($t.ref).Resize(1, $spans[$key]).Merge() | Out-Null }
      }
      $m = $ws.Range($t.ref).MergeArea
      $m.ClearContents() | Out-Null
      $m.Interior.Color = [int]($r + $gr * 256 + $b * 65536)
      $g.rgb = @($r, $gr, $b); $g.left = $m.Left; $g.top = $m.Top; $g.width = $m.Width; $g.height = $m.Height
      $c = $ws.Range($t.ref)
      $g.font = $c.Font.Size
      $g.face = $c.Font.Name
      $g.align = switch ($c.HorizontalAlignment) { -4108 { 'center' } -4152 { 'right' } default { 'left' } }
      $g.valign = switch ($c.VerticalAlignment) { -4160 { 'top' } -4108 { 'center' } default { 'bottom' } }
      $g.wrap = [bool]$c.WrapText
      if ($t.id -like 's:*') { $g.anchor = @($m.Cells.Item(1, 1).Address(0, 0), 0, 0) }
    }
    $geo += [pscustomobject]$g
  }
  $wb.ExportAsFixedFormat(0, "$Work\colored.pdf", 0, $true, $false, 1, 4)
  $wb.Close($false)
} finally {
  $xl.Quit()
  [void][Runtime.InteropServices.Marshal]::ReleaseComObject($xl)
}

$geo | ConvertTo-Json -Depth 4 | Set-Content -Encoding utf8 "$Work\geometry.json"
Render-Pdf "$Work\blank.pdf" "$Work\blank"
Render-Pdf "$Work\colored.pdf" "$Work\colored"
Render-Pdf "$Work\checked.pdf" "$Work\checked"
& node "$Root\tools\measure-layout.js" $Work "$Root\lib\templates\pds-2026-layout.json"
New-Item -ItemType Directory -Force "$Root\public\pds" | Out-Null
1..4 | ForEach-Object { Copy-Item "$Work\blank$_.png" "$Root\public\pds\page$_.png" -Force }
"Done: public\pds\page1-4.png and lib\templates\pds-2026-layout.json"
