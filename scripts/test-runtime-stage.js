#!/usr/bin/env node
/** Fresh owned qualification and owned-volume preservation; never promotes or cleans an existing stack. */
const { spawnSync } = require('node:child_process')
const path = require('node:path')
const { readInventory, validateRuntimeShape, selectRuntimeSource, selectionHash, verifyImage, assertInstalled, execute, probe, RUNTIME_FIELDS } = require('./test-runtime')
const { assertTestTarget } = require('./test-target')

/**
 * The AIO row runs with Redis like the AIO Nextcloud container: sessions,
 * distributed cache and file locking (CS-PLAN-084 WP-A). The primary row
 * keeps the APCu-only stack.
 */
function redisServices(selection, provider) {
	const redis = selection.cache.redis
	return {
		redis: {
			image: `${redis.image}@${redis.digest}`,
			networks: ['wp9'],
			healthcheck: { test: ['CMD', 'redis-cli', 'ping'], interval: '5s', timeout: '3s', retries: 30 },
		},
		[`nextcloud-${provider}`]: { depends_on: { redis: { condition: 'service_healthy' } } },
	}
}

function stageSettings(selection, project, provider, imageId, port, preservation = {}, row = 'primary') {
	validateRuntimeShape(selection)
	if (!['primary', 'aio'].includes(row)) throw new Error('Invalid runtime row')
	const redis = row === 'aio'
	if (!/^cs082-[a-z0-9][a-z0-9-]*$/.test(project) || !['mysql', 'pgsql', 'sqlite'].includes(provider)
		|| !/^sha256:[a-f0-9]{64}$/.test(imageId) || !Number.isInteger(port) || port < 1024 || port > 65535) {
		throw new Error('Invalid owned staging project/provider/image/port')
	}
	const preserving = preservation.mode === true || preservation.upgradeFrom !== undefined
	if ((preserving && preservation.mode !== true)
		|| (preservation.upgradeFrom !== undefined && !/^\d+\.\d+\.\d+\.\d+$/.test(preservation.upgradeFrom))) {
		throw new Error('Invalid preservation mode/update source')
	}
	const services = redis ? redisServices(selection, provider) : {}
	const service = `nextcloud-${provider}`
	return {
		project, provider, imageId, port, redis,
		container: `${project}-nextcloud-${provider}-1`,
		url: `http://127.0.0.1:${port}`,
		environment: {
			...process.env,
			WP9_TEST_IMAGE: imageId,
			WP9_MYSQL_IMAGE: `${selection.databases.mysql.image}@${selection.databases.mysql.digest}`,
			WP9_PGSQL_IMAGE: `${selection.databases.pgsql.image}@${selection.databases.pgsql.digest}`,
			WP9_PGDATA: `/var/lib/postgresql/${selection.databases.pgsql.version.split('.')[0]}/docker`,
			WP9_PGSQL_VOLUME_TARGET: '/var/lib/postgresql',
		},
		input: JSON.stringify({ services: { ...services, [service]: {
			...services[service],
			ports: [`127.0.0.1:${port}:80`],
			environment: {
				...(redis ? { REDIS_HOST: 'redis', REDIS_PORT: '6379' } : {}),
				TEST_HTTP_URL: `http://127.0.0.1:${port}`,
				TEST_RUNTIME_INTERNAL_VERSION: selection.nextcloud.internalVersion,
				TEST_RUNTIME_PHP_VERSION: selection.php.version,
				...(preservation.mode === true ? {
					TEST_RUNTIME_PRESERVATION: 'true',
					...(preservation.upgradeFrom !== undefined ? { TEST_RUNTIME_UPGRADE_FROM: preservation.upgradeFrom } : {}),
				} : {}),
			},
		} } }),
	}
}

function composeArguments(settings, command) {
	return ['compose', '--project-name', settings.project, '--file', 'docker/compose.test-matrix.yml',
		'--file', '-', '--profile', settings.provider, ...command]
}

function assertFreshProject(project, run = execute) {
	if (!/^cs082-[a-z0-9][a-z0-9-]*$/.test(project)) throw new Error('Invalid owned staging project')
	for (const args of [
		['ps', '-a', '--filter', `label=com.docker.compose.project=${project}`, '--format', '{{.ID}}'],
		['volume', 'ls', '--filter', `label=com.docker.compose.project=${project}`, '--format', '{{.Name}}'],
		['network', 'ls', '--filter', `label=com.docker.compose.project=${project}`, '--format', '{{.ID}}'],
	]) {
		if (run('docker', args).trim()) throw new Error('Staging project already exists; no old-volume fallback or automatic cleanup')
	}
	for (const args of [
		['ps', '-a', '--format', '{{.Names}}'], ['volume', 'ls', '--format', '{{.Name}}'], ['network', 'ls', '--format', '{{.Name}}'],
	]) {
		if (run('docker', args).trim().split(/\r?\n/).some(name => name.startsWith(`${project}_`) || name.startsWith(`${project}-`))) {
			throw new Error('Staging resource name already exists, including unlabelled resources')
		}
	}
}

/** Preservation re-creation requires an existing, healthy, owned project on the expected source image. */
function assertOwnedProject(settings, sourceImageId, run = execute) {
	const ids = run('docker', ['ps', '--filter', `label=com.docker.compose.project=${settings.project}`, '--format', '{{.ID}}']).trim()
	if (!ids) throw new Error('Preservation project is missing; a fresh install cannot be preserved')
	const container = JSON.parse(run('docker', ['inspect', settings.container]))[0]
	if (container?.Config?.Labels?.['com.docker.compose.project'] !== settings.project
		|| container?.Config?.Labels?.['com.docker.compose.service'] !== `nextcloud-${settings.provider}`
		|| container?.Image !== sourceImageId || container?.State?.Health?.Status !== 'healthy') {
		throw new Error('Preservation source container ownership/image/readiness mismatch')
	}
	return container
}

function assertProviderImage(inventory, settings, container, image) {
	const expected = inventory.databases[settings.provider]
	const repository = expected.image.split(':')[0]
	if (container?.Config?.Labels?.['com.docker.compose.project'] !== settings.project
		|| container?.Config?.Labels?.['com.docker.compose.service'] !== `db-${settings.provider}`
		|| container?.Config?.Image !== `${expected.image}@${expected.digest}`
		|| container?.Image !== image?.Id || `${image?.Os}/${image?.Architecture}` !== inventory.platform
		|| !image?.RepoDigests?.includes(`${repository}@${expected.digest}`)) {
		throw new Error('Installed database image/ownership mismatch')
	}
	if (settings.provider === 'pgsql') {
		const data = settings.environment.WP9_PGDATA
		if (!container.Config.Env.includes(`PGDATA=${data}`)
			|| !container.Mounts.some(mount => mount.Type === 'volume' && mount.Name === `${settings.project}_pgsql-db`
				&& mount.Destination === '/var/lib/postgresql')
			|| container.Mounts.some(mount => mount.Destination === '/var/lib/postgresql/data')) {
			throw new Error('PostgreSQL owned volume/PGDATA mismatch')
		}
	}
}

const REDIS_PROBE = "require '/var/www/html/lib/base.php'; $cfg = \\OC::$server->get(\\OC\\SystemConfig::class);"
	+ "$cache = \\OC::$server->get(\\OCP\\ICacheFactory::class)->createDistributed('cs084-probe');"
	+ "$token = bin2hex(random_bytes(8)); $cache->set('token', $token, 60); $roundTrip = $cache->get('token') === $token; $cache->remove('token');"
	+ "echo json_encode(['distributed' => $cfg->getValue('memcache.distributed'), 'locking' => $cfg->getValue('memcache.locking'),"
	+ "'host' => ((array) $cfg->getValue('redis', []))['host'] ?? null, 'roundTrip' => $roundTrip, 'sessionHandler' => ini_get('session.save_handler'),"
	+ "'peer' => gethostbyname('redis')]);"
const REDIS_CLASS = '\\OC\\Memcache\\Redis'

/** AIO row: Nextcloud really uses the owned, pinned Redis for cache, locking and sessions. */
function assertRedisCache(selection, settings, container, run = execute) {
	const actual = JSON.parse(run('docker', ['exec', '--user', 'www-data', settings.container, 'php', '-r', REDIS_PROBE]))
	if (actual.distributed !== REDIS_CLASS || actual.locking !== REDIS_CLASS || actual.host !== 'redis'
		|| actual.roundTrip !== true || actual.sessionHandler !== 'redis') throw new Error('Installed Redis cache/locking/session mismatch')
	const redis = JSON.parse(run('docker', ['inspect', `${settings.project}-redis-1`]))[0]
	const network = `${settings.project}_wp9`
	if (redis?.Config?.Labels?.['com.docker.compose.project'] !== settings.project
		|| redis?.Config?.Labels?.['com.docker.compose.service'] !== 'redis'
		|| redis?.Config?.Image !== `${selection.cache.redis.image}@${selection.cache.redis.digest}`
		|| redis?.State?.Health?.Status !== 'healthy'
		|| redis.NetworkSettings?.Networks?.[network]?.IPAddress !== actual.peer
		|| !container.NetworkSettings?.Networks?.[network]) throw new Error('Installed Redis image/ownership/network mismatch')
	return { redis: selection.cache.redis.version, cache: 'redis', locking: 'redis', sessions: 'redis' }
}

function verifyInstallation(selection, settings, run = execute, preflight = assertTestTarget) {
	const target = { NEXTCLOUD_URL: settings.url, NEXTCLOUD_CONTAINER: settings.container }
	preflight(settings.url, target)
	const container = JSON.parse(run('docker', ['inspect', settings.container]))[0]
	if (container?.Image !== settings.imageId || container?.Config?.Labels?.['org.chorestrike.test-runtime.inventory'] !== selectionHash(selection)
		|| container?.Config?.Labels?.['com.docker.compose.project'] !== settings.project
		|| container?.State?.Health?.Status !== 'healthy') throw new Error('Installed candidate image/readiness mismatch')
	for (const directory of ['config', 'data']) {
		if (!container.Mounts?.some(mount => mount.Type === 'volume' && mount.Name === `${settings.project}_${settings.provider}-${directory}`
			&& mount.Destination === `/var/www/html/${directory}`)) throw new Error('Installed candidate owned volume mismatch')
	}
	const code = "require '/var/www/html/version.php'; require '/var/www/html/lib/base.php';"
		+ "$cfg = \\OC::$server->get(\\OC\\SystemConfig::class);"
		+ "$dbtype = $cfg->getValue('dbtype'); $db = \\OC::$server->get(\\OCP\\IDBConnection::class); $params = $db->getInner()->getParams();"
		+ "$matches = $dbtype === 'sqlite3' ? $cfg->getValue('datadirectory') === '/var/www/html/data' && ($params['path'] ?? null) === '/var/www/html/data/owncloud.db'"
		+ ` : ($params['host'] ?? null) === 'db-${settings.provider}' && (string) ($params['port'] ?? '') === '${settings.provider === 'pgsql' ? '5432' : '3306'}'`
		+ " && ($params['dbname'] ?? null) === 'nextcloud';"
		+ "if (!$matches) { fwrite(STDERR, 'Installed database target mismatch.'); exit(1); }"
		+ "$qb = $db->getQueryBuilder(); $function = $dbtype === 'pgsql' ? \"current_setting('server_version')\" : ($dbtype === 'sqlite3' ? 'sqlite_version()' : 'VERSION()');"
		+ "$result = $qb->select($qb->createFunction($function))->executeQuery(); $version = $result->fetchOne(); $result->closeCursor();"
		+ `echo json_encode([${RUNTIME_FIELDS}, 'configuredVersion' => $cfg->getValue('version'), 'provider' => $dbtype, 'databaseVersion' => $version,`
		+ "'databaseTargetMatches' => $matches, 'databasePeer' => $dbtype === 'sqlite3' ? null : gethostbyname($params['host']),"
		+ "'enabled' => \\OC::$server->get(\\OCP\\App\\IAppManager::class)->isEnabledForUser('chorestrike')]);"
	const actual = JSON.parse(run('docker', ['exec', '--user', 'www-data', settings.container, 'php', '-r', code]))
	actual.inventoryHash = container.Config.Labels['org.chorestrike.test-runtime.inventory']
	assertInstalled(selection, actual)
	if (actual.configuredVersion !== selection.nextcloud.internalVersion || actual.enabled !== true || actual.databaseTargetMatches !== true
		|| actual.provider !== (settings.provider === 'sqlite' ? 'sqlite3' : settings.provider)
		|| actual.databaseVersion.match(/^\d+\.\d+(?:\.\d+)?/)?.[0] !== selection.databases[settings.provider].version) {
		throw new Error('Installed app/provider/database version mismatch')
	}
	if (settings.provider !== 'sqlite') {
		const db = JSON.parse(run('docker', ['inspect', `${settings.project}-db-${settings.provider}-1`]))[0]
		const image = JSON.parse(run('docker', ['image', 'inspect', db.Image]))[0]
		assertProviderImage(selection, settings, db, image)
		const network = `${settings.project}_wp9`
		if (!db.NetworkSettings?.Networks?.[network]?.IPAddress
			|| db.NetworkSettings.Networks[network].IPAddress !== actual.databasePeer
			|| !container.NetworkSettings?.Networks?.[network]) throw new Error('Installed database network peer mismatch')
	}
	const cache = settings.redis ? assertRedisCache(selection, settings, container, run) : { cache: 'apcu' }
	return { status: 'installed-runtime-provider-match', qualification: selection.status,
		project: settings.project, provider: settings.provider, imageId: settings.imageId, ...actual, ...cache }
}

function stage(selection, settings, invoke = spawnSync, run = execute) {
	assertFreshProject(settings.project, run)
	const pulls = [
		...(settings.provider !== 'sqlite' ? [selection.databases[settings.provider]] : []),
		...(settings.redis ? [selection.cache.redis] : []),
	]
	for (const image of pulls) {
		const result = invoke('docker', ['pull', '--platform', selection.platform, `${image.image}@${image.digest}`],
			{ cwd: path.resolve(__dirname, '..'), stdio: 'inherit' })
		if (result.error || result.status !== 0) throw new Error('Frozen database/cache pull failed')
	}
	const result = invoke('docker', composeArguments(settings, ['up', '--detach', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '600']),
		{ cwd: path.resolve(__dirname, '..'), env: settings.environment, input: settings.input, encoding: 'utf8', stdio: ['pipe', 'inherit', 'inherit'] })
	if (result.error || result.status !== 0) throw new Error('Owned staging provisioning failed; resources retained for diagnosis')
}

/**
 * Owned-volume preservation re-creation (P1 same-build / P2 pinned update): removes
 * only the owned project's containers/network, keeps its named volumes, recreates
 * them in preservation mode (never a fresh install, never a reseed) and re-proves
 * the installed identity on the expected target image/version.
 */
function preserve(sourceSelection, targetSelection, settings, invoke = spawnSync, run = execute, preflight = assertTestTarget) {
	assertOwnedProject(settings, settings.sourceImageId, run)
	const sourceSettings = stageSettings(sourceSelection, settings.project, settings.provider, settings.sourceImageId, settings.port, {},
		settings.redis ? 'aio' : 'primary')
	verifyInstallation(sourceSelection, sourceSettings, run, preflight)
	const down = invoke('docker', composeArguments(settings, ['down']),
		{ cwd: path.resolve(__dirname, '..'), env: settings.environment, input: settings.input, encoding: 'utf8', stdio: ['pipe', 'inherit', 'inherit'] })
	if (down.error || down.status !== 0) throw new Error('Owned preservation teardown failed; resources retained for diagnosis')
	const up = invoke('docker', composeArguments(settings, ['up', '--detach', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '600']),
		{ cwd: path.resolve(__dirname, '..'), env: settings.environment, input: settings.input, encoding: 'utf8', stdio: ['pipe', 'inherit', 'inherit'] })
	if (up.error || up.status !== 0) throw new Error('Owned preservation re-creation failed; resources retained for diagnosis')
	return verifyInstallation(targetSelection, settings, run, preflight)
}

function main(args) {
	const [command, project, provider, imageName, port, row = 'primary', patch = 'current'] = args
	if (!['up', 'verify', 'preserve'].includes(command) || args.length < 5 || args.length > 7) {
		throw new Error('Usage: node scripts/test-runtime-stage.js up|verify|preserve cs082-<run-id> mysql|pgsql|sqlite cs082-runtime-<name> <port> [primary|aio] [current|previous]')
	}
	const full = readInventory()
	if (command === 'preserve') {
		const source = selectRuntimeSource(full, row, patch)
		const target = selectRuntimeSource(full, row, 'current')
		const targetImage = verifyImage(target, imageName, execute, row, probe, 'current')
		const sourceImageName = `cs082-runtime-${source.nextcloud.version}-local`
		const sourceImage = sourceImageName === imageName && patch === 'current'
			? targetImage
			: verifyImage(source, sourceImageName, execute, row, probe, patch)
		if (source.nextcloud.internalVersion === target.nextcloud.internalVersion && sourceImage.imageId !== targetImage.imageId) {
			throw new Error('Same-version preservation requires the same frozen build')
		}
		const settings = stageSettings(target, project, provider, targetImage.imageId, Number(port), {
			mode: true,
			...(source.nextcloud.internalVersion !== target.nextcloud.internalVersion
				? { upgradeFrom: source.nextcloud.internalVersion } : {}),
		}, row)
		settings.sourceImageId = sourceImage.imageId
		process.stdout.write(`${JSON.stringify(preserve(source, target, settings), null, 2)}\n`)
		return
	}
	const selection = selectRuntimeSource(full, row, patch)
	const image = verifyImage(selection, imageName, execute, row, probe, patch)
	const settings = stageSettings(selection, project, provider, image.imageId, Number(port), {}, row)
	if (command === 'up') stage(selection, settings)
	process.stdout.write(`${JSON.stringify(verifyInstallation(selection, settings), null, 2)}\n`)
}

if (require.main === module) {
	try { main(process.argv.slice(2)) } catch (error) {
		process.stderr.write(`Runtime staging failed: ${error.message}\n`)
		process.exitCode = 1
	}
}

module.exports = { stageSettings, composeArguments, assertFreshProject, assertOwnedProject, assertProviderImage, assertRedisCache, verifyInstallation, stage, preserve }
