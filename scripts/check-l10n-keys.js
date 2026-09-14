#!/usr/bin/env node

/** Global source/catalog -> POT -> EN/DE gate. Default is strictly read-only. */
const fs = require('node:fs')
const path = require('node:path')
const ROOT = path.resolve(__dirname, '..')
const LITERAL = /^(?:'(?:\\[\s\S]|[^'\\])*'|"(?:\\[\s\S]|[^"\\])*")/
const TOKENS = /'(?:\\[\s\S]|[^'\\])*'|"(?:\\[\s\S]|[^"\\])*"|\/\*[\s\S]*?\*\/|\/\/[^\n]*/g
const EXCLUDED = new Set(['lib/Controller/TestController.php', 'lib/Service/TestDataService.php', 'lib/Command/TriggerJobs.php', 'lib/Command/TestDataSeed.php'])
// Exact variable consumers, each paired with a finite source owner below.
const DYNAMIC = {
	'src/constants/titles.js': ['title.labelKey'],
	'src/components/Profile/TitlePicker.vue': ['key', 'tier.labelKey'],
	'src/components/Common/MedalBadge.vue': ['key'],
	'src/views/Leaderboard.vue': ['MEDAL_LABELS[medal.medalType]'],
	'src/components/Tasks/TaskEditForm.vue': ['energyLabels[n]', 'frequencyLabel', 'everyKey'],
	'lib/Service/TaskService.php': ['$message'],
	'lib/Notification/ChoreStrikeNotifier.php': ['$titleLabelKey', '$packLabelKey'],
	'lib/Service/TemplateService.php': ["(string)$rt['defaultName']", "(string)$template['name']", "(string)($template['description']??'')", "(string)($template['instructions']??'')", "(string)$t['name']", "(string)($t['description']??'')", "(string)($t['instructions']??'')"],
}

function decodeLiteral(token, php = false) {
	const body = token.slice(1, -1)
	if (php && token[0] === "'") return body.replace(/\\(['\\])/g, '$1')
	return body.replace(/\\(?:\r?\n|u[\da-fA-F]{4}|x[\da-fA-F]{2}|[\s\S])/g, escape => {
		if (/^\\[ux]/.test(escape)) return String.fromCharCode(parseInt(escape.slice(2), 16))
		return ({ '\\n': '\n', '\\r': '\r', '\\t': '\t', '\\b': '\b', '\\f': '\f', '\\v': '\v', '\\0': '\0', '\\\n': '', '\\\r\n': '' })[escape] ?? escape.slice(1)
	})
}

function firstArgument(source) {
	let depth = 0
	for (let i = 0; i < source.length; i++) {
		const literal = source.slice(i).match(LITERAL)
		if (literal) { i += literal[0].length - 1; continue }
		if (depth === 0 && [',', ')'].includes(source[i])) return source.slice(0, i).trim()
		if ('([{'.includes(source[i])) depth++
		if (')]}'.includes(source[i])) depth--
	}
	throw new Error('Unterminated localization call')
}

function extractKeysFromSource(file, content) {
	file = file.replace(/\\/g, '/')
	if (EXCLUDED.has(file)) return []
	const source = content.replace(/<!--[\s\S]*?-->/g, '').replace(TOKENS, token => token.startsWith('/') ? '' : token)
	const php = file.endsWith('.php')
	const calls = php ? /->t\s*\(\s*/g : /\bt\s*\(\s*(['"])chorestrike\1\s*,\s*/g
	const keys = []
	for (const match of source.matchAll(calls)) {
		const argument = firstArgument(source.slice(match.index + match[0].length))
		const literal = argument.match(LITERAL)
		if (literal?.[0] === argument) keys.push(decodeLiteral(argument, php))
		else if (!(DYNAMIC[file] ?? []).includes(argument.replace(/\s+/g, ''))) throw new Error(`Unclassified dynamic localization: ${file}: ${argument}`)
	}
	// Local finite maps and ternary branches are owned by their declarations.
	if (file === 'src/components/Tasks/TaskEditForm.vue' || file.endsWith('/MedalBadge.vue')) {
		const name = file.endsWith('/MedalBadge.vue') ? 'MEDAL_LABELS' : 'energyLabels'
		const map = source.match(new RegExp(`const ${name} = \\{([\\s\\S]*?)\\}`))
		if (!map) throw new Error(`Missing dynamic map: ${file}: ${name}`)
		for (const entry of map[1].matchAll(/:\s*('(?:\\.|[^'\\])*')/g)) keys.push(decodeLiteral(entry[1]))
	}
	if (file === 'src/components/Tasks/TaskEditForm.vue') {
		for (const name of ['frequencyLabel', 'everyKey']) {
			const declaration = source.match(new RegExp(`const ${name} = ([\\s\\S]*?)(?=\\n\\s*const )`))
			if (!declaration) throw new Error(`Missing recurrence owner: ${name}`)
			for (const entry of declaration[1].matchAll(/[?:]\s*('(?:\\.|[^'\\])*')/g)) keys.push(decodeLiteral(entry[1]))
		}
	}
	if (file === 'lib/Service/TaskService.php') {
		const dates = source.match(/foreach\s*\(\[([^\]]+)\]\s+as\s+\$field\s*=>\s*\$message\)/)
		if (!dates) throw new Error('Missing task date-message owner')
		for (const entry of dates[1].matchAll(/=>\s*('(?:\\.|[^'\\])*')/g)) keys.push(decodeLiteral(entry[1], true))
	}
	return keys.filter(Boolean)
}

function collectSourceKeys(root = ROOT) {
	const keys = new Set()
	const walk = directory => {
		for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
			const full = path.join(directory, entry.name)
			if (entry.isDirectory()) walk(full)
			else if (/\.(js|vue|php)$/.test(entry.name)) {
				for (const key of extractKeysFromSource(path.relative(root, full), fs.readFileSync(full, 'utf8'))) keys.add(key)
			}
		}
	}
	for (const directory of ['src', 'lib']) walk(path.join(root, directory))
	const catalog = fs.readFileSync(path.join(root, 'lib/Titles/TitleCatalog.php'), 'utf8')
	const titles = [...catalog.matchAll(/'labelKey'\s*=>\s*('(?:\\.|[^'\\])*')/g)]
	if (!titles.length) throw new Error('Missing title catalog labels')
	for (const title of titles) keys.add(decodeLiteral(title[1], true))
	for (const [file, property, fields] of [
		['TaskTemplates.json', 'templates', ['name', 'description', 'instructions']],
		['RoomTypes.json', 'roomTypes', ['defaultName']],
	]) {
		for (const entry of JSON.parse(fs.readFileSync(path.join(root, 'lib/Templates', file), 'utf8'))[property]) {
			for (const field of fields) if (entry[field]) keys.add(entry[field])
		}
	}
	return keys
}

function extractPlaceholders(value) {
	if (typeof value !== 'string') return []
	return [...new Set(value.replace(/%%/g, '').match(/\{[a-zA-Z0-9_]+\}|%(?:\d+\$)?[-+0#]*(?:\d+)?(?:\.\d+)?[bcdeEfFgGosuxX]/g) ?? [])].sort()
}

function potKeys(content) {
	const keys = new Set()
	let current = null
	for (const line of content.split(/\r?\n/)) {
		if (line.startsWith('msgid ')) { if (current) keys.add(current); current = JSON.parse(line.slice(6)) }
		else if (line.startsWith('"') && current !== null) current += JSON.parse(line)
		else if (line.startsWith('msgstr')) { if (current) keys.add(current); current = null }
	}
	if (current) keys.add(current)
	return keys
}

function validate(keys, en, de, pot) {
	const errors = []
	const template = potKeys(pot)
	for (const key of keys) {
		for (const [locale, translations] of [['en', en], ['de', de]]) {
			if (typeof translations[key] !== 'string' || !translations[key].trim()) errors.push(`${locale}: missing ${key}`)
			else if (JSON.stringify(extractPlaceholders(key)) !== JSON.stringify(extractPlaceholders(translations[key]))) errors.push(`${locale}: placeholder mismatch ${key}`)
		}
		if (!template.has(key)) errors.push(`POT: missing ${key}`)
	}
	for (const [locale, entries] of [['en', Object.keys(en)], ['de', Object.keys(de)], ['POT', [...template]]]) {
		for (const key of entries) if (!keys.has(key)) errors.push(`${locale}: dead key ${key}`)
	}
	return errors
}

function regenerate(keys, enDocument, deDocument) {
	const ordered = [...keys].sort()
	const en = Object.fromEntries(ordered.map(key => [key, enDocument.translations[key] ?? key]))
	// Missing German copy remains blocking; never fill it with English.
	const de = Object.fromEntries(ordered.map(key => [key, deDocument.translations[key] ?? '']))
	const pot = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n'
		+ ordered.map(key => `msgid ${JSON.stringify(key)}\nmsgstr ""\n`).join('\n')
	return { en: JSON.stringify({ ...enDocument, translations: en }, null, 4) + '\n',
		de: JSON.stringify({ ...deDocument, translations: de }, null, 4) + '\n', pot }
}

function main(args = process.argv.slice(2)) {
	if (args.some(arg => arg !== '--write')) throw new Error('Unknown localization checker argument')
	const enPath = path.join(ROOT, 'l10n/en.json'), dePath = path.join(ROOT, 'l10n/de.json')
	const potPath = path.join(ROOT, 'l10n/templates/chorestrike.pot')
	const en = JSON.parse(fs.readFileSync(enPath, 'utf8')), de = JSON.parse(fs.readFileSync(dePath, 'utf8'))
	const keys = collectSourceKeys()
	if (args.includes('--write')) {
		const result = regenerate(keys, en, de)
		fs.writeFileSync(enPath, result.en)
		fs.writeFileSync(dePath, result.de)
		fs.writeFileSync(potPath, result.pot)
	}
	const errors = validate(keys, JSON.parse(fs.readFileSync(enPath, 'utf8')).translations,
		JSON.parse(fs.readFileSync(dePath, 'utf8')).translations, fs.existsSync(potPath) ? fs.readFileSync(potPath, 'utf8') : '')
	for (const error of errors) console.error(error)
	if (!errors.length) console.log(`l10n check passed: ${keys.size} source/catalog keys, POT/EN/DE exact coverage and placeholders`)
	return errors.length ? 1 : 0
}

module.exports = { decodeLiteral, extractKeysFromSource, collectSourceKeys, extractPlaceholders, potKeys, validate, regenerate, main }
if (require.main === module) {
	try { process.exitCode = main() } catch (error) { console.error(error.message); process.exitCode = 1 }
}
