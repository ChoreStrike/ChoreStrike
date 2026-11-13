/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The household's task categories (CS-PLAN-090 WP-I, D-090-65). The server
 * owns the list; a built-in key without a label keeps its translated name,
 * and a key the list no longer holds reads as *Other*.
 */
export const OTHER_CATEGORY = 'other'

const BUILT_IN_NAMES = {
	cleaning: () => t('chorestrike', 'Cleaning'),
	kitchen: () => t('chorestrike', 'Kitchen'),
	bathroom: () => t('chorestrike', 'Bathroom'),
	organizing: () => t('chorestrike', 'Organizing'),
	maintenance: () => t('chorestrike', 'Maintenance'),
	other: () => t('chorestrike', 'Other'),
}

/** The list an instance reads before an owner saved one. */
export const DEFAULT_CATEGORY_LIST = Object.freeze({
	revision: 0,
	items: Object.freeze(Object.keys(BUILT_IN_NAMES).map((key) => Object.freeze({ key, label: null }))),
})

/**
 * Whether a key is one of the six built-in categories.
 * @param {string} key Category key.
 * @return {boolean}
 */
export function isBuiltInCategory(key) {
	return Object.hasOwn(BUILT_IN_NAMES, key)
}

/**
 * Display name of one list item.
 * @param {{key: string, label: ?string}} item List item.
 * @return {string}
 */
export function categoryName(item) {
	return item.label ?? BUILT_IN_NAMES[item.key]?.() ?? item.key
}

/**
 * The key a task's stored category stands for: itself while listed, else *Other*.
 * @param {?string} key Stored category key.
 * @param {Array<{key: string}>} items Current list.
 * @return {string}
 */
export function resolveCategory(key, items) {
	return items.some((item) => item.key === key) ? key : OTHER_CATEGORY
}

/**
 * Select options in list order.
 * @param {Array<{key: string, label: ?string}>} items Current list.
 * @return {Array<{value: string, label: string}>}
 */
export function categoryOptions(items) {
	return items.map((item) => ({ value: item.key, label: categoryName(item) }))
}
