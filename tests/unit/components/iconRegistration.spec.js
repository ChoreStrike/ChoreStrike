/**
 * Static guard: tests/unit/setup.js stubs `Icon` and `Emoji` globally, and a
 * global stub also resolves usages a component never registered. That hid a
 * real defect: HouseHealthBar rendered `<Icon>` without importing it, so
 * production showed an empty unknown element instead of its icons.
 *
 * Every SFC that renders one of the stubbed components must import it and,
 * outside `<script setup>`, register it in `components`.
 */
import { readdirSync, readFileSync, statSync } from 'fs'
import { join, relative, resolve } from 'path'

const SRC = resolve(__dirname, '..', '..', '..', 'src')
const STUBBED = ['Icon', 'Emoji']

function vueFiles(dir) {
	return readdirSync(dir).flatMap((entry) => {
		const path = join(dir, entry)
		if (statSync(path).isDirectory()) return vueFiles(path)
		return entry.endsWith('.vue') ? [path] : []
	})
}

function block(source, tag) {
	const match = source.match(new RegExp(`<${tag}(\\s[^>]*)?>([\\s\\S]*)</${tag}>`))
	return match ? { attributes: match[1] || '', body: match[2] } : null
}

describe('globally stubbed components are registered where they are used', () => {
	const files = vueFiles(SRC)

	it('finds the SFCs to check', () => {
		expect(files.length).toBeGreaterThan(50)
	})

	for (const name of STUBBED) {
		it(`every SFC rendering <${name}> imports and registers it`, () => {
			const offenders = files
				.filter((file) => !file.endsWith(`${name}.vue`))
				.filter((file) => {
					const template = block(readFileSync(file, 'utf8'), 'template')
					return template && new RegExp(`<${name}[\\s/>]`).test(template.body)
				})
				.filter((file) => {
					const script = block(readFileSync(file, 'utf8'), 'script')
					if (!script) return true
					const imported = new RegExp(`import\\s+(${name}|\\{[^}]*\\b${name}\\b[^}]*\\})\\s+from`).test(script.body)
					const registered = /\bsetup\b/.test(script.attributes)
						|| new RegExp(`components:\\s*\\{[^}]*\\b${name}\\b`).test(script.body)
					return !(imported && registered)
				})
				.map((file) => relative(SRC, file).replace(/\\/g, '/'))
			expect(offenders).toEqual([])
		})
	}
})
