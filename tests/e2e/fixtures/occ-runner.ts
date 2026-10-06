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
 *   - local dev:            docker/compose.test.yml → `chorestrike-test`
 *   - release-gate CI:      docker/compose.test.yml → `chorestrike-test`
 *   - test.yml CI:          service container       → `nextcloud`
 *
 * Set NEXTCLOUD_CONTAINER to override detection.
 */

import { execFileSync } from 'child_process'
import { configuredTarget, assertTestTarget } from '../../../scripts/test-target'

/**
 * Resolve the configured Nextcloud container without guessing a custom target.
 *
 * @returns The container name hosting the Nextcloud test instance.
 * @throws {Error} When no known Nextcloud container is running.
 */
export function resolveNextcloudContainer(): string {
	return configuredTarget().container
}

/**
 * Run an OCC command inside the Nextcloud container as www-data.
 *
 * Mirrors the canonical seed path used by docker/seed-test-data.sh and the
 * CI workflows (`su -s /bin/bash www-data -c "cd /var/www/html && php occ …"`).
 *
 * @param args OCC arguments, e.g. `chorestrike:trigger-jobs streak-freeze --as-of=2026-08-05`.
 * @param environment Environment variables for non-interactive synthetic test-account provisioning.
 * @returns The command's stdout.
 */
export function runOcc(args: string, environment: Record<string, string> = {}): string {
	const { container } = assertTestTarget(process.env.NEXTCLOUD_URL || 'http://localhost:8080')
	const envArgs = Object.entries(environment).flatMap(([key, value]) => ['--env', `${key}=${value}`])
	return execFileSync('docker', ['exec', ...envArgs, container, 'su', '-s', '/bin/bash', 'www-data',
		'-c', `cd /var/www/html && php occ ${args}`], { encoding: 'utf8' })
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
	if (!Number.isSafeInteger(offsetDays)) throw new Error('Calendar offset must be an integer')
	const container = resolveNextcloudContainer()
	return execFileSync('docker', ['exec', container, 'date', '-d', `${offsetDays} day`, '+%Y-%m-%d'], { encoding: 'utf8' }).trim()
}
