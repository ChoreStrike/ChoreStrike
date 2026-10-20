/**
 * Static guard for the view layout tokens (TECH-DOCS-005, CR-LAYOUT-01).
 *
 * Nextcloud's navigation toggle overlaps the top-left corner of the app
 * content; a plain 2rem view padding let it cover the first letters of most
 * headings. Only the heading row sits next to the toggle, so:
 *
 *  - every routed view takes its inline padding from the shared
 *    `--cs-view-gutter`, in its media-query overrides too, so the content
 *    below the heading keeps the gutter;
 *  - the view's `<header>` adds `--cs-view-heading-inset`, the clearance the
 *    toggle needs on top of the gutter;
 *  - the tokens are declared on `.app-content`, where Nextcloud resolves
 *    `--default-clickable-area` and `--app-navigation-padding`, not at `:root`.
 *
 * tests/e2e/layout-clearance.spec.ts measures the rendered result.
 */
import { readFileSync } from 'fs'
import { resolve } from 'path'

const SRC = resolve(__dirname, '..', '..', '..', 'src')

function routedComponents() {
	const router = readFileSync(resolve(SRC, 'router.js'), 'utf8')
	return [...router.matchAll(/import\([^)]*?'\.\/([^']+\.vue)'\)/g)].map(match => match[1])
}

function template(source) {
	return source.match(/<template>([\s\S]*)<\/template>/)[1]
}

function rootClass(source) {
	return template(source).match(/class="([a-z-]+)"/)[1]
}

function headerClass(source) {
	return template(source).match(/<header\s+class="([a-z-]+)"/)[1]
}

/** Every `.cls { … }` block body, including those nested in media queries. */
function ruleBlocks(source, cls) {
	return [...source.matchAll(new RegExp(`^\\s*\\.${cls}\\s*\\{([^}]*)\\}`, 'gm'))].map(match => match[1])
}

describe('routed views clear the Nextcloud navigation toggle with their heading row', () => {
	const components = routedComponents()

	it('finds the routed views', () => {
		expect(components.length).toBeGreaterThanOrEqual(13)
	})

	it.each(components)('%s keeps the gutter on its root and clears the toggle in its header', (file) => {
		const source = readFileSync(resolve(SRC, file), 'utf8')

		const rootBlocks = ruleBlocks(source, rootClass(source)).filter(body => /\bpadding\s*:/.test(body))
		expect(rootBlocks.length).toBeGreaterThan(0)
		for (const body of rootBlocks) {
			expect(body).toMatch(/\bpadding:[^;]*var\(--cs-view-gutter\)/)
			expect(body).not.toMatch(/padding-inline-start/)
		}

		const headerBlocks = ruleBlocks(source, headerClass(source))
		expect(headerBlocks.some(body => /padding-inline-start:\s*var\(--cs-view-heading-inset\)/.test(body))).toBe(true)
		expect(source).not.toMatch(/--cs-view-inline-start/)
	})

	describe('the layout tokens', () => {
		const tokens = readFileSync(resolve(SRC, 'styles', 'tokens.css'), 'utf8')
		const declaredOn = (selector) => {
			const match = tokens.match(new RegExp(`^${selector.replace('.', '\\.')}\\s*\\{([^}]*)\\}`, 'm'))
			return match ? match[1] : ''
		}

		it('are declared where Nextcloud resolves the navigation metrics, not at :root', () => {
			const appContent = declaredOn('.app-content')
			expect(appContent).toMatch(/--cs-view-inline-start:\s*calc\(var\(--default-clickable-area[^;]*var\(--app-navigation-padding[^;]*;/)
			expect(appContent).toMatch(/--cs-view-gutter:\s*2rem;/)
			expect(appContent).toMatch(/--cs-view-heading-inset:\s*max\(0px,\s*calc\(var\(--cs-view-inline-start\)\s*-\s*var\(--cs-view-gutter\)\)\);/)

			expect(declaredOn(':root')).not.toMatch(/--cs-view-(gutter|inline-start|heading-inset)/)
		})

		it('narrow the gutter on phones', () => {
			expect(tokens).toMatch(/@media \(max-width: 768px\)\s*\{\s*\.app-content\s*\{\s*--cs-view-gutter:\s*1rem;/)
		})
	})
})
