/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * OCC runner for time-travel E2E flows (CS-PLAN-023).
 *
 * The time-travel specs trigger ChoreStrike background jobs through the
 * canonical test-only surface: `occ chorestrike:trigger-jobs` inside the
 * Nextcloud container. This helper shells out to `docker exec` so the
 * specs stay transport-agnostic across all supported environments:
 *
 *   - local dev:            docker-compose.test.yml → `chorestrike-test`
 *   - release-gate CI:      docker-compose.test.yml → `chorestrike-test`
 *   - test.yml CI:          service container       → `nextcloud`
 *
 * Set NEXTCLOUD_CONTAINER to override detection.
 */

import { execSync } from 'child_process'

const CONTAINER_CANDIDATES = ['chorestrike-test', 'nextcloud']

let resolvedContainer: string | null = null

/**
 * Resolve the Nextcloud container name (cached after first hit).
 *
 * @returns The container name hosting the Nextcloud test instance.
 * @throws {Error} When no known Nextcloud container is running.
 */
export function resolveNextcloudContainer(): string {
	if (resolvedContainer) {
		return resolvedContainer
	}

	if (process.env.NEXTCLOUD_CONTAINER) {
		resolvedContainer = process.env.NEXTCLOUD_CONTAINER
		return resolvedContainer
	}

	const names = execSync('docker ps --format "{{.Names}}"', { encoding: 'utf8' })
		.split(/\r?\n/)
		.map(name => name.trim())
		.filter(name => name.length > 0)

	for (const candidate of CONTAINER_CANDIDATES) {
		if (names.includes(candidate)) {
			resolvedContainer = candidate
			return candidate
		}
	}

	throw new Error(
		`occ-runner: no Nextcloud container found among [${names.join(', ')}]. `
		+ `Expected one of [${CONTAINER_CANDIDATES.join(', ')}] or set NEXTCLOUD_CONTAINER.`,
	)
}

/**
 * Run an OCC command inside the Nextcloud container as www-data.
 *
 * Mirrors the canonical seed path used by docker/seed-test-data.sh and the
 * CI workflows (`su -s /bin/bash www-data -c "cd /var/www/html && php occ …"`).
 *
 * @param args OCC arguments, e.g. `chorestrike:trigger-jobs streak-freeze --as-of=2026-08-05`.
 * @returns The command's stdout.
 */
export function runOcc(args: string): string {
	const container = resolveNextcloudContainer()
	const command = `docker exec ${container} su -s /bin/bash www-data -c "cd /var/www/html && php occ ${args}"`
	return execSync(command, { encoding: 'utf8' })
}

/**
 * Get a calendar date derived from the CONTAINER's clock (not the host's).
 *
 * The --as-of value must agree with the container's "today", otherwise
 * host/container timezone skew around midnight would shift "yesterday".
 *
 * @param offsetDays Days relative to the container's today (1 = tomorrow).
 * @returns The date formatted as YYYY-MM-DD.
 */
export function containerDate(offsetDays: number): string {
	const container = resolveNextcloudContainer()
	const command = `docker exec ${container} date -d "${offsetDays} day" +%Y-%m-%d`
	return execSync(command, { encoding: 'utf8' }).trim()
}
