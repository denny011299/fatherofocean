# Restore app/ PHP files from Cursor Local History (pre-June-20 snapshots).
# Usage: powershell -ExecutionPolicy Bypass -File tools\restore-app-from-cursor-history.ps1
# Optional: -UseLatest   pick latest pre-June-20 entry (default: largest file size)
# Optional: -WhatIf      show plan only, do not copy

param(
    [switch]$UseLatest,
    [switch]$WhatIf
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = 'C:\Pelayaran'
$HistoryRoot = "$env:APPDATA\Cursor\User\History"
$AppDir = Join-Path $ProjectRoot 'app'
$Cutoff = [DateTimeOffset]::Parse('2026-06-20T00:00:00+07:00').ToUnixTimeMilliseconds()

if (-not (Test-Path $HistoryRoot)) {
    Write-Error "Cursor history not found: $HistoryRoot"
}

$plan = @{}
Get-ChildItem $HistoryRoot -Recurse -Filter 'entries.json' | ForEach-Object {
    try {
        $json = Get-Content $_.FullName -Raw | ConvertFrom-Json
        if ($json.resource -notmatch 'file:///c%3A/Pelayaran/app/') { return }

        $relPath = [System.Uri]::UnescapeDataString($json.resource) `
            -replace '^file:///c:/Pelayaran/app/', '' -replace '/', '\'

        $candidates = @()
        foreach ($entry in @($json.entries)) {
            if ($entry.timestamp -ge $Cutoff) { continue }
            $histFile = Join-Path $_.DirectoryName $entry.id
            if (-not (Test-Path $histFile)) { continue }
            $candidates += [PSCustomObject]@{
                File      = $histFile
                Size      = (Get-Item $histFile).Length
                Timestamp = $entry.timestamp
            }
        }
        if (-not $candidates) { return }

        $pick = if ($UseLatest) {
            $candidates | Sort-Object Timestamp -Descending | Select-Object -First 1
        } else {
            $candidates | Sort-Object Size -Descending | Select-Object -First 1
        }

        $plan[$relPath] = $pick
    } catch {
        Write-Warning "Skip $($_.FullName): $_"
    }
}

$currentFiles = @(Get-ChildItem $AppDir -Recurse -File | ForEach-Object {
    $_.FullName.Substring("$AppDir\".Length)
})

Write-Host "=== Cursor History Restore Plan ==="
Write-Host "History entries found : $($plan.Count)"
Write-Host "Current app/ files    : $($currentFiles.Count)"
Write-Host "Missing from history  : $($currentFiles.Count - $plan.Count)"
Write-Host "Strategy              : $(if ($UseLatest) { 'latest pre-June-20' } else { 'largest pre-June-20' })"
Write-Host ""

if ($WhatIf) {
    $plan.GetEnumerator() | Sort-Object Key | ForEach-Object {
        Write-Host ("  {0} <- {1} ({2} bytes)" -f $_.Key, $_.Value.File, $_.Value.Size)
    }
    exit 0
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $ProjectRoot "app.pre-restore-$stamp"
Write-Host "Backing up current app/ to $backupDir"
Copy-Item $AppDir $backupDir -Recurse -Force

$restored = 0
foreach ($kv in $plan.GetEnumerator()) {
    $dest = Join-Path $AppDir $kv.Key
    $destParent = Split-Path $dest -Parent
    if (-not (Test-Path $destParent)) {
        New-Item -ItemType Directory -Path $destParent -Force | Out-Null
    }
    Copy-Item $kv.Value.File $dest -Force
    $restored++
}

Write-Host "Restored $restored files from Cursor Local History."

$missing = $currentFiles | Where-Object { -not $plan.ContainsKey($_) } | Sort-Object
$missingList = Join-Path $ProjectRoot "tools\restore-app-missing-from-history.txt"
$missing | Set-Content $missingList -Encoding UTF8
Write-Host "Wrote missing-file list: $missingList ($($missing.Count) files)"

Write-Host ""
Write-Host "Next: php -l sweep + php tools/verify-deploy.php"
