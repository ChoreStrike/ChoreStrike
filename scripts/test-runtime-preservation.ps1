# CS-PLAN-082 R5/R6: synthetic populated persistence (P1), real runtime update (P2)
# and whole-instance recovery (P3) proofs on owned cs082 test targets.
# Never runs against the canonical/WP9 stacks, never touches production, never
# adopts a retained baseline; all fixtures are synthetic and separately owned.
[CmdletBinding()]
param(
    [ValidateSet('p1', 'p2', 'p3')] [string]$Proof,
    [ValidateSet('mysql', 'pgsql', 'sqlite')] [string]$Provider = 'pgsql',
    [ValidateSet('primary', 'aio')] [string]$Row = 'primary',
    [string]$RunId = [Guid]::NewGuid().ToString('N').Substring(0, 8),
    [Parameter()][ValidateRange(1024, 65535)] [int]$Port = 0,
    [switch]$SelfTest
)

$ErrorActionPreference = 'Stop'

function Get-SanitizedArguments {
    param([string[]]$Arguments)
    # Element-level redaction: standalone or embedded password-bearing tokens,
    # including "-p<value>" inside interpolated dump/restore shell strings.
    return @($Arguments | ForEach-Object {
        if ($_ -match '(?i)(password|pass=|OC_PASS=|PGPASSWORD=|(?:^|\s)-p\w)') { '[redacted]' } else { $_ }
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

function Get-ProofProject {
    param([string]$Proof, [string]$Provider, [string]$Row, [string]$RunId)
    if ($RunId -cnotmatch '^[a-f0-9]{8}$') { throw 'Run id must be eight lowercase hex characters.' }
    $project = "cs082-$RunId-$Proof-$Provider"
    if ($Row -eq 'aio') { $project += '-aio' }
    if ($Proof -eq 'p3' -and ($Provider -ne 'pgsql' -or $Row -ne 'primary')) {
        throw 'P3 whole-instance recovery runs on the target PostgreSQL primary runtime only.'
    }
    if ($Proof -eq 'p1' -and $Row -ne 'primary') { throw 'P1 same-build persistence is proven on the primary row.' }
    if ($project -notmatch '^cs082-[a-z0-9][a-z0-9-]*$') { throw "Invalid owned proof project: $project" }
    return $project
}

function New-OwnedNetwork {
    param([hashtable]$State, [string]$Name, [scriptblock]$Operation)
    $null = & $Operation -Arguments @('network', 'create', '--internal', $Name)
    $State.NetworkCreated = $true
}

function Add-ProofLog {
    param([string]$Path, [object[]]$Lines)
    [IO.File]::AppendAllText($Path, ($Lines -join "`n") + "`n")
}

function Get-ProjectResidue {
    param([string]$Project, [scriptblock]$Operation)
    # Compose exits 0 even when it leaves a network "still in use"; only empty
    # label queries prove the owned staging project is gone.
    $label = "label=com.docker.compose.project=$Project"
    return @(foreach ($kind in @(@('ps', '--all'), @('volume', 'ls'), @('network', 'ls'))) {
        & $Operation -Arguments ($kind + @('--quiet', '--filter', $label)) | Where-Object { $_ }
    })
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
    'Preservation cleanup failure-path self-test: PASS'
    $sanitizedDump = Get-SanitizedArguments @('exec', 'owned-db', 'sh', '-ec',
        'pg_dump -U nextcloud nextcloud > /tmp/recovery.sql')
    if (($sanitizedDump -join ' ') -notmatch 'pg_dump -U nextcloud') { throw 'Non-password arguments must not be redacted.' }
    $sanitizedEnv = Get-SanitizedArguments @('exec', '-e', 'PGPASSWORD=nextcloud', 'owned-db', 'psql', '-h', '127.0.0.1')
    if ($sanitizedEnv[2] -ne '[redacted]') { throw 'PostgreSQL password environment tokens must be redacted.' }
    $sanitizedMy = Get-SanitizedArguments @('sh', '-ec', 'mysqldump -uroot -proot nextcloud')
    if ($sanitizedMy[-1] -ne '[redacted]') { throw 'Embedded MariaDB password tokens must be redacted.' }
    'Preservation log sanitization self-test: PASS'
    if ((Get-ProofProject p1 mysql primary 'abcd1234') -ne 'cs082-abcd1234-p1-mysql') { throw 'P1 project naming drifted.' }
    if ((Get-ProofProject p2 pgsql aio 'abcd1234') -ne 'cs082-abcd1234-p2-pgsql-aio') { throw 'P2 AIO project naming drifted.' }
    foreach ($invalid in @(
            @('p3', 'mysql', 'primary', 'abcd1234'), @('p3', 'pgsql', 'aio', 'abcd1234'),
            @('p1', 'pgsql', 'aio', 'abcd1234'), @('p1', 'mysql', 'primary', 'ABCD1234'))) {
        try { $null = Get-ProofProject @invalid; throw "Invalid proof combination was accepted: $($invalid -join ',')" }
        catch { if ($_.Exception.Message -like '*accepted*') { throw } }
    }
    'Preservation proof routing self-test: PASS'
    foreach ($failAfterCreate in @($false, $true)) {
        $state = @{ NetworkCreated = $false }
        $seen.Clear()
        try {
            New-OwnedNetwork $state 'owned-network' { param($Arguments) $seen.Add(($Arguments -join ' ')) }
            if ($failAfterCreate) { throw 'Synthetic failure after network creation' }
        } catch {
            if ($_.Exception.Message -ne 'Synthetic failure after network creation') { throw }
        } finally {
            $network = if ($state.NetworkCreated) { 'owned-network' } else { '' }
            $errors = Invoke-OwnedCleanup @() $network { param($Arguments) $seen.Add(($Arguments -join ' ')) }
        }
        if ($errors.Count -ne 0 -or ($seen -join ',') -ne 'network create --internal owned-network,network rm owned-network') {
            throw 'Nested network creation must remain visible to outer success/failure cleanup.'
        }
    }
    'Preservation network lifecycle self-test: PASS'
    $seen.Clear()
    $residue = Get-ProjectResidue 'cs082-owned' {
        param([string[]]$Arguments)
        $seen.Add(($Arguments -join ' '))
        if ($Arguments[0] -eq 'network') { 'still-in-use-network' }
    }
    $label = '--quiet --filter label=com.docker.compose.project=cs082-owned'
    if (($residue -join ',') -ne 'still-in-use-network' -or
        ($seen -join ',') -ne "ps --all $label,volume ls $label,network ls $label") {
        throw 'Teardown must query every owned resource kind and report what remains.'
    }
    'Preservation teardown residue self-test: PASS'
    $log = [IO.Path]::GetTempFileName()
    try {
        [IO.File]::AppendAllText($log, "initial`n")
        Add-ProofLog $log @('teardown', ('unicode-' + [char]0x00e4))
        $utf8 = [Text.UTF8Encoding]::new($false, $true)
        $text = $utf8.GetString([IO.File]::ReadAllBytes($log))
        if ($text -ne ("initial`nteardown`nunicode-" + [char]0x00e4 + "`n")) { throw 'Evidence log encoding drifted.' }
    } finally { Remove-Item -LiteralPath $log }
    'Preservation UTF-8 evidence log self-test: PASS'
    return
}

$repo = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path -LiteralPath (Join-Path $repo 'private'))) {
    throw 'The repository private/ parent must already exist.'
}
$project = Get-ProofProject $Proof $Provider $Row $RunId
$evidence = Join-Path $repo "private\$project"
$null = New-Item -ItemType Directory -Path $evidence
[IO.File]::WriteAllText((Join-Path $evidence 'owned'), $project)
$created = [Collections.Generic.List[string]]::new()
$lifecycle = @{ NetworkCreated = $false }
$failure = $null
$summary = $null
$start = Get-Date
"Preservation target: $project (proof $($Proof.ToUpper()), provider $Provider, row $Row)"

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

function Invoke-Node {
    param([string[]]$Arguments)
    $ErrorActionPreference = 'Continue'
    $stderrFile = [IO.Path]::GetTempFileName()
    try {
        $output = & node $Arguments 2>$stderrFile
        $exitCode = $LASTEXITCODE
        $diagnostics = [IO.File]::ReadAllText($stderrFile)
    } finally {
        Remove-Item -LiteralPath $stderrFile -Force -ErrorAction SilentlyContinue
    }
    [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'),
        '> node ' + ($Arguments -join ' ') + "`n" + ($output -join "`n") + "`n" + $diagnostics + "exit=$exitCode`n")
    if ($exitCode -ne 0) { throw "Node tooling failed (exit $exitCode); private commands.log retains diagnostics." }
    return (($output | Out-String).Trim())
}

function Get-RuntimeSelection {
    param([string]$Patch = 'current')
    $json = Invoke-Node @('scripts/test-runtime.js', 'selection', "--runtime=$Row", "--patch=$Patch")
    $record = ($json | ConvertFrom-Json)
    if ($record.status -ne 'selection') { throw 'Runtime selection probe malformed.' }
    return $record
}

function Invoke-Fixture {
    param([string]$Container, [string]$Mode, [int]$ExpectedExitCode = 0, [switch]$Disarmed)
    return Invoke-Docker -Arguments @('exec', '--user', 'www-data', '-e', "CS_RECOVERY_REHEARSAL=$(if ($Disarmed) { 'false' } else { 'true' })",
        $Container, 'php', '/var/www/html/custom_apps/chorestrike/tests/recovery/fixture.php', $Mode) -ExpectedExitCode $ExpectedExitCode
}

function Initialize-EvidenceShare {
    param([string]$Container)
    $null = Invoke-Docker @('exec', $Container, 'mkdir', '-p', '/evidence')
    $null = Invoke-Docker @('cp', (Join-Path $evidence 'owned'), "${Container}:/evidence/owned")
    $null = Invoke-Docker @('exec', $Container, 'chown', '-R', 'www-data:www-data', '/evidence')
}

function Copy-FromContainer {
    param([string]$Container, [string[]]$Files)
    foreach ($file in $Files) {
        $null = Invoke-Docker @('cp', "${Container}:/evidence/$file", (Join-Path $evidence $file))
    }
}

function Restore-EvidenceShare {
    param([string]$Container)
    Initialize-EvidenceShare $Container
    foreach ($file in @('fixture.json', 'before.json')) {
        $null = Invoke-Docker @('cp', (Join-Path $evidence $file), "${Container}:/evidence/$file")
    }
    $null = Invoke-Docker @('exec', $Container, 'chown', '-R', 'www-data:www-data', '/evidence')
}

function Get-InstalledIdentity {
    param([string]$Container)
    $code = "require '/var/www/html/lib/base.php'; " +
        '$cfg = \OC::$server->get(\OC\SystemConfig::class); $db = \OC::$server->get(\OCP\IDBConnection::class); ' +
        '$qb = $db->getQueryBuilder(); $rows = $qb->select(''version'')->from(''migrations'')' +
        '->where($qb->expr()->eq(''app'', $qb->createNamedParameter(''chorestrike'')))->executeQuery()->fetchAll(); ' +
        '$m = array_map(static fn ($r) => $r[''version''], $rows); sort($m); ' +
        'echo json_encode([''instanceid'' => $cfg->getValue(''instanceid''), ''version'' => $cfg->getValue(''version''), ''migrations'' => $m]);'
    $output = Invoke-Docker @('exec', '--user', 'www-data', $Container, 'php', '-r', $code)
    $json = @($output | Where-Object { $_ -is [string] -and $_.StartsWith('{') })
    if ($json.Count -ne 1) { throw 'Expected exactly one installed-identity JSON record.' }
    return ($json[0] | ConvertFrom-Json)
}

function Get-ProjectVolumes {
    param([string]$Project)
    $names = @( (Invoke-Docker @('volume', 'ls', '--filter', "label=com.docker.compose.project=$Project", '--format', '{{.Name}}')) |
        Where-Object { $_ } | Sort-Object)
    $result = @{}
    foreach ($name in $names) {
        $info = (Invoke-Docker @('volume', 'inspect', $name, '--format', '{{.CreatedAt}}')) | Out-String
        $result[$name] = $info.Trim()
    }
    return $result
}

function Assert-IdentityPreserved {
    param($Before, $After, [bool]$ExpectUpgrade)
    if ($Before.instanceid -ne $After.instanceid) { throw 'Instance identity changed across preservation.' }
    if (($Before.migrations | ConvertTo-Json -Compress) -ne ($After.migrations | ConvertTo-Json -Compress)) {
        throw 'Applied app migration inventory changed across preservation.'
    }
    if ($ExpectUpgrade) {
        if ($Before.version -eq $After.version) { throw 'P2 did not perform a real runtime update.' }
    } elseif ($Before.version -ne $After.version) {
        throw 'P1 same-build recreation changed the installed version.'
    }
}

function Test-MigrationFiles {
    param($Identity)
    $migrationFiles = @(Get-ChildItem -LiteralPath (Join-Path $repo 'lib\Migration') -Filter 'Version*.php' |
        ForEach-Object { $_.BaseName.Substring('Version'.Length) } | Sort-Object)
    if (Compare-Object $migrationFiles @($Identity.migrations | Sort-Object)) {
        throw 'Applied migration/file inventories differ.'
    }
    return $migrationFiles.Count
}

function Invoke-SchemaParity {
    param([string]$Container)
    # Read-only schema/provider proofs against the installed DB, exactly the same
    # assertions fresh installs pass in the provider matrix; the destructive
    # seed/reset case of that file is never selected here.
    $output = Invoke-Docker @('exec', '--user', 'www-data', '-w', '/var/www/html/custom_apps/chorestrike',
        $Container, 'vendor/bin/phpunit', '--configuration', 'phpunit.integration.xml',
        '--do-not-cache-result', '--filter',
        'test(InstalledProvider_MatchesSelectorAndUsesRealStorage|FullMigratedSchema_ExactlyMatchesTheFreshMigrationChain)',
        'tests/integration/Db/SupportedDatabaseSchemaTest.php')
    if (($output | Out-String) -notmatch 'OK \(2 tests') { throw 'Read-only schema/provider parity proofs failed on the preserved instance.' }
}

function Invoke-StagedProof {
    # P1: same frozen build, container recreation on the same owned named volumes.
    # P2: pinned previous-patch source install, populated, then the official
    #     Nextcloud upgrade path onto the frozen target build (upgradeFrom set).
    param([string]$SourcePatch)
    $current = Get-RuntimeSelection 'current'
    $source = Get-RuntimeSelection $SourcePatch
    $sourceImage = "cs082-runtime-$($source.nextcloud)-local"
    $targetImage = "cs082-runtime-$($current.nextcloud)-local"
    $container = "$project-nextcloud-$Provider-1"
    if ($SourcePatch -eq 'previous') {
        $ErrorActionPreference = 'Continue'
        $null = & docker image inspect $sourceImage 2>&1
        $sourceBuilt = $LASTEXITCODE -eq 0
        [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'), "Pinned source image presence probe exit=$LASTEXITCODE`n")
        if (-not $sourceBuilt) {
            $null = Invoke-Node @('scripts/test-runtime.js', 'build', $sourceImage, "--runtime=$Row", '--patch=previous')
        }
        $null = Invoke-Node @('scripts/test-runtime.js', 'verify-image', $sourceImage, "--runtime=$Row", '--patch=previous')
    }
    $null = Invoke-Node @('scripts/test-runtime-stage.js', 'up', $project, $Provider, $sourceImage, "$Port", $Row, $SourcePatch)

    Initialize-EvidenceShare $container
    $null = Invoke-Fixture $container 'seed' -ExpectedExitCode 255 -Disarmed  # refusal control: unarmed fixture must fail before any mutation
    $seedOutput = Invoke-Fixture $container 'seed'
    if (($seedOutput | Out-String) -notmatch 'Recovery fixture seed: PASS') { throw 'Populated fixture seeding failed.' }
    Copy-FromContainer $container @('fixture.json', 'before.json')
    $identityBefore = Get-InstalledIdentity $container
    $migrationCount = Test-MigrationFiles $identityBefore
    if ($SourcePatch -eq 'previous' -and $identityBefore.version -ne $source.internalVersion) {
        throw 'Populated source does not run the pinned previous patch.'
    }
    $volumesBefore = Get-ProjectVolumes $project
    [IO.File]::WriteAllText((Join-Path $evidence 'identity-before.json'), ($identityBefore | ConvertTo-Json))
    [IO.File]::WriteAllText((Join-Path $evidence 'volumes-before.json'), ($volumesBefore | ConvertTo-Json))

    $preserved = Invoke-Node @('scripts/test-runtime-stage.js', 'preserve', $project, $Provider, $targetImage, "$Port", $Row, $SourcePatch)
    [IO.File]::WriteAllText((Join-Path $evidence 'preserved-installation.json'), $preserved)

    $volumesAfter = Get-ProjectVolumes $project
    if (($volumesBefore | ConvertTo-Json -Compress) -ne ($volumesAfter | ConvertTo-Json -Compress)) {
        throw 'Owned volumes were recreated or replaced during preservation.'
    }
    $identityAfter = Get-InstalledIdentity $container
    Assert-IdentityPreserved $identityBefore $identityAfter ($SourcePatch -eq 'previous')
    [IO.File]::WriteAllText((Join-Path $evidence 'identity-after.json'), ($identityAfter | ConvertTo-Json))

    Restore-EvidenceShare $container
    $verification = Invoke-Fixture $container 'verify'
    $postcheck = Invoke-Fixture $container 'postcheck'
    if ($SourcePatch -eq 'previous') { Invoke-SchemaParity $container }
    return [ordered]@{
        sourceImage = $sourceImage; targetImage = $targetImage; migrations = $migrationCount
        versionBefore = $identityBefore.version; versionAfter = $identityAfter.version
        volumesPreserved = $volumesBefore.Count; verify = ($verification | Out-String).Trim()
        postcheck = ($postcheck | Out-String).Trim()
    }
}

function Wait-OwnedPostgres {
    param([string]$Name, [string]$User = 'nextcloud', [string]$Database = 'nextcloud')
    for ($i = 0; $i -lt 60; $i++) {
        $ErrorActionPreference = 'Continue'
        & docker exec -e PGPASSWORD=nextcloud $Name psql -h 127.0.0.1 -U $User -d $Database -c 'SELECT 1' 2>&1 | Out-Null
        $exitCode = $LASTEXITCODE
        [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'), "Authenticated TCP DB readiness attempt=$i exit=$exitCode`n")
        if ($exitCode -eq 0) { return }
        Start-Sleep -Seconds 1
    }
    throw 'Owned recovery DB failed to become ready for authenticated TCP queries.'
}

function Invoke-RecoveryProof {
    # P3: whole-instance backup/restore on the frozen target PostgreSQL runtime
    # into a separate owned target, with wrong-version/manifest/divergence controls.
    $current = Get-RuntimeSelection 'current'
    $imageName = "cs082-runtime-$($current.nextcloud)-local"
    $verify = Invoke-Node @('scripts/test-runtime.js', 'verify-image', $imageName, "--runtime=$Row")
    $image = (($verify | ConvertFrom-Json).imageId)
    if ($image -notmatch '^sha256:[a-f0-9]{64}$') { throw 'Expected the verified frozen candidate image.' }
    $dbImage = (Invoke-Node @('-e',
        "const { readInventory } = require('./scripts/test-runtime'); const i = readInventory(); process.stdout.write(i.databases.pgsql.image + '@' + i.databases.pgsql.digest)")).Trim()
    $null = Invoke-Docker @('image', 'inspect', $dbImage)

    New-OwnedNetwork $lifecycle $project { param($Arguments) Invoke-Docker $Arguments }
    $db = "$project-db"
    $null = Invoke-Docker (@('create', '--name', $db, '--network', $project,
        '-e', 'POSTGRES_DB=nextcloud', '-e', 'POSTGRES_USER=nextcloud', '-e', 'POSTGRES_PASSWORD=nextcloud', $dbImage))
    $created.Add($db)
    $null = Invoke-Docker @('start', $db)
    Wait-OwnedPostgres $db

    # Whitelist runtime/build paths: never copy private/, .git/, browser artifacts or node_modules.
    & tar.exe -czf (Join-Path $evidence 'app.tar.gz') -C $repo appinfo lib templates img l10n js css vendor composer.json composer.lock LICENSE tests/recovery
    [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'), "Runtime whitelist archive (host tar) exit=$LASTEXITCODE`n")
    if ($LASTEXITCODE -ne 0) { throw 'Runtime/build capture failed.' }
    $common = @('--network', $project,
        '-e', 'NEXTCLOUD_TEST_ENV=true', '-e', 'NEXTCLOUD_TEST_MODE=true',
        '-e', "DB_HOST=$db", '--entrypoint', 'tail', $image, '-f', '/dev/null')
    $source = "$project-source"
    $null = Invoke-Docker (@('create', '--name', $source) + $common)
    $created.Add($source)
    $null = Invoke-Docker @('start', $source)
    Initialize-EvidenceShare $source
    $null = Invoke-Docker @('cp', (Join-Path $evidence 'app.tar.gz'), "${source}:/tmp/app.tar.gz")
    $null = Invoke-Docker @('exec', $source, 'tar', '-xzf', '/tmp/app.tar.gz', '-C', '/var/www/html/custom_apps/chorestrike')
    $null = Invoke-Docker @('exec', $source, 'ln', '-s', '/var/www/html/custom_apps/chorestrike', '/var/www/html/apps/chorestrike')
    $null = Invoke-Docker @('exec', $source, 'chown', '-R', 'www-data:www-data', '/var/www/html/custom_apps/chorestrike')
    $null = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', $source, 'php', 'occ',
        'maintenance:install', '--database=pgsql', "--database-host=$db", '--database-name=nextcloud',
        '--database-user=nextcloud', '--database-pass=nextcloud', '--admin-user=admin', '--admin-pass=admin')
    $null = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', $source, 'php', 'occ', 'app:enable', 'chorestrike')
    foreach ($uid in @('mom', 'dad', 'teen', 'kid', 'grandma', 'friend')) {
        $null = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', '-e', 'OC_PASS=RecoveryTest123!',
            $source, 'php', 'occ', 'user:add', '--password-from-env', $uid)
    }
    $null = Invoke-Fixture $source 'seed' -ExpectedExitCode 255 -Disarmed  # refusal control
    $seedOutput = Invoke-Fixture $source 'seed'
    if (($seedOutput | Out-String) -notmatch 'Recovery fixture seed: PASS') { throw 'Populated fixture seeding failed.' }
    Copy-FromContainer $source @('fixture.json', 'before.json')
    $identityBefore = Get-InstalledIdentity $source
    $migrationCount = Test-MigrationFiles $identityBefore
    $null = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', $source, 'php', 'occ', 'maintenance:mode', '--on')

    # No web process, cron, ports or other jobs exist in this internal-network source.
    $null = Invoke-Docker -Arguments @('exec', $source, 'bash', '-o', 'pipefail', '-ec',
        'false | sort | xargs -r sha256sum > /evidence/rejected-manifest') -ExpectedExitCode 1
    $null = Invoke-Docker @('exec', $source, 'bash', '-o', 'pipefail', '-ec',
        'cd /var/www/html; find . -type f -print0 | sort -z | xargs -0 sha256sum > /evidence/files.sha256; test -s /evidence/files.sha256; tar -czf /evidence/instance.tar.gz .')
    Copy-FromContainer $source @('files.sha256', 'instance.tar.gz')
    # Ownership-preserving dump: Nextcloud's PostgreSQL installer creates and
    # uses a dedicated oc_admin role; a faithful whole-instance backup keeps it.
    $null = Invoke-Docker @('exec', $db, 'sh', '-ec', 'pg_dump -U nextcloud -d nextcloud -f /tmp/recovery.sql')
    $null = Invoke-Docker @('cp', "${db}:/tmp/recovery.sql", (Join-Path $evidence 'database.sql'))
    $null = Invoke-Docker @('exec', $db, 'pg_dumpall', '-U', 'nextcloud', '--roles-only', '-f', '/tmp/roles.sql')
    $null = Invoke-Docker @('cp', "${db}:/tmp/roles.sql", (Join-Path $evidence 'roles.sql'))
    $rowsDump = 'pg_dump -U nextcloud -d nextcloud --data-only --inserts --no-owner --no-privileges | grep -E ''^(INSERT INTO|SELECT pg_catalog.setval)'' | sort | sha256sum'
    $beforeDb = (Invoke-Docker @('exec', $db, 'bash', '-o', 'pipefail', '-ec', $rowsDump) | Out-String).Trim()
    $restoreStart = Get-Date

    $null = Invoke-Fixture $source 'diverge'
    $null = Invoke-Fixture $source 'verify' -ExpectedExitCode 255  # divergence control: mutated state must fail verification
    $divergedDb = (Invoke-Docker @('exec', $db, 'bash', '-o', 'pipefail', '-ec', $rowsDump) | Out-String).Trim()
    if ($divergedDb -eq $beforeDb) { throw 'Whole DB did not diverge after backup.' }
    $null = Invoke-Docker @('stop', $source)
    # Restore into a fresh cluster, not the source cluster with surviving roles.
    $sourceDb = $db
    $null = Invoke-Docker @('stop', $sourceDb)
    $null = Invoke-Docker @('network', 'disconnect', $project, $sourceDb)
    $db = "$project-restored-db"
    $null = Invoke-Docker @('create', '--name', $db, '--network', $project, '--network-alias', $sourceDb,
        '-e', 'POSTGRES_DB=postgres', '-e', 'POSTGRES_USER=cs_recovery', '-e', 'POSTGRES_PASSWORD=nextcloud', $dbImage)
    $created.Add($db)
    $null = Invoke-Docker @('start', $db)
    Wait-OwnedPostgres $db 'cs_recovery' 'postgres'
    $roleCount = Invoke-Docker @('exec', $db, 'psql', '-U', 'cs_recovery', '-d', 'postgres', '-At', '-c',
        "SELECT count(*) FROM pg_roles WHERE rolname IN ('nextcloud', 'oc_admin')")
    if (($roleCount | Out-String).Trim() -ne '0') { throw 'Recovery destination inherited source roles.' }
    foreach ($file in @('roles.sql', 'database.sql')) {
        $null = Invoke-Docker @('cp', (Join-Path $evidence $file), "${db}:/tmp/$file")
    }
    $null = Invoke-Docker @('exec', $db, 'psql', '-U', 'cs_recovery', '-d', 'postgres', '-v', 'ON_ERROR_STOP=1', '-f', '/tmp/roles.sql')
    $null = Invoke-Docker @('exec', $db, 'createdb', '-U', 'nextcloud', '-O', 'nextcloud', 'nextcloud')
    $null = Invoke-Docker @('exec', $db, 'sh', '-ec', 'psql -U nextcloud -d nextcloud -v ON_ERROR_STOP=1 -f /tmp/database.sql > /dev/null')
    $afterDb = (Invoke-Docker @('exec', $db, 'bash', '-o', 'pipefail', '-ec', $rowsDump) | Out-String).Trim()
    if ($afterDb -ne $beforeDb) { throw 'Whole DB row dump differs after restore.' }

    $target = "$project-restored"
    $null = Invoke-Docker (@('create', '--name', $target) + $common)
    $created.Add($target)
    $null = Invoke-Docker @('start', $target)
    Initialize-EvidenceShare $target
    foreach ($file in @('fixture.json', 'before.json', 'files.sha256', 'instance.tar.gz')) {
        $null = Invoke-Docker @('cp', (Join-Path $evidence $file), "${target}:/evidence/$file")
    }
    $null = Invoke-Docker @('exec', $target, 'tar', '-xzf', '/evidence/instance.tar.gz', '-C', '/var/www/html')
    $fileCheck = 'cd /var/www/html; sha256sum -c /evidence/files.sha256 > /evidence/file-check.log; test ! -e custom_apps/chorestrike/recovery-divergence.txt'
    $null = Invoke-Docker @('exec', $target, 'sh', '-ec', 'printf "\ncorrupted\n" >> /var/www/html/version.php')
    $null = Invoke-Docker -Arguments @('exec', $target, 'sh', '-ec', $fileCheck) -ExpectedExitCode 1
    $null = Invoke-Docker @('exec', $target, 'grep', '-Fx', './version.php: FAILED', '/evidence/file-check.log')
    $null = Invoke-Docker @('exec', $target, 'tar', '-xzf', '/evidence/instance.tar.gz', '-C', '/var/www/html')
    $null = Invoke-Docker @('exec', $target, 'sh', '-ec', $fileCheck)
    $verification = Invoke-Fixture $target 'verify'
    $status = Invoke-Docker @('exec', '-u', 'www-data', '-w', '/var/www/html', $target, 'php', 'occ', 'status', '--output=json')
    # OCC emits an optional PCNTL advisory before its JSON; retain it in commands.log.
    $jsonStatus = @($status | Where-Object { $_ -is [string] -and $_.StartsWith('{') })
    if ($jsonStatus.Count -ne 1) { throw 'Expected exactly one Nextcloud status JSON record.' }
    $parsed = $jsonStatus[0] | ConvertFrom-Json
    if (-not $parsed.installed -or -not $parsed.maintenance -or $parsed.needsDbUpgrade) { throw 'Restored Nextcloud status invalid.' }
    if ($parsed.version -ne $current.internalVersion) { throw 'Restored runtime version differs from the frozen target.' }

    # Wrong-version negative control: a partial restore (config+data only) onto
    # the previous-patch build is a code downgrade, not recovery — the restored
    # newer configured state on older code must be detectably mismatched.
    # Nextcloud's needUpgrade only covers upgrades, so the downgrade is proven by
    # code != configured version.
    $previous = Get-RuntimeSelection 'previous'
    $previousImage = "cs082-runtime-$($previous.nextcloud)-local"
    $previousVerify = Invoke-Node @('scripts/test-runtime.js', 'verify-image', $previousImage, "--runtime=$Row", '--patch=previous')
    $previousImageId = ($previousVerify | ConvertFrom-Json).imageId
    $wrong = "$project-wrongversion"
    $null = Invoke-Docker (@('create', '--name', $wrong, '--network', $project,
        '-e', 'NEXTCLOUD_TEST_ENV=true', '-e', 'NEXTCLOUD_TEST_MODE=true',
        '-e', "DB_HOST=$sourceDb", '--entrypoint', 'tail', $previousImageId, '-f', '/dev/null'))
    $created.Add($wrong)
    $null = Invoke-Docker @('start', $wrong)
    $null = Invoke-Docker @('cp', (Join-Path $evidence 'instance.tar.gz'), "${wrong}:/tmp/instance.tar.gz")
    $null = Invoke-Docker @('exec', $wrong, 'tar', '-xzf', '/tmp/instance.tar.gz', '-C', '/var/www/html', './config', './data')
    $ErrorActionPreference = 'Continue'
    $wrongStatus = & docker exec -u www-data -w /var/www/html $wrong php occ status --output=json 2>&1
    $wrongExit = $LASTEXITCODE
    $wrongCode = & docker exec $wrong php -r 'require ''/var/www/html/version.php''; echo implode(''.'', $OC_Version);' 2>&1
    [IO.File]::AppendAllText((Join-Path $evidence 'commands.log'),
        "> docker exec $wrong occ status + version.php (wrong-version control)`n" + ($wrongStatus -join "`n") + "`nexit=$wrongExit`ncode=$($wrongCode -join '')`n")
    $wrongCodeVersion = ($wrongCode | Out-String).Trim()
    if ($wrongCodeVersion -ne $previous.internalVersion) { throw 'Wrong-version control did not run on the pinned previous build.' }
    # Nextcloud loudly refuses a downgrade (HintException, exit 1); if status
    # somehow succeeded, the code-vs-configured version mismatch must show.
    $downgradeRefused = $wrongExit -ne 0 -and (($wrongStatus | Out-String) -match 'Downgrading Nextcloud')
    if (-not $downgradeRefused) {
        $wrongJson = @($wrongStatus | Where-Object { $_ -is [string] -and $_.StartsWith('{') })
        $wrongConfigVersion = $null
        if ($wrongExit -eq 0 -and $wrongJson.Count -eq 1) { $wrongConfigVersion = ($wrongJson[0] | ConvertFrom-Json).version }
        if ($null -eq $wrongConfigVersion -or $wrongConfigVersion -eq $wrongCodeVersion) {
            throw 'Wrong-version control did not detect the runtime downgrade.'
        }
    }
    [IO.File]::WriteAllText((Join-Path $evidence 'wrong-version-control.txt'),
        "exit=$wrongExit code=$wrongCodeVersion => downgrade detected/refused`n" + ($wrongStatus | Out-String))

    $postcheck = Invoke-Fixture $target 'postcheck'
    $identityAfter = Get-InstalledIdentity $target
    Assert-IdentityPreserved $identityBefore $identityAfter $false
    $php = Invoke-Docker @('exec', $target, 'php', '-r', 'echo PHP_VERSION;')
    $dbVersion = Invoke-Docker @('exec', $db, 'psql', '-U', 'nextcloud', '-At', '-c', 'SHOW server_version;')
    return [ordered]@{
        image = $image; databaseImage = $dbImage; migrations = $migrationCount
        nextcloud = $parsed.versionstring; php = ($php -join '').Trim(); database = ($dbVersion -join '').Trim()
        wholeDbRowsEqual = $true; allCapturedFileHashesEqual = $true; divergenceObserved = $true
        freshClusterRestore = $true; sourceRolesAbsentBeforeRestore = $true; corruptedFileRejected = $true
        wrongVersionControl = 'refused'; recoverySeconds = [Math]::Round(((Get-Date) - $restoreStart).TotalSeconds, 2)
        verify = ($verification | Out-String).Trim(); postcheck = ($postcheck | Out-String).Trim()
    }
}

try {
    $null = Invoke-Docker @('version', '--format', '{{.Server.Version}}')
    if ($Proof -in @('p1', 'p2')) {
        if ($Port -eq 0) { throw 'Staged proofs require an explicit owned loopback -Port.' }
        $listener = $null
        try {
            $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Parse('127.0.0.1'), $Port)
            $listener.Start()
        } catch { throw "Owned loopback port $Port is not free." }
        finally { if ($listener) { $listener.Stop() } }
        $result = Invoke-StagedProof ($(if ($Proof -eq 'p2') { 'previous' } else { 'current' }))
        $summary = [ordered]@{ result = 'PASS'; proof = $Proof; target = $project; provider = $Provider; row = $Row } + $result
    } else {
        $result = Invoke-RecoveryProof
        $summary = [ordered]@{ result = 'PASS'; proof = $Proof; target = $project; provider = $Provider; row = $Row } + $result
    }
    $summary['elapsedSeconds'] = [Math]::Round(((Get-Date) - $start).TotalSeconds, 2)
}
catch { $failure = $_ }
finally {
    if ($Proof -in @('p1', 'p2')) {
        # Qualified teardown of the owned disposable staging project only on success;
        # a failed proof retains its resources for diagnosis (cs082 ownership prefix).
        # The AIO Redis peer lives in the stage script's stdin overlay, not in the
        # matrix file, so only --remove-orphans reaches it.
        if (-not $failure) {
            $ErrorActionPreference = 'Continue'
            $output = & docker compose --project-name $project --file (Join-Path $repo 'docker\compose.test-matrix.yml') --profile $Provider down -v --remove-orphans 2>&1
            $exitCode = $LASTEXITCODE
            Add-ProofLog (Join-Path $evidence 'commands.log') (@($output) + "Owned staging teardown exit=$exitCode")
            if ($exitCode -ne 0) { $failure = "Owned staging teardown failed (exit $exitCode)." }
            else {
                try {
                    $residue = Get-ProjectResidue $project { param([string[]]$Arguments) Invoke-Docker $Arguments }
                    if ($residue.Count -gt 0) { $failure = "Owned staging teardown left $($residue.Count) project resources behind." }
                } catch { $failure = $_ }
            }
        }
        $cleanupFailures = @()
    } else {
        $network = if ($lifecycle.NetworkCreated) { $project } else { '' }
        $cleanupFailures = Invoke-OwnedCleanup ($created.ToArray()) $network {
            param([string[]]$Arguments)
            Invoke-Docker $Arguments
        }
    }
}
if ($null -eq $summary) { $summary = [ordered]@{ result = 'FAIL'; proof = $Proof; target = $project } }
$summary['cleanupSucceeded'] = $cleanupFailures.Count -eq 0 -and $null -eq $failure
$summary['cleanupFailureCount'] = $cleanupFailures.Count
if ($failure -or $cleanupFailures.Count -gt 0) { $summary['result'] = 'FAIL' }
[IO.File]::WriteAllText((Join-Path $evidence 'summary.json'), ($summary | ConvertTo-Json))
if ($failure) { throw $failure }
if ($cleanupFailures.Count -gt 0) { throw 'Preservation teardown incomplete; private evidence retains each cleanup error.' }
$summary | ConvertTo-Json
