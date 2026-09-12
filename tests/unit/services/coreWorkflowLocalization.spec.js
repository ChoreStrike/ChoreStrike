/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * CS-PLAN-066 (D7, CR-053-13 WP7 core slice): every t() key reachable in the
 * WP7 family journey must exist in the en AND de artifacts with matching
 * placeholders, and in the POT template. `l10n:check-keys` is a de↔en parity
 * check only — this spec pins SOURCE coverage of the core surfaces, so no
 * untranslated core string can defer silently to WP9.
 */

import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'

const PROJECT_ROOT = resolve(__dirname, '..', '..', '..')

// The WP7 core journey surfaces (plan §3.5 table).
const CORE_SURFACES = [
	'src/App.vue',
	'src/router.js',
	'src/views/TodayView.vue',
	'src/views/Dashboard.vue',
	'src/views/TaskManager.vue',
	'src/views/RoomDetail.vue',
	'src/components/Dashboard/QuickStats.vue',
	'src/components/Dashboard/HouseHealthBar.vue',
	'src/components/Dashboard/FloorList.vue',
	'src/components/Dashboard/ArcadeSection.vue',
	'src/components/Tasks/TaskList.vue',
	'src/components/Tasks/TaskEditForm.vue',
	'src/components/Tasks/TaskChecklist.vue',
	'src/components/Setup/SetupWizard.vue',
	'src/components/Members/MemberList.vue',
	'src/components/Common/UndoSnackbar.vue',
	'src/components/Common/NotAuthorizedState.vue',
	'src/components/Common/ErrorState.vue',
	'src/components/Common/EmptyState.vue',
	'src/components/Common/ConfirmDialog.vue',
]

const extractKeys = (content) => {
	// t('chorestrike', 'Key' …) — single-quoted first argument, tolerating
	// escaped quotes. Multi-line calls are picked up per line of source.
	const keys = []
	const keyRe = /t\(\s*'chorestrike'\s*,\s*'((?:[^'\\]|\\.)*)'/g
	let m
	while ((m = keyRe.exec(content)) !== null) {
		keys.push(m[1].replace(/\\'/g, "'"))
	}
	return keys
}

const extractPlaceholders = (value) => [...new Set(value.match(/\{[a-zA-Z0-9_]+\}/g) || [])].sort()

const collectCoreKeys = () => {
	const byKey = new Map()
	for (const rel of CORE_SURFACES) {
		const content = readFileSync(join(PROJECT_ROOT, rel), 'utf8')
		for (const key of extractKeys(content)) {
			if (!byKey.has(key)) byKey.set(key, [])
			byKey.get(key).push(rel)
		}
	}
	return byKey
}

describe('WP7 core workflow localization (CS-PLAN-066 D7)', () => {
	const en = JSON.parse(readFileSync(join(PROJECT_ROOT, 'l10n', 'en.json'), 'utf8')).translations
	const de = JSON.parse(readFileSync(join(PROJECT_ROOT, 'l10n', 'de.json'), 'utf8')).translations
	const pot = readFileSync(join(PROJECT_ROOT, 'l10n', 'templates', 'chorestrike.pot'), 'utf8')
	const coreKeys = collectCoreKeys()

	it('finds a substantial core key inventory (audit sanity)', () => {
		expect(coreKeys.size).toBeGreaterThan(150)
	})

	it('every core surface key exists in en.json and de.json', () => {
		const missing = []
		for (const [key, sources] of coreKeys) {
			if (!(key in en)) missing.push(`en: "${key}" (${sources[0]})`)
			if (!(key in de)) missing.push(`de: "${key}" (${sources[0]})`)
		}
		expect(missing).toEqual([])
	})

	it('every core surface key exists in the POT template', () => {
		const missing = []
		for (const [key] of coreKeys) {
			const escaped = key.replace(/\\/g, '\\\\').replace(/"/g, '\\"')
			if (!pot.includes(`msgid "${escaped}"`)) missing.push(`"${key}"`)
		}
		expect(missing).toEqual([])
	})

	it('de translations keep the en placeholders for every core key', () => {
		const mismatches = []
		for (const [key] of coreKeys) {
			if (!(key in de) || !(key in en)) continue
			const enPh = extractPlaceholders(en[key])
			const dePh = extractPlaceholders(de[key])
			if (enPh.join('|') !== dePh.join('|')) mismatches.push(key)
		}
		expect(mismatches).toEqual([])
	})

	it('renders representative EN and DE copy through the artifacts', () => {
		// Representative core journey strings: EN identity, real German copy.
		expect(en['My Day']).toBe('My Day')
		expect(de['My Day']).toBe('Mein Tag')
		expect(en['Some views could not be refreshed']).toBe('Some views could not be refreshed')
		expect(de['Some views could not be refreshed']).toBe('Einige Ansichten konnten nicht aktualisiert werden')
		expect(de['Checklist ({done}/{total})']).toBe('Checkliste ({done}/{total})')
		expect(de['Could not complete the task']).toBeTruthy()
		expect(de['Overdue']).toBeTruthy()
	})
})
