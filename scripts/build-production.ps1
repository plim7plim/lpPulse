param([string]$OutputDirectory = 'C:\BLU\pulse-local\releases')
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskRelease = Join-Path ([IO.Path]::GetFullPath($OutputDirectory)) ('pulse-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
if (Test-Path -LiteralPath $taskRelease) { throw 'Destino já existe. Não foi alterado.' }
$taskPublic = Join-Path $taskRelease 'public'
$taskPrivate = Join-Path $taskRelease 'private'
New-Item -ItemType Directory -Path $taskPublic,$taskPrivate | Out-Null
foreach ($taskDirectory in @('assets','css','js','paginas')) {
    Copy-Item -LiteralPath (Join-Path $taskRoot $taskDirectory) -Destination $taskPublic -Recurse
}
foreach ($taskFile in @('index.html','clientes.html','politica-de-cookies.html','.htaccess')) {
    Copy-Item -LiteralPath (Join-Path $taskRoot $taskFile) -Destination $taskPublic
}
New-Item -ItemType Directory -Path (Join-Path $taskPublic 'backend') | Out-Null
Copy-Item -LiteralPath (Join-Path $taskRoot 'backend/src') -Destination (Join-Path $taskPublic 'backend') -Recurse
foreach ($taskFile in @('api.php','webhook.php')) {
    Copy-Item -LiteralPath (Join-Path $taskRoot ('backend/' + $taskFile)) -Destination (Join-Path $taskPublic 'backend')
}
foreach ($taskDirectory in @('docs')) {
    Copy-Item -LiteralPath (Join-Path $taskRoot $taskDirectory) -Destination $taskPrivate -Recurse
}
New-Item -ItemType Directory -Path (Join-Path $taskPrivate 'scripts'),(Join-Path $taskPrivate 'database') | Out-Null
Copy-Item -LiteralPath (Join-Path $taskRoot 'database/migrations') -Destination (Join-Path $taskPrivate 'database') -Recurse
foreach ($taskFile in @('migrate.php','reconcile-sms.php','create-admin.php','check-production.php')) {
    Copy-Item -LiteralPath (Join-Path $taskRoot ('scripts/' + $taskFile)) -Destination (Join-Path $taskPrivate 'scripts')
}
New-Item -ItemType Directory -Path (Join-Path $taskPrivate 'backend') | Out-Null
Copy-Item -LiteralPath (Join-Path $taskRoot 'backend/src') -Destination (Join-Path $taskPrivate 'backend') -Recurse
Copy-Item -LiteralPath (Join-Path $taskRoot 'schema.sql'),(Join-Path $taskRoot 'backend/panel-config.example.php') -Destination $taskPrivate
Compress-Archive -Path (Join-Path $taskRelease '*') -DestinationPath ($taskRelease + '.zip')
Write-Output ('Pacote criado: ' + $taskRelease + '.zip')
Write-Output 'public vai para a pasta pública; private deve ficar fora dela. Nenhuma configuração privada local foi copiada.'
