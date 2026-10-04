# CS-PLAN-081 H-T10: synthetic whole-instance recovery, never a production restore.
[CmdletBinding()]
param([switch]$SelfTest)

$ErrorActionPreference = 'Stop'

function Get-SanitizedArguments {
    param([string[]]$Arguments)
    # Element-level redaction: standalone or embedded password-bearing tokens,
    # including "-p<value>" inside interpolated dump/restore shell strings.
    return @($Arguments | ForEach-Object {
        if ($_ -match '(?i)(password|pass=|OC_PASS=|(?:^|\s)-p\w)') { '[redacted]' } else { $_ }
    })
}

function Invoke-OwnedCleanup {
    param([string[]]$Names, [string]$Network, [scriptblock]$Operation)
    $failures = [Collections.Generic.List[object]]::new()
    foreach ($name in $Names) {
        try { $null = & $Operation -Arguments @('rm', '-f', '-v', $name) }
        catch { $failures.Add($_) }
    }
    if ($Network) {
        try { $null = & $Operation -Arguments @('network', 'rm', $Network) }
        catch { $failures.Add($_) }
    }
    return ,$failures
}

function Wait-OwnedDatabase {
    param([string]$Name, [scriptblock]$Probe, [scriptblock]$Delay)
    $arguments = @('exec', $Name, 'mysql', '--protocol=TCP', '--host=127.0.0.1',
        '--user=nextcloud', '--password=nextcloud', '--database=nextcloud', '--execute=SELECT 1')
    for ($i = 0; $i -lt 60; $i++) {
        if ((& $Probe -Arguments $arguments -Attempt $i) -eq 0) { return }
        & $Delay
    }
    throw 'Owned recovery DB failed to become ready for authenticated TCP queries.'
}

if ($SelfTest) {
    $seen = [Collections.Generic.List[string]]::new()
    $failures = Invoke-OwnedCleanup @('first', 'second') 'owned-network' {
        param([string[]]$Arguments)
        $seen.Add($Arguments[-1])
        if ($Arguments[-1] -eq 'first') { throw 'Synthetic first cleanup failure' }
    }
    if ($failures.Count -ne 1 -or ($seen -join ',') -ne 'first,second,owned-network') {
        throw 'Cleanup must retain its failure and still attempt every owned resource.'
    }
    'Recovery cleanup failure-path self-test: PASS'
    $probes = [Collections.Generic.List[int]]::new()
    Wait-OwnedDatabase 'owned-db' {
        param([string[]]$Arguments, [int]$Attempt)
        foreach ($required in @('--protocol=TCP', '--host=127.0.0.1', '--user=nextcloud',
            '--password=nextcloud', '--database=nextcloud', '--execute=SELECT 1')) {
            if ($required -notin $Arguments) { throw "Missing authenticated TCP readiness argument: $required" }
        }
        $probes.Add($Attempt)
        # A temporary socket-only server cannot satisfy this TCP/application query.
        if ($Attempt -eq 0) { return 1 }
        return 0
    } {}
    if (($probes -join ',') -ne '0,1') { throw 'Readiness must retry rejected initialization queries.' }
    $exhausted = $false
    try { Wait-OwnedDatabase 'owned-db' { return 1 } {} }
    catch { $exhausted = $_.Exception.Message -eq 'Owned recovery DB failed to become ready for authenticated TCP queries.' }
    if (-not $exhausted) { throw 'Persistent TCP/authentication failure must reject readiness.' }
    'Recovery authenticated TCP readiness self-test: PASS'
    $sanitizedDump = Get-SanitizedArguments @('exec', 'owned-db', 'sh', '-ec',
        'mysqldump -uroot -proot --single-transaction nextcloud > /tmp/recovery.sql')
    if ($sanitizedDump[-1] -ne '[redacted]') { throw 'Embedded password tokens in shell strings must be redacted.' }
    $sanitizedVersion = Get-SanitizedArguments @('exec', 'owned-db', 'mysql', '-uroot', '-N', '-e', 'SELECT VERSION();')
    if ($sanitizedVersion -contains '[redacted]') { throw 'Non-password arguments must not be redacted.' }
    'Recovery log sanitization self-test: PASS'
    return
}

$repo = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path -LiteralPath (Join-Path $repo 'private'))) {
    throw 'The repository private/ parent must already exist.'
}
$prefix = 'cs081-recovery-' + [Guid]::NewGuid().ToString('N').Substring(0, 12)
$evidence = Join-Path $repo "private\$prefix"
$null = New-Item -ItemType Directory -Path $evidence
[IO.File]::WriteAllText((Join-Path $evidence 'owned'), $prefix)
$created = [Collections.Generic.List[string]]::new()
$networkCreated = $false
$failure = $null
$summary = $null
$start = Get-Date
"Recovery target: $prefix"

function Invoke-Docker {
    param([string[]]$Arguments, [int]$ExpectedExitCode = 0)
    $ErrorActionPreference = 'Continue'
    $output = & docker @Arguments 2>&1
    $exitCode = $LASTEXITCODE
    $sanitized = Get-SanitizedArguments $Arguments
    [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'),
        '> docker ' + ($sanitized -join ' ') + "`n" + ($output -join "`n") + "`nexit=$exitCode expected=$ExpectedExitCode`n")
    if ($exitCode -ne $ExpectedExitCode) { throw "Docker operation failed (exit $exitCode); private commands.log retains diagnostics." }
    return $output
}

function Run-Container {
    param([string]$Name, [string[]]$Arguments)
    $null = Invoke-Docker (@('create', '--name', $Name) + $Arguments)
    $created.Add($Name)
    $null = Invoke-Docker @('start', $Name)
}

function Invoke-Occ {
    param([string]$Container, [string[]]$Arguments)
    $null = Invoke-Docker (@('exec', '-u', 'www-data', '-w', '/var/www/html', $Container, 'php', 'occ') + $Arguments)
}

try {
    $image = (Invoke-Docker @('inspect', '--format', '{{.Image}}', 'chorestrike-test') | Out-String).Trim()
    if ($image -notmatch '^sha256:[a-f0-9]{64}$') { throw 'Expected the locally built canonical test image.' }
    $null = Invoke-Docker @('network', 'create', '--internal', $prefix)
    $networkCreated = $true
    $db = "$prefix-db"
    Run-Container $db @('--network', $prefix, '-e', 'MYSQL_ROOT_PASSWORD=root', '-e', 'MYSQL_DATABASE=nextcloud',
        '-e', 'MYSQL_USER=nextcloud', '-e', 'MYSQL_PASSWORD=nextcloud', 'mariadb:10.6')
    Wait-OwnedDatabase $db {
        param([string[]]$Arguments, [int]$Attempt)
        $ErrorActionPreference = 'Continue'
        & docker @Arguments 2>$null | Out-Null
        $exitCode = $LASTEXITCODE
        [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'), "Authenticated TCP DB readiness attempt=$Attempt exit=$exitCode`n")
        return $exitCode
    } { Start-Sleep -Seconds 1 }

    # Whitelist runtime/build paths: never copy private/, .git/, browser artifacts or node_modules.
    & tar.exe -czf (Join-Path $evidence 'app.tar.gz') -C $repo appinfo lib templates img l10n js css vendor composer.json composer.lock LICENSE tests/recovery
    [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'), "Runtime whitelist archive (host tar) exit=$LASTEXITCODE`n")
    if ($LASTEXITCODE -ne 0) { throw 'Runtime/build capture failed.' }
    $common = @('--network', $prefix,
        '-e', 'NEXTCLOUD_TEST_ENV=true', '-e', 'NEXTCLOUD_TEST_MODE=true', '-e', 'CS_RECOVERY_REHEARSAL=true',
        '-e', "DB_HOST=$db", '--entrypoint', 'tail', $image, '-f', '/dev/null')
    $source = "$prefix-source"
    Run-Container $source $common
    $null = Invoke-Docker @('exec', $source, 'mkdir', '/evidence')
    $null = Invoke-Docker @('cp', (Join-Path $evidence 'owned'), "${source}:/evidence/owned")
    $null = Invoke-Docker @('cp', (Join-Path $evidence 'app.tar.gz'), "${source}:/tmp/app.tar.gz")
    $null = Invoke-Docker @('exec', $source, 'tar', '-xzf', '/tmp/app.tar.gz', '-C', '/var/www/html/custom_apps/chorestrike')
    $null = Invoke-Docker @('exec', $source, 'ln', '-s', '/var/www/html/custom_apps/chorestrike', '/var/www/html/apps/chorestrike')
    $null = Invoke-Docker @('exec', $source, 'chown', '-R', 'www-data:www-data', '/var/www/html/custom_apps/chorestrike', '/evidence')
    Invoke-Occ $source @('maintenance:install', '--database=mysql', "--database-host=$db", '--database-name=nextcloud',
        '--database-user=nextcloud', '--database-pass=nextcloud', '--admin-user=admin', '--admin-pass=admin')
    Invoke-Occ $source @('app:enable', 'chorestrike')
    foreach ($uid in @('mom', 'dad', 'teen', 'kid', 'grandma', 'friend')) {
        $null = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', '-e', 'OC_PASS=RecoveryTest123!',
            $source, 'php', 'occ', 'user:add', '--password-from-env', $uid)
    }
    $fixture = '/var/www/html/custom_apps/chorestrike/tests/recovery/fixture.php'
    $null = Invoke-Docker -Arguments @('exec', '-u', 'www-data', '-e', 'CS_RECOVERY_REHEARSAL=false',
        $source, 'php', $fixture, 'seed') -ExpectedExitCode 255
    $null = Invoke-Docker @('exec', '-u', 'www-data', $source, 'php', $fixture, 'seed')
    Invoke-Occ $source @('maintenance:mode', '--on')

    # No web process, cron, ports or other jobs exist in this internal-network source.
    $null = Invoke-Docker -Arguments @('exec', $source, 'bash', '-o', 'pipefail', '-ec',
        'false | sort | xargs -r sha256sum > /evidence/rejected-manifest') -ExpectedExitCode 1
    $null = Invoke-Docker @('exec', $source, 'bash', '-o', 'pipefail', '-ec',
        'cd /var/www/html; find . -type f -print0 | sort -z | xargs -0 sha256sum > /evidence/files.sha256; test -s /evidence/files.sha256; tar -czf /evidence/instance.tar.gz .')
    foreach ($file in @('fixture.json', 'before.json', 'files.sha256', 'instance.tar.gz')) {
        $null = Invoke-Docker @('cp', "${source}:/evidence/$file", (Join-Path $evidence $file))
    }
    $dump = 'mysqldump -uroot -proot --single-transaction --hex-blob --order-by-primary --skip-comments --skip-dump-date nextcloud'
    $null = Invoke-Docker @('exec', $db, 'sh', '-ec', "$dump > /tmp/recovery.sql")
    $null = Invoke-Docker @('cp', "${db}:/tmp/recovery.sql", (Join-Path $evidence 'database.sql'))
    $rowsDump = "$dump --no-create-info --skip-add-locks --skip-disable-keys --skip-extended-insert"
    $beforeDb = (Invoke-Docker @('exec', $db, 'bash', '-o', 'pipefail', '-ec', "$rowsDump | sha256sum") | Out-String).Trim()
    $restoreStart = Get-Date

    $null = Invoke-Docker @('exec', '-u', 'www-data', $source, 'php', $fixture, 'diverge')
    $null = Invoke-Docker -Arguments @('exec', '-u', 'www-data', $source, 'php', $fixture, 'verify') -ExpectedExitCode 255
    $divergedDb = (Invoke-Docker @('exec', $db, 'bash', '-o', 'pipefail', '-ec', "$rowsDump | sha256sum") | Out-String).Trim()
    if ($divergedDb -eq $beforeDb) { throw 'Whole DB did not diverge after backup.' }
    $null = Invoke-Docker @('stop', $source)
    $null = Invoke-Docker @('exec', $db, 'mysql', '-uroot', '-proot', '-e', 'DROP DATABASE nextcloud; CREATE DATABASE nextcloud;')
    $null = Invoke-Docker @('exec', $db, 'sh', '-ec', 'mysql -uroot -proot nextcloud < /tmp/recovery.sql')
    $afterDb = (Invoke-Docker @('exec', $db, 'bash', '-o', 'pipefail', '-ec', "$rowsDump | sha256sum") | Out-String).Trim()
    if ($afterDb -ne $beforeDb) { throw 'Whole DB row dump differs after restore.' }

    $target = "$prefix-restored"
    Run-Container $target $common
    $null = Invoke-Docker @('exec', $target, 'mkdir', '/evidence')
    foreach ($file in @('owned', 'fixture.json', 'before.json', 'files.sha256', 'instance.tar.gz')) {
        $null = Invoke-Docker @('cp', (Join-Path $evidence $file), "${target}:/evidence/$file")
    }
    $null = Invoke-Docker @('exec', $target, 'tar', '-xzf', '/evidence/instance.tar.gz', '-C', '/var/www/html')
    $null = Invoke-Docker @('exec', $target, 'sh', '-ec',
        'cd /var/www/html; sha256sum -c /evidence/files.sha256 > /evidence/file-check.log; test ! -e custom_apps/chorestrike/recovery-divergence.txt')
    $verification = Invoke-Docker @('exec', '-u', 'www-data', $target, 'php', $fixture, 'verify')
    $status = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', $target, 'php', 'occ', 'status', '--output=json')
    # OCC emits an optional PCNTL advisory before its JSON; retain it in commands.log.
    $jsonStatus = @($status | Where-Object { $_ -is [string] -and $_.StartsWith('{') })
    if ($jsonStatus.Count -ne 1) { throw 'Expected exactly one Nextcloud status JSON record.' }
    $parsed = $jsonStatus[0] | ConvertFrom-Json
    if (-not $parsed.installed -or -not $parsed.maintenance -or $parsed.needsDbUpgrade) { throw 'Restored Nextcloud status invalid.' }
    $version = Invoke-Docker @('exec', $db, 'mysql', '-uroot', '-proot', '-N', '-e', 'SELECT VERSION();')
    $php = Invoke-Docker @('exec', $target, 'php', '-r', 'echo PHP_VERSION;')
    $null = Invoke-Docker @('exec', $target, 'sh', '-ec',
        'cd /var/www/html/custom_apps/chorestrike; sha256sum lib/Migration/*.php js/* > /evidence/build-migrations.sha256')
    $null = Invoke-Docker @('cp', "${target}:/evidence/build-migrations.sha256", (Join-Path $evidence 'build-migrations.sha256'))
    $migrationInventory = Invoke-Docker @('exec', $db, 'mysql', '-uroot', '-proot', '-N', 'nextcloud', '-e',
        "SELECT version FROM oc_migrations WHERE app = 'chorestrike' ORDER BY version;")
    [IO.File]::WriteAllLines((Join-Path $evidence 'applied-migrations.txt'), [string[]]$migrationInventory)
    $migrationFiles = @(Get-ChildItem -LiteralPath (Join-Path $repo 'lib\Migration') -Filter 'Version*.php' |
        ForEach-Object { $_.BaseName.Substring('Version'.Length) } | Sort-Object)
    if (Compare-Object $migrationFiles @($migrationInventory | Sort-Object)) { throw 'Applied migration/file inventories differ.' }
    Invoke-Occ $target @('maintenance:mode', '--off')
    $appVersion = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', $target, 'php', 'occ', 'config:app:get', 'chorestrike', 'installed_version')
    $installedVersion = @($appVersion | Where-Object { $_ -match '^\d+\.\d+\.\d+$' })
    if ($installedVersion.Count -ne 1) { throw 'Expected exactly one installed app version.' }
    $revision = & git -C $repo rev-parse HEAD
    [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'), "Candidate revision capture exit=$LASTEXITCODE`n")
    if ($LASTEXITCODE -ne 0) { throw 'Candidate revision capture failed.' }
    $summary = [ordered]@{
        result = 'PASS'; target = $prefix; image = $image; nextcloud = $parsed.versionstring
        app = $installedVersion[0]
        revision = $revision; appArchiveSha256 = (Get-FileHash -LiteralPath (Join-Path $evidence 'app.tar.gz') -Algorithm SHA256).Hash.ToLowerInvariant()
        appliedMigrationCount = $migrationFiles.Count
        php = ($php -join '').Trim(); database = ($version -join '').Trim()
        wholeDbRowsEqual = $true; allCapturedFileHashesEqual = $true; divergenceObserved = $true
        recoverySeconds = [Math]::Round(((Get-Date) - $restoreStart).TotalSeconds, 2)
        elapsedSeconds = [Math]::Round(((Get-Date) - $start).TotalSeconds, 2)
    }
}
catch { $failure = $_ }
finally {
    $network = if ($networkCreated) { $prefix } else { '' }
    $cleanupFailures = Invoke-OwnedCleanup ($created.ToArray()) $network {
        param([string[]]$Arguments)
        Invoke-Docker $Arguments
    }
}
if ($null -eq $summary) { $summary = [ordered]@{ result = 'FAIL'; target = $prefix } }
$summary['cleanupSucceeded'] = $cleanupFailures.Count -eq 0
$summary['cleanupFailureCount'] = $cleanupFailures.Count
if ($failure -or $cleanupFailures.Count -gt 0) { $summary['result'] = 'FAIL' }
[IO.File]::WriteAllText((Join-Path $evidence 'summary.json'), ($summary | ConvertTo-Json))
if ($failure) { throw $failure }
if ($cleanupFailures.Count -gt 0) { throw 'Recovery teardown incomplete; private evidence retains each cleanup error.' }
$verification
$summary | ConvertTo-Json
