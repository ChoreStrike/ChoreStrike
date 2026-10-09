/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { readFileSync, existsSync } from 'fs'
import { resolve } from 'path'

describe('Phase 5 — design tokens + PWA scaffolding', () => {
	const root = resolve(__dirname, '../..')
	const tokensPath = resolve(root, 'src/styles/tokens.css')
	const manifestPath = resolve(root, 'img/manifest.webmanifest')
	const mainJsPath = resolve(root, 'src/main.js')

	it('ships a tokens stylesheet that defines the celebration palette', () => {
		expect(existsSync(tokensPath)).toBe(true)
		const css = readFileSync(tokensPath, 'utf8')
		expect(css).toMatch(/--cs-celebrate-primary/)
		expect(css).toMatch(/--cs-celebrate-gold/)
		expect(css).toMatch(/--cs-tier-bronze/)
		expect(css).toMatch(/--cs-tier-silver/)
		expect(css).toMatch(/--cs-tier-gold/)
		expect(css).toMatch(/--cs-health-full/)
		expect(css).toMatch(/prefers-reduced-motion/)
	})

	it('imports tokens + mobile styles from main.js', () => {
		const main = readFileSync(mainJsPath, 'utf8')
		expect(main).toMatch(/styles\/tokens\.css/)
		expect(main).toMatch(/styles\/mobile\.css/)
	})

	it('localizes the startup fallback without injecting HTML', () => {
		const main = readFileSync(mainJsPath, 'utf8')
		expect(main).toContain("t('chorestrike', 'App failed to load. Check logs.')")
		expect(main).toContain('message.textContent')
		expect(main).not.toContain('appRoot.innerHTML')
	})

	it('ships a PWA manifest with the ChoreStrike brand colour', () => {
		expect(existsSync(manifestPath)).toBe(true)
		const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
		expect(manifest.name).toBe('ChoreStrike')
		expect(manifest.theme_color).toBe('#0984E3')
		expect(manifest.start_url).toMatch(/apps\/chorestrike/)
		expect(Array.isArray(manifest.icons)).toBe(true)
		expect(manifest.icons[0].src).toMatch(/app\.svg$/)
	})

	it('leaves preference loading to the membership-gated app shell', () => {
		const main = readFileSync(mainJsPath, 'utf8')
		expect(main).not.toContain('fetchPreferences(')
		expect(main).not.toContain('usePreferencesStore')
	})
})
