param(
    [Parameter(Mandatory = $true)]
    [string]$PhpDir
)

$PhpDir = $PhpDir.TrimEnd('\')
$extDir = ($PhpDir + '\ext') -replace '\\', '\\'

$iniPath = Join-Path $PhpDir 'php.ini'

# target settings (0 = unlimited)
$settings = @(
    @{ Key = 'upload_max_filesize'; Value = 'upload_max_filesize = 0' },
    @{ Key = 'post_max_size';       Value = 'post_max_size = 0' },
    @{ Key = 'max_input_time';      Value = 'max_input_time = -1' },
    @{ Key = 'max_execution_time';  Value = 'max_execution_time = 0' }
)

if (Test-Path -LiteralPath $iniPath) {
    $content = [System.IO.File]::ReadAllText($iniPath)
    $changed = $false
    foreach ($s in $settings) {
        $rx = "(?im)^\s*" + $s.Key + "\s*=\s*([^\r\n;]*)"
        $m = [regex]::Match($content, $rx)
        if ($m.Success) {
            $val = $m.Groups[1].Value.Trim()
            $expected = ($s.Value -split '=', 2)[1].Trim()
            if ($val -ne $expected) {
                $content = [regex]::Replace($content, "(?im)^\s*" + $s.Key + "\s*=\s*[^\r\n;]*", $s.Value)
                $changed = $true
            }
        }
        else {
            $content += [Environment]::NewLine + $s.Value
            $changed = $true
        }
    }
    # ensure zip extension is enabled (needed by showdoc zip import)
    if ((Test-Path -LiteralPath (Join-Path $PhpDir 'ext\php_zip.dll')) -and -not [regex]::IsMatch($content, '(?im)^\s*extension\s*=\s*zip\s*$')) {
        $content += [Environment]::NewLine + 'extension=zip'
        $changed = $true
    }
    if ($changed) {
        [System.IO.File]::WriteAllText($iniPath, $content)
        Write-Host ("Updated upload limits (unlimited) in: " + $iniPath)
    }
    else {
        Write-Host ("php.ini already has unlimited upload settings: " + $iniPath)
    }
}
else {
    $ini = @"
[PHP]
extension_dir = "$extDir"
extension=curl
extension=fileinfo
extension=gd
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip

error_reporting = E_ALL & ~E_DEPRECATED
display_errors = Off
log_errors = On

upload_max_filesize = 0
post_max_size = 0
max_input_time = -1
max_execution_time = 0
memory_limit = 512M

[Date]
date.timezone = Asia/Shanghai
"@
    [System.IO.File]::WriteAllText($iniPath, $ini)
    Write-Host ("Generated: " + $iniPath)
}
