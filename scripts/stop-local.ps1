$ErrorActionPreference = 'Stop'
$taskRuntime = 'C:\BLU\pulse-local'
$taskPhpPidFile = Join-Path $taskRuntime 'php.pid'
$taskPhpPort = Get-NetTCPConnection -LocalPort 4173 -State Listen -ErrorAction SilentlyContinue
if ($taskPhpPort -and (Test-Path -LiteralPath $taskPhpPidFile)) {
    $taskStoredPid = [int](Get-Content -LiteralPath $taskPhpPidFile -Raw).Trim()
    $taskProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$taskStoredPid"
    if ($taskPhpPort.OwningProcess -ne $taskStoredPid -or $taskProcess.CommandLine -notmatch 'backend[/\\]local-router\.php') { throw 'Processo PHP diferente do ambiente Pulse. Nada foi encerrado.' }
    Stop-Process -Id $taskStoredPid
}
$taskDbPort = Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue
if ($taskDbPort) {
    $taskStoredDb = [int](Get-Content -LiteralPath (Join-Path $taskRuntime 'mariadb.pid') -Raw).Trim()
    if ($taskDbPort.OwningProcess -ne $taskStoredDb) { throw 'Porta 3307 não pertence a este ambiente. Banco preservado.' }
    & (Join-Path $taskRuntime 'mariadb-11.4.11-winx64\bin\mariadb-admin.exe') '--defaults-extra-file=C:/BLU/pulse-private/root-client.ini' shutdown
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB não confirmou o encerramento.' }
}
Write-Output 'Ambiente local encerrado. Dados preservados.'
