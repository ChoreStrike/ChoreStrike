/** @jest-environment node */
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Public documentation drift guard (CS-PLAN-087 WP-B, TECH-DOCS-007): the
 * README names the app version of appinfo/info.xml, and the README and the
 * user guide show only images that `npm run docs:screenshots` reproduces.
 */

const fs = require('node:fs')
const path = require('node:path')
const { SCENES } = require('../docs-screenshots/scenes')

const ROOT = path.resolve(__dirname, '../..')
const read = relative => fs.readFileSync(path.join(ROOT, relative), 'utf8')
const VERSION = read('appinfo/info.xml').match(/<version>([^<]+)<\/version>/)[1]
const PAGES = ['README.md', 'docs/user-guide.md']

/** Repository-relative paths of the local images a Markdown page shows. */
function imagesOf(page) {
	const text = read(page)
	const sources = [...text.matchAll(/!\[[^\]]*\]\(([^)\s]+)\)/g), ...text.matchAll(/<img\s[^>]*src="([^"]+)"/g)]
		.map(match => match[1])
		.filter(source => !/^https?:/.test(source))
	return sources.map(source => path.posix.join(path.posix.dirname(page), source))
}

describe('public documentation drift', () => {
	it('names the info.xml version in the README status badge and screenshot caption', () => {
		const readme = read('README.md')
		const badge = readme.match(/\[!\[Status: [^\]]*\]\([^)]*\)\]/)[0]
		expect(badge).toContain(`[![Status: alpha ${VERSION}]`)
		expect(badge).toContain(`status-alpha%20${VERSION.replace(/-/g, '--')}-`)
		expect(readme).toMatch(new RegExp(`<sub>Screenshots: [^<]*version ${VERSION.replace(/\./g, '\\.')}\\.</sub>`))
	})

	it.each(PAGES)('%s shows only existing images', page => {
		const images = imagesOf(page)
		expect(images.length).toBeGreaterThan(0)
		images.forEach(image => expect({ image, exists: fs.existsSync(path.join(ROOT, image)) }).toEqual({ image, exists: true }))
	})

	it('shows only captured screenshots, and every capture is shown', () => {
		const captured = SCENES.map(scene => `docs/screenshots/${scene.output}`)
		const shown = PAGES.flatMap(imagesOf).filter(image => image.startsWith('docs/screenshots/'))
		expect([...new Set(shown)].sort()).toEqual([...captured].sort())
	})
})
