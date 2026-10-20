<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Component
		:is="iconComponent"
		:class="iconClasses"
		:size="computedSize"
		:stroke-width="strokeWidth"
		v-bind="$attrs" />
</template>

<script>
import { computed } from 'vue'
import { getIcon } from '../../utils/icons.js'
import { logger } from '../../utils/logger.js'

/**
 * Icon component for ChoreStrike
 *
 * Uses the local ChoreStrike icon registry with Microsoft Fluent SVG assets.
 *
 * @example
 * <Icon name="Home" size="24" />
 * <Icon name="Trash2" class="danger" />
 * <Icon name="CheckCircle" :size="32" stroke-width="2.5" />
 */
export default {
	name: 'CsIcon',

	inheritAttrs: false,

	props: {
		/**
		 * Icon name from the local icon registry
		 * @see src/utils/icons.js for available icons
		 */
		name: {
			type: String,
			required: true,
		},

		/**
		 * Icon size in pixels
		 */
		size: {
			type: [Number, String],
			default: 20,
		},

		/**
		 * Stroke width for the icon
		 */
		strokeWidth: {
			type: [Number, String],
			default: 1.5,
		},

		/**
		 * Additional CSS class
		 */
		className: {
			type: String,
			default: '',
		},
	},

	setup(props) {
		const iconComponent = computed(() => {
			const component = getIcon(props.name)
			if (!component) {
				logger.warn(`Icon "${props.name}" not found in icon registry`, { component: 'Icon', iconName: props.name })
				return null
			}
			return component
		})

		const computedSize = computed(() => {
			return typeof props.size === 'number' ? props.size : parseInt(props.size, 10)
		})

		const iconClasses = computed(() => {
			return ['icon', `icon--${props.name.toLowerCase()}`, props.className]
				.filter(Boolean)
				.join(' ')
		})

		return {
			iconComponent,
			computedSize,
			iconClasses,
		}
	},
}
</script>

<style scoped>
.icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
</style>
