/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { hasIcon } from '@/utils/icons'

/**
 * Navigation icon guard (CS-PLAN-087 WP-A, CR-NAV-01).
 *
 * Every app navigation entry renders its glyph through the `#icon` slot with
 * the app's Icon component. Legacy `icon-*` classes rendered nothing for five
 * entries, and `getIcon()` silently returns undefined for an unknown name, so
 * the guard checks the registry, not just a non-empty attribute. Jest stubs
 * Icon globally, which would mask a missing local registration — the
 * registration is pinned statically here.
 */
describe('App navigation icons', () => {
	const source = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
	const template = source.slice(source.indexOf('<template>'), source.indexOf('<script>'))
	const script = source.slice(source.indexOf('<script>'))
	const entries = [...template.matchAll(/<NcAppNavigationItem\b([^>]*)>([\s\S]*?)<\/NcAppNavigationItem>/g)]
		.map(([, attributes, body]) => ({
			testId: attributes.match(/data-testid="([^"]+)"/)?.[1],
			attributes,
			icon: body.match(/<template #icon>\s*<Icon name="([^"]+)"/)?.[1],
		}))

	it('finds all eleven navigation entries, each closed with an icon slot', () => {
		expect(entries.map((entry) => entry.testId)).toEqual([
			'nav-today', 'nav-dashboard', 'nav-tasks', 'nav-leaderboard', 'nav-insights',
			'nav-scheduling', 'nav-calendar', 'nav-arcade', 'nav-rewards',
			'nav-reward-requests', 'nav-settings',
		])
		expect(template).not.toMatch(/<NcAppNavigationItem\b[^>]*\/>/)
	})

	it('uses no legacy icon class prop', () => {
		for (const entry of entries) {
			expect({ testId: entry.testId, legacy: /(^|\s):?icon=/.test(entry.attributes) })
				.toEqual({ testId: entry.testId, legacy: false })
		}
	})

	it('maps every entry to a registered glyph', () => {
		expect(hasIcon('NotARegisteredGlyph')).toBe(false)
		for (const entry of entries) {
			expect({ testId: entry.testId, registered: Boolean(entry.icon) && hasIcon(entry.icon) })
				.toEqual({ testId: entry.testId, registered: true })
		}
	})

	it('gives every entry its own glyph', () => {
		const icons = entries.map((entry) => entry.icon)
		expect(new Set(icons).size).toBe(icons.length)
	})

	it('registers the Icon component locally', () => {
		expect(script).toContain("import Icon from './components/Common/Icon.vue'")
		expect(script).toMatch(/components: \{[^}]*\bIcon,/)
	})
})
