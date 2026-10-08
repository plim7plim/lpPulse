$ErrorActionPreference = 'Stop'
$taskProject = Split-Path -Parent $PSScriptRoot
$taskRuntime = 'C:\BLU\pulse-local'
$taskConfig = 'C:\BLU\pulse-private\panel-local.php'
$taskDbExe = Join-Path $taskRuntime 'mariadb-11.4.11-winx64\bin\mariadbd.exe'
if (-not (Test-Path -LiteralPath $taskConfig) -or -not (Test-Path -LiteralPath $taskDbExe)) { throw 'Ambiente local não preparado. Veja docs/banco-local.md.' }
$taskDbPort = Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue
if (-not $taskDbPort) {
    $taskDb = Start-Process -FilePath $taskDbExe -ArgumentList @('--defaults-file=C:/BLU/pulse-local/my.ini','--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntime 'mariadb.log') -RedirectStandardError (Join-Path $taskRuntime 'mariadb-stderr.log')
    Set-Content -LiteralPath (Join-Path $taskRuntime 'mariadb.pid') -Value $taskDb.Id -Encoding ascii
}
$taskPhpExe = (Get-Command php).Source
$taskPhpPort = Get-NetTCPConnection -LocalPort 4173 -State Listen -ErrorAction SilentlyContinue
if ($taskPhpPort) {
    $taskPidFile = Join-Path $taskRuntime 'php.pid'
    if (-not (Test-Path -LiteralPath $taskPidFile) -or [int](Get-Content -LiteralPath $taskPidFile -Raw).Trim() -ne $taskPhpPort.OwningProcess) { throw 'Porta 4173 ocupada por outro processo. Não foi encerrado.' }
} else {
    $taskPreviousConfig = $env:PULSE_CONFIG_PATH
    try {
        $env:PULSE_CONFIG_PATH = $taskConfig
        $taskPhp = Start-Process -FilePath $taskPhpExe -ArgumentList @('-c','C:/BLU/pulse-local/php.ini','-S','127.0.0.1:4173','-t',$taskProject,(Join-Path $taskProject 'backend/local-router.php')) -WorkingDirectory $taskProject -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRuntime 'php.log') -RedirectStandardError (Join-Path $taskRuntime 'php-stderr.log')
        Set-Content -LiteralPath (Join-Path $taskRuntime 'php.pid') -Value $taskPhp.Id -Encoding ascii
    } finally { $env:PULSE_CONFIG_PATH = $taskPreviousConfig }
}
for ($taskAttempt=0; $taskAttempt -lt 20; $taskAttempt++) {
    try {
        $taskSession = Invoke-RestMethod 'http://127.0.0.1:4173/backend/api.php?action=session' -TimeoutSec 2
        if ($taskSession.ok -and $taskSession.data.test_environment) { Write-Output 'Pulse local pronto: http://127.0.0.1:4173/paginas/clientes.html'; exit 0 }
    } catch { }
    Start-Sleep -Milliseconds 250
}
throw 'A API local não respondeu. Consulte C:\BLU\pulse-local\php-error.log.'
