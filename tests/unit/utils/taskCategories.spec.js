/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { categoryName, categoryOptions, DEFAULT_CATEGORY_LIST, isBuiltInCategory, resolveCategory } from '../../../src/utils/taskCategories.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))

/** CS-PLAN-090 WP-I (D-090-65): names, options and the *Other* fallback. */
describe('taskCategories', () => {
	const household = [{ key: 'kitchen', label: 'Kitchen & dining' }, { key: 'cleaning', label: null }, { key: 'c0a1b2c3d', label: 'Garden' }, { key: 'other', label: null }]

	it('starts with the six keys the task form always offered', () => {
		expect(DEFAULT_CATEGORY_LIST.revision).toBe(0)
		expect(DEFAULT_CATEGORY_LIST.items.map(item => item.key)).toEqual(['cleaning', 'kitchen', 'bathroom', 'organizing', 'maintenance', 'other'])
		expect(isBuiltInCategory('maintenance')).toBe(true)
		expect(isBuiltInCategory('c0a1b2c3d')).toBe(false)
		expect(isBuiltInCategory('toString')).toBe(false)
	})

	it('names a built-in key by its translation until an owner renames it', () => {
		expect(categoryName({ key: 'cleaning', label: null })).toBe('t:Cleaning')
		expect(categoryName({ key: 'kitchen', label: 'Kitchen & dining' })).toBe('Kitchen & dining')
		expect(categoryName({ key: 'c0a1b2c3d', label: 'Garden' })).toBe('Garden')
	})

	it('reads a key the list no longer holds as Other', () => {
		expect(resolveCategory('kitchen', household)).toBe('kitchen')
		expect(resolveCategory('bathroom', household)).toBe('other')
		expect(resolveCategory(undefined, household)).toBe('other')
	})

	it('offers the list in its order', () => {
		expect(categoryOptions(household)).toEqual([
			{ value: 'kitchen', label: 'Kitchen & dining' },
			{ value: 'cleaning', label: 't:Cleaning' },
			{ value: 'c0a1b2c3d', label: 'Garden' },
			{ value: 'other', label: 't:Other' },
		])
	})
})
