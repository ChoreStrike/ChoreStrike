/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Tier index → CSS tier class + design token. Mirrors the task-tier palette
 * (bronze/silver/gold) from src/styles/tokens.css so a title badge matches
 * the medal/tier visual language.
 */
export const TIER_STYLES = [
	{ class: 'tier-bronze', color: 'var(--cs-tier-bronze)' },
	{ class: 'tier-silver', color: 'var(--cs-tier-silver)' },
	{ class: 'tier-gold', color: 'var(--cs-tier-gold)' },
]

/**
 * Map a title descriptor (from the API) to its display label via the l10n
 * helper. Returns the raw labelKey when translation is unavailable so the UI
 * never shows an empty badge.
 *
 * @param {(app: string, message: string) => string} t Nextcloud translate helper
 * @param {{labelKey?: string}|null} title Title descriptor
 * @return {string|null}
 */
export function titleLabel(t, title) {
	if (!title || !title.labelKey) {
		return null
	}
	return t('chorestrike', title.labelKey)
}
