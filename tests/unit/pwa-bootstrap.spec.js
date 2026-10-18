/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { readFileSync, existsSync } from 'fs'
import { resolve } from 'path'

/**
 * Pixel size from a PNG's IHDR chunk (big-endian width/height at bytes 16/20).
 *
 * @param {string} file PNG path
 * @return {string} "WxH"
 */
function pngSize(file) {
	const header = readFileSync(file).subarray(0, 24)
	expect(header.subarray(1, 4).toString('ascii')).toBe('PNG')
	expect(header.subarray(12, 16).toString('ascii')).toBe('IHDR')
	return `${header.readUInt32BE(16)}x${header.readUInt32BE(20)}`
}

describe('Phase 5 — design tokens + PWA scaffolding', () => {
	const root = resolve(__dirname, '../..')
	const tokensPath = resolve(root, 'src/styles/tokens.css')
	const manifestPath = resolve(root, 'img/manifest.json')
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
	})

	it('uses the Nextcloud manifest convention with install-independent URLs', () => {
		// Core's page layout links image_path(app, 'manifest.json'); without
		// that file it falls back to the theming manifest (CS-PLAN-087 WP-A).
		expect(existsSync(resolve(root, 'img/manifest.webmanifest'))).toBe(false)
		const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
		// Relative to the served manifest: works under /apps or /custom_apps,
		// in a subdirectory install and without pretty URLs.
		for (const served of ['https://cloud.example/custom_apps/chorestrike/img/manifest.json', 'https://cloud.example/nc/apps/chorestrike/img/manifest.json']) {
			const appRoute = new URL('../../../index.php/apps/chorestrike/', served).href
			expect(new URL(manifest.start_url, served).href).toBe(appRoute)
			expect(new URL(manifest.scope, served).href).toBe(appRoute)
		}
	})

	it('lists the mascot PNGs relative to the manifest with their real pixel sizes', () => {
		// The manifest is served from the app's img/ directory, wherever the
		// app root is (/custom_apps in AIO installs): only relative paths work.
		const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
		const icons = manifest.icons

		expect(icons.map((icon) => icon.purpose).sort()).toEqual(['any', 'any', 'maskable'])
		for (const icon of icons) {
			expect(icon.src).toMatch(/^[\w-]+\.png$/)
			expect(icon.type).toBe('image/png')
			const file = resolve(root, 'img', icon.src)
			expect(existsSync(file)).toBe(true)
			expect(icon.sizes).toBe(pngSize(file))
		}
		expect(icons.map((icon) => icon.sizes)).toEqual(expect.arrayContaining(['192x192', '512x512']))
	})

	it('leaves preference loading to the membership-gated app shell', () => {
		const main = readFileSync(mainJsPath, 'utf8')
		expect(main).not.toContain('fetchPreferences(')
		expect(main).not.toContain('usePreferencesStore')
	})
})

/**
 * Nextcloud app icons (CS-PLAN-087 WP-A). The header shows img/app.svg at
 * 20 px with `--background-image-invert-if-bright`, the notifications
 * popover img/app-dark.svg with `--background-invert-if-dark`: both must be
 * flat monochrome glyphs, or the filters turn colors into noise.
 */
describe('Nextcloud app icons', () => {
	const root = resolve(__dirname, '../..')
	const read = (name) => readFileSync(resolve(root, 'img', name), 'utf8')
	const fills = (svg) => [...svg.matchAll(/\sfill="([^"]*)"/g)].map((match) => match[1])
	const geometry = (svg) => svg.replace(/<!--[\s\S]*?-->/g, '').replace(/\sfill="#fff"/g, '').replace(/\s+/g, ' ')

	it.each(['app.svg', 'app-dark.svg'])('%s is a square flat glyph without external references', (name) => {
		const svg = read(name)
		const viewBox = svg.match(/viewBox="0 0 (\d+) (\d+)"/)
		expect(viewBox).not.toBeNull()
		expect(viewBox[1]).toBe(viewBox[2])
		expect(svg).not.toMatch(/Gradient|<image|<style|<text|href=|url\(|stroke|opacity|style=/)
	})

	it('app.svg is filled white only', () => {
		expect(fills(read('app.svg'))).toEqual(['#fff'])
	})

	it('app-dark.svg keeps the default (black) fill and the same geometry', () => {
		expect(fills(read('app-dark.svg'))).toEqual([])
		expect(geometry(read('app-dark.svg'))).toBe(geometry(read('app.svg')))
	})
})
