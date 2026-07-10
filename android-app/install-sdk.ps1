# Android SDK installer with BITS download progress
param([string]$SdkRoot = "$env:LOCALAPPDATA\Android\Sdk")
$ErrorActionPreference = "Stop"

function Format-Bytes([long]$bytes) {
    if ($bytes -ge 1GB) { return ("{0:N2} GB" -f ($bytes / 1GB)) }
    if ($bytes -ge 1MB) { return ("{0:N2} MB" -f ($bytes / 1MB)) }
    if ($bytes -ge 1KB) { return ("{0:N2} KB" -f ($bytes / 1KB)) }
    return "$bytes B"
}

function Download-WithBits {
    param([string]$Url, [string]$OutFile, [string]$Label)

    if (Test-Path $OutFile) { Remove-Item $OutFile -Force }

    Write-Host ""
    Write-Host "========================================"
    Write-Host " $Label"
    Write-Host "========================================"
    Write-Host "URL : $Url"
    Write-Host "File: $OutFile"
    Write-Host ""
    Write-Host "Starting download (BITS shows progress below)..."
    Write-Host ""

    $job = Start-BitsTransfer -Source $Url -Destination $OutFile -DisplayName $Label -Description "Android SDK download" -Asynchronous

    while ($job.JobState -eq "Transferring" -or $job.JobState -eq "Connecting") {
        $done = $job.BytesTransferred
        $total = $job.BytesTotal
        $totalOk = ($total -gt 0) -and ($total -lt 1000000000000)
        if ($totalOk) {
            $pct = [math]::Round(100.0 * $done / $total, 1)
            $doneStr = if ($done -ge 1MB) { "{0:N2} MB" -f ($done/1MB) } else { "{0:N0} KB" -f ($done/1KB) }
            $totalStr = if ($total -ge 1MB) { "{0:N2} MB" -f ($total/1MB) } else { "{0:N0} KB" -f ($total/1KB) }
            Write-Host ("  {0}%  {1} / {2}" -f $pct, $doneStr, $totalStr)
        } else {
            $doneStr = if ($done -ge 1MB) { "{0:N2} MB" -f ($done/1MB) } else { "{0:N0} KB" -f ($done/1KB) }
            Write-Host ("  Downloaded: {0}" -f $doneStr)
        }
        Start-Sleep -Seconds 2
    }

    Complete-BitsTransfer -BitsJob $job

    if ($job.JobState -ne "Transferred") {
        throw "Download failed: $($job.JobState)"
    }

    $size = (Get-Item $OutFile).Length
    Write-Host ""
    Write-Host "Download complete: $(Format-Bytes $size)" -ForegroundColor Green
}

function Run-SdkManager {
    param([string[]]$Packages)
    $sdkmanager = Join-Path $SdkRoot "cmdline-tools\latest\bin\sdkmanager.bat"
    if (-not (Test-Path $sdkmanager)) { throw "sdkmanager not found: $sdkmanager" }

    Write-Host ""
    Write-Host "========================================"
    Write-Host " Step 2/2 - Installing SDK packages"
    Write-Host "========================================"
    foreach ($pkg in $Packages) { Write-Host "  - $pkg" }
    Write-Host ""

    $argLine = "--sdk_root=`"$SdkRoot`" " + ($Packages -join ' ')
    $cmd = "echo y| `"$sdkmanager`" $argLine"
    $proc = Start-Process -FilePath "cmd.exe" -ArgumentList "/c $cmd" -Wait -PassThru -NoNewWindow
    if ($proc.ExitCode -ne 0) { throw "sdkmanager failed exit $($proc.ExitCode)" }
    Write-Host "SDK packages installed OK" -ForegroundColor Green
}

New-Item -ItemType Directory -Force -Path "$SdkRoot\cmdline-tools\latest" | Out-Null

$cmdlineUrl = "https://dl.google.com/android/repository/commandlinetools-win-11076708_latest.zip"
$cmdlineZip = Join-Path $env:TEMP "android-cmdline-tools.zip"

Download-WithBits -Url $cmdlineUrl -OutFile $cmdlineZip -Label "Android Command Line Tools (~150 MB)"

Write-Host ""
Write-Host "Extracting zip..."
$extractDir = Join-Path $env:TEMP "android-cmdline-tools-extract"
if (Test-Path $extractDir) { Remove-Item $extractDir -Recurse -Force }
Expand-Archive -Path $cmdlineZip -DestinationPath $extractDir -Force
Copy-Item -Recurse -Force (Join-Path $extractDir "cmdline-tools\*") "$SdkRoot\cmdline-tools\latest\"
Write-Host "Extract OK" -ForegroundColor Green

Run-SdkManager -Packages @("platform-tools", "platforms;android-34", "build-tools;34.0.0")

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host " SDK READY: $SdkRoot"
Write-Host "========================================" -ForegroundColor Green
Get-ChildItem $SdkRoot | ForEach-Object { Write-Host "  $($_.Name)" }
