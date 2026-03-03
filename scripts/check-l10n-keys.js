#!/usr/bin/env node

/**
 * Validate key and placeholder consistency between l10n/de.json and l10n/en.json.
 */

const fs = require('fs')
const path = require('path')

const basePath = path.resolve(__dirname, '..')
const dePath = path.join(basePath, 'l10n', 'de.json')
const enPath = path.join(basePath, 'l10n', 'en.json')

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

const deTranslations = readTranslations(dePath)
const enTranslations = readTranslations(enPath)

const deKeys = Object.keys(deTranslations).sort()
const enKeys = Object.keys(enTranslations).sort()

const missingInDe = enKeys.filter((key) => !deTranslations[key])
const missingInEn = deKeys.filter((key) => !enTranslations[key])

const placeholderMismatches = []

for (const key of deKeys) {
	if (!Object.prototype.hasOwnProperty.call(enTranslations, key)) {
		continue
	}

	const dePlaceholders = extractPlaceholders(deTranslations[key])
	const enPlaceholders = extractPlaceholders(enTranslations[key])

	if (dePlaceholders.join('|') !== enPlaceholders.join('|')) {
		placeholderMismatches.push({
			key,
			dePlaceholders,
			enPlaceholders,
		})
	}
}

if (missingInDe.length === 0 && missingInEn.length === 0 && placeholderMismatches.length === 0) {
	console.log('l10n key check passed: de.json and en.json are synchronized.')
	process.exit(0)
}

if (missingInDe.length > 0) {
	console.error('Keys missing in de.json:')
	missingInDe.forEach((key) => console.error(`  - ${key}`))
}

if (missingInEn.length > 0) {
	console.error('Keys missing in en.json:')
	missingInEn.forEach((key) => console.error(`  - ${key}`))
}

if (placeholderMismatches.length > 0) {
	console.error('Placeholder mismatches between de.json and en.json:')
	placeholderMismatches.forEach(({ key, dePlaceholders, enPlaceholders }) => {
		console.error(`  - ${key}`)
		console.error(`    de: ${dePlaceholders.join(', ') || '(none)'}`)
		console.error(`    en: ${enPlaceholders.join(', ') || '(none)'}`)
	})
}

process.exit(1)
