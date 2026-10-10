/** Collect complete license payloads for modules actually present in Webpack stats. */
'use strict'

const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')

const STANDARD_SOURCES = {
	'AGPL-3.0-or-later': ['@nextcloud/dialogs', 'LICENSE'],
	'GPL-3.0-or-later': ['@nextcloud/router', 'LICENSE'],
	'Apache-2.0': ['dompurify', 'LICENSE'],
}

function hasCompleteLicense(texts, license) {
	const joined = texts.map(text => text.data.toString('utf8')).join('\n')
	const checks = [
		['AGPL-3.0', /GNU AFFERO GENERAL PUBLIC LICENSE/, 30000],
		['GPL-3.0', /GNU GENERAL PUBLIC LICENSE/, 30000],
		['Apache-2.0', /Apache License/, 9000],
		['MPL-2.0', /Mozilla Public License/, 15000],
		['MIT', /Permission is hereby granted, free of charge/, 800],
		['BSD', /Redistribution and use in source and binary forms/, 1200],
		['ISC', /Permission to use, copy, modify, and\/or distribute/, 500],
	]
	let checked = false
	for (const [identifier, header, minimum] of checks) {
		if (!new RegExp(`(^|[^A-Za-z])${identifier}`).test(String(license))) continue
		checked = true
		if (!header.test(joined) || joined.length < minimum) return false
	}
	return checked || texts.some(text => text.data.length > 800 && !/^\s*SPDX-License-Identifier:[^\n]+\s*$/.test(text.data.toString()))
}

function collectPackageLicenses(stats, root) {
	const overrides = JSON.parse(fs.readFileSync(path.join(__dirname, '../docker/package-license-overrides.json'), 'utf8'))
	const packages = new Set()
	const visit = modules => {
		for (const module of modules ?? []) {
			const source = String(module.nameForCondition ?? module.name ?? '').replaceAll('\\', '/')
			const start = source.indexOf('node_modules/')
			const last = source.lastIndexOf('node_modules/')
			if (start >= 0) {
				const parts = source.slice(last + 13).split('/')
				const name = parts[0].startsWith('@') ? parts.slice(0, 2).join('/') : parts[0]
				packages.add(source.slice(start, last + 13) + name)
			}
			visit(module.modules)
		}
	}
	visit(stats.modules)
	const files = []
	const inventory = []
	const missing = []
	for (const relative of [...packages].sort()) {
		const directory = path.resolve(root, relative)
		if (!directory.startsWith(path.resolve(root, 'node_modules') + path.sep)) throw new Error('Unsafe dependency license path')
		const metadata = JSON.parse(fs.readFileSync(path.join(directory, 'package.json'), 'utf8'))
		if (!/^(?:@[a-z0-9._-]+\/)?[a-z0-9._-]+$/i.test(metadata.name) || !/^[a-z0-9.+-]+$/i.test(metadata.version)) throw new Error('Unsafe dependency license identity')
		const names = fs.readdirSync(directory).filter(name => /^(?:licen[cs]e|copying|notice)(?:[._-].*)?$/i.test(name) && fs.lstatSync(path.join(directory, name)).isFile()).sort()
		const texts = names.map(name => ({ name, data: fs.readFileSync(path.join(directory, name)) }))
		let fallback = null
		const override = overrides[`${metadata.name}@${metadata.version}`]
		if (!hasCompleteLicense(texts, metadata.license) && override) {
			fallback = override.source
			texts.push({ name: 'LICENSE-upstream', data: fs.readFileSync(path.join(__dirname, '../docker/package-license-texts', override.file)) })
		}
		if (!hasCompleteLicense(texts, metadata.license) && STANDARD_SOURCES[metadata.license]) {
			const [dependency, name] = STANDARD_SOURCES[metadata.license]
			fallback = `node_modules/${dependency}/${name}`
			texts.push({ name: 'LICENSE-standard', data: fs.readFileSync(path.join(root, fallback)) })
		}
		if (!hasCompleteLicense(texts, metadata.license)) {
			missing.push(`${metadata.name}@${metadata.version} (${metadata.license})`)
			continue
		}
		const destination = `licenses/npm/${metadata.name}/${metadata.version}`
		for (const text of texts) files.push({ name: `${destination}/${text.name}`, data: text.data })
		files.push({ name: `${destination}/package.json`, data: Buffer.from(JSON.stringify({ name: metadata.name, version: metadata.version, license: metadata.license, author: metadata.author ?? null, contributors: metadata.contributors ?? [], repository: metadata.repository ?? null }, null, 2) + '\n') })
		inventory.push({ name: metadata.name, version: metadata.version, license: metadata.license, fallback, texts: texts.map(text => ({ path: `${destination}/${text.name}`, sha256: crypto.createHash('sha256').update(text.data).digest('hex') })) })
	}
	if (missing.length) throw new Error(`Missing complete dependency license: ${missing.join(', ')}`)
	const unique = new Map()
	for (const file of files) {
		if (unique.has(file.name) && !unique.get(file.name).data.equals(file.data)) throw new Error('Conflicting dependency license payloads')
		unique.set(file.name, file)
	}
	const records = [...new Map(inventory.map(record => [`${record.name}@${record.version}`, record])).values()]
	unique.set('licenses/npm/inventory.json', { name: 'licenses/npm/inventory.json', data: Buffer.from(JSON.stringify(records, null, 2) + '\n') })
	return { files: [...unique.values()], inventory: records }
}

if (require.main === module) {
	const root = process.cwd()
	const { files } = collectPackageLicenses(JSON.parse(fs.readFileSync(process.argv[2], 'utf8')), root)
	for (const file of files) {
		fs.mkdirSync(path.dirname(file.name), { recursive: true })
		fs.writeFileSync(file.name, file.data)
	}
}

module.exports = { collectPackageLicenses, hasCompleteLicense }
