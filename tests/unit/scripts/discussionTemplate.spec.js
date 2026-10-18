/** @jest-environment node */
const fs = require('node:fs')
const path = require('node:path')
const yaml = require('js-yaml')
const root = path.resolve(__dirname, '../../..')
const read = file => fs.readFileSync(path.join(root, file), 'utf8')

// GitHub reads discussion forms only from the default branch and only under
// the exact category slug, so a mismatch would otherwise show up after a push.
describe('GitHub Q&A discussion form', () => {
	const form = yaml.load(read('.github/DISCUSSION_TEMPLATE/q-a.yml'))
	const fields = form.body.filter(element => element.type !== 'markdown')

	it('is named after the category slug the issue chooser links to', () => {
		const { contact_links: links } = yaml.load(read('.github/ISSUE_TEMPLATE/config.yml'))
		const slugs = links.map(link => link.url.match(/\/discussions\/categories\/([\w-]+)$/)?.[1]).filter(Boolean)
		expect(slugs).toEqual(['q-a'])
		expect(fs.existsSync(path.join(root, `.github/DISCUSSION_TEMPLATE/${slugs[0]}.yml`))).toBe(true)
	})

	it('gives every field a unique id and label and requires the question', () => {
		expect(new Set(fields.map(field => field.id)).size).toBe(fields.length)
		expect(new Set(fields.map(field => field.attributes.label)).size).toBe(fields.length)
		expect(fields.find(field => field.id === 'question').validations.required).toBe(true)
	})

	it('requires the privacy and security confirmations', () => {
		const { options } = fields.find(field => field.type === 'checkboxes').attributes
		expect(options).toHaveLength(2)
		expect(options.every(option => option.required === true)).toBe(true)
	})

	it('links only to documents that exist on the default branch', () => {
		const text = form.body.find(element => element.type === 'markdown').attributes.value
		const documents = [...text.matchAll(/ChoreStrike\/blob\/master\/([^)\s]+)/g)].map(match => match[1])
		expect(documents).toEqual(['docs/SECURITY.md'])
		documents.forEach(document => expect(fs.existsSync(path.join(root, document))).toBe(true))
	})
})
