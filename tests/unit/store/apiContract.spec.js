/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * API Contract Test
 *
 * Verifies that every API endpoint called by the Pinia stores in
 * `src/store/*.js` via the shared `api` client matches at least one
 * route declared in `appinfo/routes.php`. The route table is the
 * single source of truth — derived from the same source
 * `RouteMatrixTest` (PHPUnit) parses.
 *
 * Catches the historical drift patterns:
 *   - Store calls an un-routed endpoint (would return 404 at runtime).
 *   - Route is declared but no api call references it (dead route).
 *
 * The stores use the shared API client (`src/services/api.js`) with
 * paths relative to `API_PREFIX = /apps/chorestrike`. The effective
 * URL is `${API_PREFIX}${path}`.
 */

import { describe, it, expect } from '@jest/globals'
import { readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'

const PROJECT_ROOT = resolve(__dirname, '..', '..', '..')
const ROUTES_FILE = join(PROJECT_ROOT, 'appinfo', 'routes.php')
const STORES_DIR = join(PROJECT_ROOT, 'src', 'store')
const SERVICES_DIR = join(PROJECT_ROOT, 'src', 'services')
const API_PREFIX = '/apps/chorestrike'
const DEPRECATED_PREFIX_RE = /^\/index\.php\/apps\/chorestrike/i

function parsePhpRoutesArray(content) {
	const routes = []
	// name/url/verb in order, optionally followed by a 'requirements' map
	// (added by CS-PLAN-061 §3.7: `\d+` id constraints etc.). Requirement
	// values may themselves contain bracket expressions like `[a-z0-9-]+`,
	// so the map body is matched with one level of bracket nesting.
	const routeRegex = /\[\s*'name'\s*=>\s*'([^']+)'\s*,\s*'url'\s*=>\s*'([^']+)'\s*,\s*'verb'\s*=>\s*'([^']+)'(?:,\s*'requirements'\s*=>\s*\[(?:[^\[\]]|\[[^\[\]]*\])*\])?\s*\]/g
	let m
	while ((m = routeRegex.exec(content)) !== null) {
		routes.push({ name: m[1], url: m[2], verb: m[3] })
	}
	if (routes.length === 0) {
		throw new Error('Could not parse any routes from appinfo/routes.php')
	}
	return routes
}

function routeToRegexPattern(routeUrl) {
	const path = routeUrl.split('?')[0]
	const escaped = path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	return '^' + escaped.replace(/\\\{[^}]+\\\}/g, '[^/]+') + '$'
}

function extractStoreEndpoints() {
	const files = readdirSync(STORES_DIR).filter((f) => f.endsWith('.js'))
	const endpoints = []
	const apiCallRegex = /api\.(get|post|put|delete|patch)\(\s*([`'"])(\/api\/v1\/[^`'"]+?)\2/g

	for (const file of files) {
		const content = readFileSync(join(STORES_DIR, file), 'utf-8')
		let m
		while ((m = apiCallRegex.exec(content)) !== null) {
			const method = m[1].toUpperCase()
			const path = m[3]
			endpoints.push({ file, method, path })
		}
	}
	return endpoints
}

function extractServiceEndpoints() {
	const files = readdirSync(SERVICES_DIR).filter((f) => f.endsWith('.js'))
	const endpoints = []
	for (const file of files) {
		const content = readFileSync(join(SERVICES_DIR, file), 'utf-8')
		const axiosCallRegex = /axios\(\s*\{\s*method:\s*['"]([^'"]+)['"]\s*,\s*url:\s*`\$\{API_PREFIX\}([^`]+)`/g
		let m
		while ((m = axiosCallRegex.exec(content)) !== null) {
			const method = m[1].toUpperCase()
			const path = m[2]
			endpoints.push({ file, method, path })
		}
	}
	return endpoints
}

function normaliseStoreUrl(path) {
	const pathOnly = path.split('?')[0]
	return pathOnly.replace(/\$\{[^}]+\}/g, '[^/]+').replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

describe('API Contract — stores → routes', () => {
	const routes = parsePhpRoutesArray(readFileSync(ROUTES_FILE, 'utf-8'))
	const routePatterns = routes.map((r) => ({ route: r, pattern: routeToRegexPattern(r.url) }))

	it('loads at least one route from appinfo/routes.php', () => {
		expect(routes.length).toBeGreaterThan(10)
	})

	it('every store api call matches at least one declared route', () => {
		const endpoints = extractStoreEndpoints()
		expect(endpoints.length).toBeGreaterThan(0)

		const unrouteable = []
		for (const ep of endpoints) {
			const normalised = '^' + normaliseStoreUrl(ep.path) + '$'
			const matched = routePatterns.some((rp) => {
				if (rp.route.verb !== ep.method) {
					return false
				}
				const re = new RegExp(rp.pattern)
				return re.test(ep.path) || re.test(normalised)
			})
			if (!matched) {
				unrouteable.push(`${ep.file}: ${ep.method} ${ep.path} (no matching route)`)
			}
		}
		if (unrouteable.length > 0) {
			throw new Error('Unrouteable store endpoints:\n' + unrouteable.join('\n'))
		}
		expect(unrouteable).toEqual([])
	})

	it('every service axios call matches at least one declared route', () => {
		const endpoints = extractServiceEndpoints()
		if (endpoints.length === 0) {
			return
		}

		const unrouteable = []
		for (const ep of endpoints) {
			const normalised = '^' + normaliseStoreUrl(ep.path) + '$'
			const matched = routePatterns.some((rp) => {
				if (rp.route.verb !== ep.method) {
					return false
				}
				const re = new RegExp(rp.pattern)
				return re.test(ep.path) || re.test(normalised)
			})
			if (!matched) {
				unrouteable.push(`${ep.file}: ${ep.method} ${ep.path} (no matching route)`)
			}
		}
		if (unrouteable.length > 0) {
			throw new Error('Unrouteable service endpoints:\n' + unrouteable.join('\n'))
		}
		expect(unrouteable).toEqual([])
	})

	it('no store uses the deprecated /index.php/apps/chorestrike prefix', () => {
		const files = readdirSync(STORES_DIR).filter((f) => f.endsWith('.js'))
		const violations = []

		for (const file of files) {
			const content = readFileSync(join(STORES_DIR, file), 'utf-8')
			if (DEPRECATED_PREFIX_RE.test(content)) {
				violations.push(file)
			}
		}

		expect(violations).toEqual([])
	})

	it('no store calls the removed completeWithTeam endpoint', () => {
		const endpoints = extractStoreEndpoints()
		const usesDead = endpoints.some((ep) => ep.path.includes('complete-with-team'))
		expect(usesDead).toBe(false)
	})
})