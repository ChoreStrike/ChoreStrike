/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { onBeforeUnmount, watch } from 'vue'
import { usePreferencesStore } from '../store/preferences.js'
import { applyColorScheme, HIGH_CONTRAST_QUERY, SCHEME_ATTRIBUTE } from '../utils/colorSchemes.js'

/**
 * Keep the body's scheme attribute in step with the viewer's colour scheme
 * (CS-PLAN-090 WP-K). Called once from the app root: it applies the scheme
 * before the first render, again after every change (a save here, or a
 * preference read that brings another device's choice), and when the
 * system's contrast preference changes. Unmounting removes the attribute.
 */
export function useColorScheme() {
	const store = usePreferencesStore()
	// Bound: an unbound `window.matchMedia` throws "Illegal invocation".
	const matchMedia = typeof window.matchMedia === 'function' ? (query) => window.matchMedia(query) : undefined
	const contrastQuery = matchMedia?.(HIGH_CONTRAST_QUERY) ?? null
	const apply = () => applyColorScheme(store.colorScheme, document.body, matchMedia)

	watch(() => store.colorScheme, apply, { immediate: true })
	contrastQuery?.addEventListener?.('change', apply)

	onBeforeUnmount(() => {
		contrastQuery?.removeEventListener?.('change', apply)
		document.body.removeAttribute(SCHEME_ATTRIBUTE)
	})
}
