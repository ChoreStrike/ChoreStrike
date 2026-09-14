const fs = require('node:fs')
const path = require('node:path')
const { extractKeysFromSource, collectSourceKeys, extractPlaceholders, validate, regenerate, potKeys } = require('../../../scripts/check-l10n-keys.js')

const ROOT = path.resolve(__dirname, '../../..')
const keys = new Set(['One task', '{n} tasks', 'Hi %1$s', 'titles.pack.home_helpers'])
const en = { 'One task': 'One task', '{n} tasks': '{n} tasks', 'Hi %1$s': 'Hi %1$s', 'titles.pack.home_helpers': 'Home Helpers' }
const de = { 'One task': 'Eine Aufgabe', '{n} tasks': '{n} Aufgaben', 'Hi %1$s': 'Hallo %1$s', 'titles.pack.home_helpers': 'Helfer zu Hause' }
const pot = regenerate(keys, { translations: en }, { translations: de }).pot

describe('global localization gate', () => {
	it('extracts escaped, multiline and double-quoted literal calls without comments', () => {
		const source = `// t('chorestrike', 'Not live')\n t('chorestrike', 'It\\'s ready')\n t(\n"chorestrike",\n"Line\\nNext")`
		expect(extractKeysFromSource('src/example.vue', source)).toEqual(["It's ready", 'Line\nNext'])
		expect(extractKeysFromSource('lib/Example.php', `$l->t('Line\\nNext'); $l->t("Line\\nNext");`)).toEqual(['Line\\nNext', 'Line\nNext'])
	})
	it('rejects unclassified dynamic keys and concatenated literal prefixes', () => {
		expect(() => extractKeysFromSource('src/new.js', "t('chorestrike', unknownKey)")).toThrow('Unclassified')
		expect(() => extractKeysFromSource('src/new.js', "t('chorestrike', 'prefix' + value)")).toThrow('Unclassified')
	})
	it('owns every live title and template label from its source catalog', () => {
		const actual = collectSourceKeys()
		const titles = fs.readFileSync(path.join(ROOT, 'lib/Titles/TitleCatalog.php'), 'utf8')
		for (const match of titles.matchAll(/'labelKey' => '([^']+)'/g)) expect(actual.has(match[1])).toBe(true)
		const templates = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Templates/TaskTemplates.json'), 'utf8')).templates
		for (const template of templates) for (const field of ['name', 'description', 'instructions']) expect(actual.has(template[field])).toBe(true)
		for (const label of ['Daily', 'Every {n} days', 'Quick', 'Team Anchor']) expect(actual.has(label)).toBe(true)
	})
	it('accepts complete artifacts with real German copy', () => expect(validate(keys, en, de, pot)).toEqual([]))
	it.each([...keys])('rejects a missing literal/plural/catalog key in both locales: %s', key => {
		const a = { ...en }, b = { ...de }
		delete a[key]; delete b[key]
		expect(validate(keys, a, b, pot)).toEqual(expect.arrayContaining([`en: missing ${key}`, `de: missing ${key}`]))
	})
	it('rejects missing POT files, entries and dead keys', () => {
		expect(validate(keys, en, de, '')).toContain('POT: missing One task')
		expect(validate(keys, en, de, pot.replace('msgid "One task"', 'msgid "Dead"'))).toContain('POT: missing One task')
		expect(validate(keys, { ...en, Dead: 'Dead' }, de, pot)).toContain('en: dead key Dead')
	})
	it('compares both locales to named and positional source placeholders', () => {
		expect(validate(keys, { ...en, '{n} tasks': 'tasks' }, { ...de, '{n} tasks': 'Aufgaben' }, pot)).toContain('en: placeholder mismatch {n} tasks')
		expect(validate(keys, en, { ...de, 'Hi %1$s': 'Hallo' }, pot)).toContain('de: placeholder mismatch Hi %1$s')
		expect(extractPlaceholders('100% clean; %% and %2$d for {name}')).toEqual(['%2$d', '{name}'])
	})
	it('regenerates deterministically, preserves copy and never invents German', () => {
		const first = regenerate(keys, { translations: { ...en, Dead: 'unused' } }, { translations: de })
		const second = regenerate(keys, JSON.parse(first.en), JSON.parse(first.de))
		expect(second).toEqual(first)
		expect(JSON.parse(second.de).translations).toEqual(de)
		const missing = regenerate(new Set(['New']), { translations: {} }, { translations: {} })
		expect(JSON.parse(missing.de).translations.New).toBe('')
		expect(validate(new Set(['New']), { New: 'New' }, { New: '' }, missing.pot)).toContain('de: missing New')
	})
	it('reads multiline gettext entries', () => {
		expect([...potKeys('msgid ""\n"Line\\n"\n"Next"\nmsgstr ""\n')]).toEqual(['Line\nNext'])
	})

	it('checked-in artifacts are the exact idempotent regeneration result', () => {
		const english = fs.readFileSync(path.join(ROOT, 'l10n/en.json'), 'utf8')
		const german = fs.readFileSync(path.join(ROOT, 'l10n/de.json'), 'utf8')
		const template = fs.readFileSync(path.join(ROOT, 'l10n/templates/chorestrike.pot'), 'utf8')
		expect(regenerate(collectSourceKeys(), JSON.parse(english), JSON.parse(german))).toEqual({ en: english, de: german, pot: template })
	})
})
