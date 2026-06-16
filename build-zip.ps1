# wp-distributor.zip'i WordPress'in tanıdığı forward-slash yapısıyla paketler.
# Windows'un Compress-Archive'ı ters-slash (\) ürettiği için kullanılamaz;
# bu script entry adlarını elle "/" ile yazar.
#
# Kullanım:  powershell -ExecutionPolicy Bypass -File build-zip.ps1

$ErrorActionPreference = 'Stop'
$base = $PSScriptRoot
$src  = Join-Path $base 'wp-distributor'
$dest = Join-Path $base 'wp-distributor.zip'

if (-not (Test-Path $src)) { throw "Kaynak klasör yok: $src" }
if (Test-Path $dest) { Remove-Item $dest -Force }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($dest, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $srcFull = (Get-Item $src).FullName
    Get-ChildItem -Path $src -Recurse -File | ForEach-Object {
        $rel  = $_.FullName.Substring($srcFull.Length + 1) -replace '\\', '/'
        $name = 'wp-distributor/' + $rel
        $entry = $zip.CreateEntry($name, [System.IO.Compression.CompressionLevel]::Optimal)
        $es = $entry.Open()
        $fs = [System.IO.File]::OpenRead($_.FullName)
        $fs.CopyTo($es)
        $fs.Close()
        $es.Close()
        Write-Host "  + $name"
    }
}
finally {
    $zip.Dispose()
}

# Masaüstüne de kopyala (elle kurulum kolaylığı için)
$desktopCopy = Join-Path ([Environment]::GetFolderPath('Desktop')) 'wp-distributor.zip'
Copy-Item $dest $desktopCopy -Force -ErrorAction SilentlyContinue

$ver = (Select-String -Path (Join-Path $src 'wp-distributor.php') -Pattern "Version:\s*([\d.]+)").Matches[0].Groups[1].Value
Write-Host ""
Write-Host "Tamamlandi: wp-distributor.zip (surum $ver)" -ForegroundColor Green
Write-Host "GitHub Release tag'i: wp-distributor-$ver" -ForegroundColor Cyan
