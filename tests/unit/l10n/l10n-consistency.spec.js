/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import fs from 'fs'
import path from 'path'

/**
 * Unit tests for l10n key and placeholder consistency between de.json and en.json.
 * These tests serve as regression protection for i18n placeholder interpolation.
 */
describe('l10n Consistency', () => {
	const basePath = path.resolve(__dirname, '../../../l10n')
	const dePath = path.join(basePath, 'de.json')
	const enPath = path.join(basePath, 'en.json')

	const readTranslations = (filePath) => {
		const content = fs.readFileSync(filePath, 'utf8')
		const parsed = JSON.parse(content)
		return parsed.translations || {}
	}

	const extractPlaceholders = (value) => {
		if (typeof value !== 'string') {
			return []
		}
		const matches = value.match(/\{[a-zA-Z0-9_]+\}/g) || []
		return [...new Set(matches)].sort()
	}

	describe('Key Synchronization', () => {
		it('has same keys in de.json and en.json', () => {
			const deTranslations = readTranslations(dePath)
			const enTranslations = readTranslations(enPath)

			const deKeys = Object.keys(deTranslations).sort()
			const enKeys = Object.keys(enTranslations).sort()

			const missingInDe = enKeys.filter((key) => !deTranslations[key])
			const missingInEn = deKeys.filter((key) => !enTranslations[key])

			expect(missingInDe).toEqual([])
			expect(missingInEn).toEqual([])
		})
	})

	describe('Placeholder Consistency', () => {
		it('has matching placeholders in de.json and en.json for all keys', () => {
			const deTranslations = readTranslations(dePath)
			const enTranslations = readTranslations(enPath)

			const deKeys = Object.keys(deTranslations)
			const mismatches = []

			for (const key of deKeys) {
				if (!Object.prototype.hasOwnProperty.call(enTranslations, key)) {
					continue
				}

				const dePlaceholders = extractPlaceholders(deTranslations[key])
				const enPlaceholders = extractPlaceholders(enTranslations[key])

				if (dePlaceholders.join('|') !== enPlaceholders.join('|')) {
					mismatches.push({
						key,
						dePlaceholders,
						enPlaceholders,
					})
				}
			}

			expect(mismatches).toEqual([])
		})

		it('preserves positional PHP placeholders in live weekday validation messages', () => {
			const enTranslations = readTranslations(enPath)
			const deTranslations = readTranslations(dePath)

			const dayPlaceholderKeys = [
				'Invalid weekday: %1$s',
				'%1$s must contain all seven weekdays (missing: %2$s)',
			]

			for (const key of dayPlaceholderKeys) {
				// Check EN has placeholder
				expect(enTranslations[key]).toBeDefined()
				expect(enTranslations[key]).toContain('%1$s')

				// Check DE has placeholder
				expect(deTranslations[key]).toBeDefined()
				expect(deTranslations[key]).toContain('%1$s')
			}
		})
	})

	describe('Translation Coverage', () => {
		it('has German translations for all English keys', () => {
			const deTranslations = readTranslations(dePath)
			const enTranslations = readTranslations(enPath)

			const enKeys = Object.keys(enTranslations)
			const untranslated = enKeys.filter((key) => {
				const deValue = deTranslations[key]
				return !deValue || deValue === ''
			})

			expect(untranslated).toEqual([])
		})

		it('has English translations for all German keys', () => {
			const deTranslations = readTranslations(dePath)
			const enTranslations = readTranslations(enPath)

			const deKeys = Object.keys(deTranslations)
			const untranslated = deKeys.filter((key) => {
				const enValue = enTranslations[key]
				return !enValue || enValue === ''
			})

			expect(untranslated).toEqual([])
		})
	})
})
