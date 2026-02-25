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

// Export icons registry for programmatic access
export { icons, getIcon, hasIcon } from '../../utils/icons.js'
