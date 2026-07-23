/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * API Contract Test
 *
 * Verifies that every API endpoint called by the Pinia stores in
 * `src/store/*.js` matches at least one route declared in
 * `appinfo/routes.php`. The route table is the single source of truth —
 * derived from the same source `RouteMatrixTest` (PHPUnit) parses.
 *
 * Catches the historical drift patterns:
 *   - Store calls an un-routed endpoint (would return 404 at runtime).
 *   - Route is declared but no store calls it (dead route, low priority).
 *
 * The test normalises both sides:
 *   - Routes: `{id}` placeholders → `[^/]+`, query strings stripped.
 *   - Store calls: template-literal `${var}` placeholders → `[^/]+`,
 *     base path (`/index.php/apps/chorestrike` or `/apps/chorestrike`) stripped,
 *     query strings stripped.
 */

import { describe, it, expect } from '@jest/globals'
import { readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'

const PROJECT_ROOT = resolve(__dirname, '..', '..', '..')
const ROUTES_FILE = join(PROJECT_ROOT, 'appinfo', 'routes.php')
const STORES_DIR = join(PROJECT_ROOT, 'src', 'store')
const API_PREFIX_RE = /^\/?(index\.php\/)?apps\/chorestrike\//i

function parsePhpRoutesArray(content) {
	const routes = []
	const routeRegex = /\[\s*'name'\s*=>\s*'([^']+)'\s*,\s*'url'\s*=>\s*'([^']+)'\s*,\s*'verb'\s*=>\s*'([^']+)'\s*\]/g
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
	const axiosCallRegex = /axios\.(get|post|put|delete|patch)\(\s*([`'"])([^`'"]+?)\2/g

	for (const file of files) {
		const content = readFileSync(join(STORES_DIR, file), 'utf-8')
		let m
		while ((m = axiosCallRegex.exec(content)) !== null) {
			const method = m[1].toUpperCase()
			const rawUrl = m[3]
			const stripped = rawUrl.replace(API_PREFIX_RE, '/')
			if (stripped.includes('/api/v1/')) {
				endpoints.push({ file, method, url: stripped })
			}
		}
	}
	return endpoints
}

function endpointPath(url) {
	return url.split('?')[0].replace(API_PREFIX_RE, '/')
}

function normaliseStoreUrl(url) {
	const pathOnly = url.split('?')[0]
	return pathOnly.replace(/\$\{[^}]+\}/g, '[^/]+').replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

describe('API Contract — stores → routes', () => {
	const routes = parsePhpRoutesArray(readFileSync(ROUTES_FILE, 'utf-8'))
	const routePatterns = routes.map((r) => ({ route: r, pattern: routeToRegexPattern(r.url) }))

	it('loads at least one route from appinfo/routes.php', () => {
		expect(routes.length).toBeGreaterThan(10)
	})

	it('every store axios call matches at least one declared route', () => {
		const endpoints = extractStoreEndpoints()
		expect(endpoints.length).toBeGreaterThan(0)

		const unrouteable = []
		for (const ep of endpoints) {
			const path = endpointPath(ep.url)
			const normalised = '^' + normaliseStoreUrl(path) + '$'
			const matched = routePatterns.some((rp) => {
				if (rp.route.verb !== ep.method) {
					return false
				}
				const re = new RegExp(rp.pattern)
				return re.test(path) || re.test(normalised)
			})
			if (!matched) {
				unrouteable.push(`${ep.file}: ${ep.method} ${path} (no matching route)`)
			}
		}
		if (unrouteable.length > 0) {
			throw new Error('Unrouteable store endpoints:\n' + unrouteable.join('\n'))
		}
		expect(unrouteable).toEqual([])
	})

	it('no store calls the removed completeWithTeam endpoint', () => {
		const endpoints = extractStoreEndpoints()
		const usesDead = endpoints.some((ep) => ep.url.includes('complete-with-team'))
		expect(usesDead).toBe(false)
	})
})
