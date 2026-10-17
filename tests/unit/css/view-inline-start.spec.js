/**
 * Static guard: Nextcloud's navigation toggle overlaps the top-left corner of
 * the app content, and a plain 2rem view padding let it cover the first
 * letters of most headings (found while capturing the README screenshots,
 * TECH-DOCS-005). Every routed view's root rule must therefore take its
 * inline-start padding from `--cs-view-inline-start`, also in media-query
 * overrides of its padding shorthand. tests/e2e/layout-clearance.spec.ts
 * measures the rendered result.
 */
import { readFileSync } from 'fs'
import { resolve } from 'path'

const SRC = resolve(__dirname, '..', '..', '..', 'src')

function routedComponents() {
	const router = readFileSync(resolve(SRC, 'router.js'), 'utf8')
	return [...router.matchAll(/import\([^)]*?'\.\/([^']+\.vue)'\)/g)].map(match => match[1])
}

function rootClass(source) {
	const template = source.match(/<template>([\s\S]*)<\/template>/)[1]
	return template.match(/class="([a-z-]+)"/)[1]
}

/** Every `.root { … }` block body, including those nested in media queries. */
function rootBlocks(source, cls) {
	return [...source.matchAll(new RegExp(`^\\s*\\.${cls}\\s*\\{([^}]*)\\}`, 'gm'))].map(match => match[1])
}

describe('routed views clear the Nextcloud navigation toggle', () => {
	const components = routedComponents()

	it('finds the routed views', () => {
		expect(components.length).toBeGreaterThanOrEqual(13)
	})

	it.each(components)('%s pads its inline start with --cs-view-inline-start', (file) => {
		const source = readFileSync(resolve(SRC, file), 'utf8')
		const blocks = rootBlocks(source, rootClass(source)).filter(body => /\bpadding\s*:/.test(body))
		expect(blocks.length).toBeGreaterThan(0)
		for (const body of blocks) {
			expect(body).toMatch(/padding-inline-start:\s*var\(--cs-view-inline-start\)/)
		}
	})

	it('defines the token from the Nextcloud navigation metrics', () => {
		const tokens = readFileSync(resolve(SRC, 'styles', 'tokens.css'), 'utf8')
		expect(tokens).toMatch(/--cs-view-inline-start:\s*calc\(var\(--default-clickable-area[^;]*var\(--app-navigation-padding[^;]*;/)
	})
})
