/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * Common Components Index
 *
 * This file exports all common/shared components for easy importing.
 *
 * @example
 * import { Icon, Emoji, Button, Card } from './components/Common'
 */

export { default as Icon } from './Icon.vue'
export { default as Emoji } from './Emoji.vue'
export { default as EmptyState } from './EmptyState.vue'
export { default as ErrorState } from './ErrorState.vue'

// Export icons registry for programmatic access
export { icons, getIcon, hasIcon } from '../../utils/icons.js'
