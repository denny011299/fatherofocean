# Gap-fill ONLY missing app/ files from Cursor Local History (pre-June-20).
# Does NOT overwrite existing files. Does NOT backup entire app/.
param(
    [switch]$UseLatest,
    [switch]$WhatIf
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = 'C:\Pelayaran'
$HistoryRoot = "$env:APPDATA\Cursor\User\History"
$AppDir = Join-Path $ProjectRoot 'app'
$Cutoff = [DateTimeOffset]::Parse('2026-06-20T00:00:00+07:00').ToUnixTimeMilliseconds()
$ReportFile = Join-Path $ProjectRoot 'tools\restore-app-gap-fill-report.txt'

if (-not (Test-Path $HistoryRoot)) {
    Write-Error "Cursor history not found: $HistoryRoot"
}

# Build history plan: relPath -> best candidate
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

# Collect expected missing targets from restore-app-missing-from-history.txt
$targetList = @()
$missingListFile = Join-Path $ProjectRoot 'tools\restore-app-missing-from-history.txt'
if (Test-Path $missingListFile) {
    $targetList = @(Get-Content $missingListFile | Where-Object { $_.Trim() -ne '' })
}

# Also scan routes for controller references
$routesFile = Join-Path $ProjectRoot 'routes\web.php'
if (Test-Path $routesFile) {
    $routesContent = Get-Content $routesFile -Raw
    $matches = [regex]::Matches($routesContent, 'App\\Http\\Controllers\\([A-Za-z0-9_]+)')
    foreach ($m in $matches) {
        $rel = "Http\Controllers\$($m.Groups[1].Value).php"
        if ($targetList -notcontains $rel) { $targetList += $rel }
    }
}

# Find files missing on disk
$missingOnDisk = @()
foreach ($rel in ($targetList | Sort-Object -Unique)) {
    $dest = Join-Path $AppDir $rel
    if (-not (Test-Path $dest)) {
        $missingOnDisk += $rel
    }
}

# Also: any history entry whose target is missing on disk
foreach ($rel in $plan.Keys) {
    $dest = Join-Path $AppDir $rel
    if (-not (Test-Path $dest) -and ($missingOnDisk -notcontains $rel)) {
        $missingOnDisk += $rel
    }
}

$restored = @()
$noHistory = @()
$skippedExists = @()

foreach ($rel in ($missingOnDisk | Sort-Object -Unique)) {
    $dest = Join-Path $AppDir $rel
    if (Test-Path $dest) {
        $skippedExists += $rel
        continue
    }
    if (-not $plan.ContainsKey($rel)) {
        $noHistory += $rel
        continue
    }
    $pick = $plan[$rel]
    if ($WhatIf) {
        $restored += [PSCustomObject]@{ Rel = $rel; Source = $pick.File; Size = $pick.Size }
        continue
    }
    $destParent = Split-Path $dest -Parent
    if (-not (Test-Path $destParent)) {
        New-Item -ItemType Directory -Path $destParent -Force | Out-Null
    }
    Copy-Item $pick.File $dest -Force
    $restored += [PSCustomObject]@{ Rel = $rel; Source = $pick.File; Size = $pick.Size }
}

$report = @()
$report += "=== Gap-Fill Restore Report $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') ==="
$report += "History entries indexed : $($plan.Count)"
$report += "Missing on disk (targets): $($missingOnDisk.Count)"
$report += "Restored from history   : $($restored.Count)"
$report += "Still no history        : $($noHistory.Count)"
$report += ""
if ($restored.Count -gt 0) {
    $report += "--- RESTORED ---"
    foreach ($r in $restored) {
        $report += "  $($r.Rel) <- $($r.Source) ($($r.Size) bytes)"
    }
    $report += ""
}
if ($noHistory.Count -gt 0) {
    $report += "--- STILL MISSING (no history) ---"
    foreach ($r in ($noHistory | Sort-Object)) { $report += "  $r" }
    $report += ""
}

$report | Set-Content $ReportFile -Encoding UTF8
$report | ForEach-Object { Write-Host $_ }

if (-not $WhatIf) {
    Write-Host ""
    Write-Host "Report: $ReportFile"
}

exit 0
