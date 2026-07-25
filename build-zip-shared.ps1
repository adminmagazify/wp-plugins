# wp-distributor'ı forward-slash entry adlarıyla ortak yola paketler.
# $PSScriptRoot kullanır — Türkçe karakterli sabit yol PowerShell 5.1'de bozuluyordu.
# Ortak yol: C:\Users\Public\wpd\wp-distributor.zip = Bash /c/Users/Public/wpd/wp-distributor.zip
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$src = Join-Path $PSScriptRoot "wp-distributor"
$outDir = "C:\Users\Public\wpd"
$out = Join-Path $outDir "wp-distributor.zip"

if (-not (Test-Path $src)) { throw "Kaynak yok: $src" }
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }
if (Test-Path $out) { Remove-Item $out -Force }

$zip = [System.IO.Compression.ZipFile]::Open($out, 'Create')
try {
  $files = Get-ChildItem -Path $src -Recurse -File
  $prefixLen = (Split-Path $src -Parent).Length + 1  # "wp-distributor/" öneki korunsun
  foreach ($f in $files) {
    $rel = $f.FullName.Substring($prefixLen).Replace('\', '/')  # forward-slash ZORUNLU
    $entry = $zip.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)
    $es = $entry.Open()
    $fs = [System.IO.File]::OpenRead($f.FullName)
    try { $fs.CopyTo($es) } finally { $fs.Dispose(); $es.Dispose() }
  }
} finally {
  $zip.Dispose()
}
Write-Output "OK -> $out"
Write-Output ("Dosya sayisi: " + $files.Count)
