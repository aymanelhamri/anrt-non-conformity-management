# Lanceur de l'environnement local : start demarre MySQL et PHP, stop les arrete,
# status affiche leur etat. Il ne cree pas de nouveaux comptes utilisateur.
param(
    [ValidateSet('start', 'stop', 'status')]
    [string] $Action = 'start'
)

$ErrorActionPreference = 'Stop'
# Les outils sont dans .tools/ ; les donnees MySQL, journaux et configurations
# privees sont dans instance/mysql-local/. Ces dossiers sont ignores par Git.
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtimeDirectory = Join-Path $projectRoot 'instance\mysql-local'
$mysqlExecutable = Join-Path $projectRoot '.tools\mysql-8.4.13-winx64\bin\mysqld.exe'
$mysqlAdmin = Join-Path $projectRoot '.tools\mysql-8.4.13-winx64\bin\mysqladmin.exe'
$phpExecutable = Join-Path $projectRoot '.tools\php\php.exe'
$mysqlConfiguration = Join-Path $runtimeDirectory 'my.ini'
# admin.ini fournit le compte MySQL necessaire a un arret propre du serveur.
$clientConfiguration = Join-Path $runtimeDirectory 'admin.ini'
# Ce fichier retient le numero et l'heure de creation du processus PHP lance ici.
$phpStatePath = Join-Path $runtimeDirectory 'php-process.json'

function Test-LocalPort([int] $Port) {
    # Verifier si un serveur accepte une connexion TCP sur ce poste.
    # Cette sonde ne teste pas les identifiants MySQL ni ceux du formulaire.
    $probe = [System.Net.Sockets.TcpClient]::new()
    try {
        $attempt = $probe.ConnectAsync('127.0.0.1', $Port)
        return $attempt.Wait(300) -and $probe.Connected
    } catch { return $false } finally { $probe.Dispose() }
}

function Wait-LocalPort([int] $Port) {
    # Le lancement prend un peu de temps : patienter au plus 30 secondes.
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    do {
        if (Test-LocalPort $Port) { return }
        Start-Sleep -Milliseconds 200
    } while ([DateTime]::UtcNow -lt $deadline)
    throw "Le serveur local sur le port $Port ne repond pas. Consultez instance/mysql-local/."
}

function Get-OwnedPhpProcess {
    # Un numero de processus peut etre reutilise par Windows. Comparer aussi le
    # programme et son heure de depart pour reconnaitre notre serveur PHP.
    if (-not (Test-Path -LiteralPath $phpStatePath)) { return $null }
    $state = Get-Content -LiteralPath $phpStatePath -Raw -Encoding UTF8 | ConvertFrom-Json
    $process = Get-Process -Id $state.id -ErrorAction SilentlyContinue
    if ($process -and $process.Path -eq $phpExecutable -and
        $process.StartTime.ToUniversalTime().ToString('o') -eq $state.started) {
        return $process
    }
    return $null
}

function Get-OwnedMysqlProcess {
    # MySQL ecrit son numero de processus dans mysql.pid. Verifier le programme
    # associe permet de retrouver l'instance portable de ce projet.
    $mysqlPidFile = Join-Path $runtimeDirectory 'mysql.pid'
    if (-not (Test-Path -LiteralPath $mysqlPidFile)) { return $null }
    $mysqlProcessId = [int](Get-Content -LiteralPath $mysqlPidFile -Raw).Trim()
    $process = Get-Process -Id $mysqlProcessId -ErrorAction SilentlyContinue
    if ($process -and $process.Path -eq $mysqlExecutable) { return $process }
    return $null
}

if ($Action -eq 'status') {
    # Afficher les processus reconnus sans les demarrer ni les arreter.
    Write-Output ('MySQL : ' + $(if (Get-OwnedMysqlProcess) { 'demarre sur 127.0.0.1:3307' } else { 'arrete' }))
    Write-Output ('Application : ' + $(if (Get-OwnedPhpProcess) { 'http://127.0.0.1:5000/connexion' } else { 'arretee' }))
    exit 0
}

if ($Action -eq 'stop') {
    # Arreter PHP, puis demander a MySQL de terminer ses ecritures avant de sortir.
    # L'arret du serveur conserve les tables et leurs comptes sur le disque.
    $phpProcess = Get-OwnedPhpProcess
    if ($phpProcess) { Stop-Process -InputObject $phpProcess; $phpProcess.WaitForExit(5000) | Out-Null }
    $mysqlProcess = Get-OwnedMysqlProcess
    if ($mysqlProcess) {
        if (-not (Test-Path -LiteralPath $clientConfiguration)) { throw 'Configuration privee admin.ini absente.' }
        & $mysqlAdmin "--defaults-extra-file=$clientConfiguration" --connect-timeout=5 shutdown
        if ($LASTEXITCODE -ne 0) { throw 'MySQL ne confirme pas son arret.' }
        # Attendre sa fermeture complete permet de relancer le serveur aussitot.
        if (-not $mysqlProcess.WaitForExit(30000)) { throw 'MySQL termine encore son arret ; reessayez dans quelques instants.' }
    }
    Write-Output 'Application et MySQL locaux arretes. Les donnees sont conservees.'
    exit 0
}

# Pour start, les outils et la configuration doivent avoir ete prepares auparavant.
foreach ($requiredPath in @($mysqlExecutable, $phpExecutable, $mysqlConfiguration)) {
    if (-not (Test-Path -LiteralPath $requiredPath)) { throw "Fichier requis absent : $requiredPath" }
}

if (-not (Get-OwnedMysqlProcess)) {
    # Ne pas utiliser le port d'un autre programme. my.ini fixe le port 3307,
    # l'adresse 127.0.0.1 et le dossier contenant les fichiers de la base.
    if (Test-LocalPort 3307) { throw 'Le port 3307 est deja utilise par un autre processus.' }
    $mysqlArguments = @("--defaults-file=`"$mysqlConfiguration`"")
    # Ce fichier prive sert seulement au tout premier lancement de MySQL.
    # Il a ete retire apres la preparation des acces administrateur.
    $bootstrapPath = Join-Path $runtimeDirectory 'bootstrap.sql'
    if (Test-Path -LiteralPath $bootstrapPath) { $mysqlArguments += "--init-file=`"$bootstrapPath`"" }
    Start-Process -FilePath $mysqlExecutable -ArgumentList $mysqlArguments -WorkingDirectory $projectRoot -WindowStyle Hidden | Out-Null
}
Wait-LocalPort 3307
Write-Output 'MySQL local : 127.0.0.1:3307'

if (-not (Test-Path -LiteralPath (Join-Path $projectRoot '.env'))) {
    # MySQL peut fonctionner avant que la base et les comptes applicatifs soient crees.
    Write-Output 'Initialisez la base avec .\.tools\php\php.exe bin/setup-local.php, puis relancez ce script.'
    exit 0
}

if (-not (Get-OwnedPhpProcess)) {
    # Le serveur PHP ecoute sur le port 5000. Seul public/ est expose aux requetes,
    # et public/index.php choisit la page a afficher, dont /connexion.
    if (Test-LocalPort 5000) { throw 'Le port 5000 est deja utilise par un autre processus.' }
    $phpArguments = @('-S', '127.0.0.1:5000', '-t', "`"$(Join-Path $projectRoot 'public')`"", "`"$(Join-Path $projectRoot 'public\index.php')`"")
    # Lancer en arriere-plan avec les messages ecrits dans des journaux prives.
    $phpProcess = Start-Process -FilePath $phpExecutable -ArgumentList $phpArguments -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $runtimeDirectory 'php-output.log') -RedirectStandardError (Join-Path $runtimeDirectory 'php-error.log')
    @{ id = $phpProcess.Id; started = $phpProcess.StartTime.ToUniversalTime().ToString('o') } |
        ConvertTo-Json | Set-Content -LiteralPath $phpStatePath -Encoding UTF8
}
Wait-LocalPort 5000
Write-Output 'Connexion : http://127.0.0.1:5000/connexion'
