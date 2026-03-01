<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<img
		:src="emojiUrl"
		:alt="alt || name"
		:class="emojiClasses"
		:width="size"
		:height="size"
		v-bind="$attrs">
</template>

<script>
import { computed } from 'vue'

/**
 * Emoji mapping for Microsoft Fluent UI Emoji
 *
 * These are the recommended emojis for ChoreStrike categories.
 * Emojis are loaded from the Fluent UI Emoji GitHub repository.
 *
 * @see https://github.com/microsoft/fluentui-emoji
 */
const EMOJI_MAP = {
	// Rooms
	'living-room': 'couch_and_lamp',
	bedroom: 'bed',
	bathroom: 'bathtub',
	kitchen: 'cooking',
	balcony: 'potted_plant',
	office: 'laptop',
	garage: 'automobile',
	storage: 'package',
	dining: 'fork_and_knife_with_plate',

	// Tasks & Chores
	trash: 'wastebasket',
	cleaning: 'broom',
	sponge: 'sponge',
	bucket: 'bucket',
	soap: 'soap',
	laundry: 'basket',
	shopping: 'shopping_cart',
	dishes: 'fork_and_knife_with_plate',
	repair: 'hammer_and_wrench',

	// Gamification - Energy
	energy: 'high_voltage',
	battery: 'battery',
	power: 'electric_plug',

	// Gamification - Rewards
	star: 'star',
	sparks: 'sparkles',
	coin: 'coin',
	points: 'gem_stone',

	// Gamification - Streak
	fire: 'fire',
	streak: 'fire',

	// Gamification - Medals
	trophy: 'trophy',
	gold: '1st_place_medal',
	silver: '2nd_place_medal',
	bronze: '3rd_place_medal',
	crown: 'crown',

	// Monsters
	monster: 'alien_monster',
	bug: 'microbe',
	ghost: 'ghost',
	spider: 'spider_web',
	dust: 'dashing_away',
	dirt: 'pile_of_poo',

	// Avatars (Animals for kids/family-friendly)
	fox: 'fox',
	panda: 'panda',
	cat: 'cat_face',
	frog: 'frog',
	dog: 'dog_face',
	owl: 'owl',
	rabbit: 'rabbit_face',
	bear: 'bear',
	penguin: 'penguin',
	unicorn: 'unicorn',
	dragon: 'dragon_face',
	robot: 'robot',

	// Status
	done: 'check_mark_button',
	pending: 'white_circle',
	warning: 'warning',
	error: 'cross_mark',
	info: 'information',

	// Time
	morning: 'sunrise',
	afternoon: 'sun',
	evening: 'city_sunset',
	night: 'night_with_stars',
	clock: 'alarm_clock',

	// Weather (for seasonal tasks)
	sunny: 'sun',
	rainy: 'cloud_with_rain',
	snowy: 'snowflake',
	windy: 'leaf_fluttering_in_wind',
}

/**
 * Get emoji file name from map or return the name directly
 *
 * @param {string} name - The emoji name or key
 * @return {string} The emoji file name
 */
function getEmojiFileName(name) {
	return EMOJI_MAP[name] || name
}

/**
 * Emoji component for ChoreStrike
 *
 * Uses Microsoft Fluent UI Emojis (MIT licensed) for graphics.
 *
 * @example
 * <Emoji name="living-room" :size="64" />
 * <Emoji name="monster" alt="Chore Monster" />
 * <Emoji name="fox" :size="128" style="3d" />
 */
export default {
	name: 'Emoji',

	inheritAttrs: false,

	props: {
		/**
		 * Emoji name (key from EMOJI_MAP or direct Fluent UI name)
		 * @see EMOJI_MAP for available keys
		 */
		name: {
			type: String,
			required: true,
		},

		/**
		 * Emoji size in pixels
		 */
		size: {
			type: [Number, String],
			default: 64,
		},

		/**
		 * Emoji style variant
		 * Options: '3d' (default), 'color', 'flat', 'high-contrast'
		 */
		emojiStyle: {
			type: String,
			default: '3d',
			validator: (value) => ['3d', 'color', 'flat', 'high-contrast'].includes(value),
		},

		/**
		 * Alt text for accessibility
		 */
		alt: {
			type: String,
			default: '',
		},

		/**
		 * Additional CSS class
		 */
		className: {
			type: String,
			default: '',
		},

		/**
		 * Use local assets instead of CDN
		 */
		local: {
			type: Boolean,
			default: false,
		},
	},

	setup(props) {
		const emojiFileName = computed(() => getEmojiFileName(props.name))

		const emojiUrl = computed(() => {
			if (props.local) {
				// Use local assets from img/emojis/
				return `./img/emojis/${emojiFileName.value}.png`
			}
			// Use GitHub CDN (for development/quick setup)
			// Note: In production, download and use local assets
			const baseUrl = 'https://raw.githubusercontent.com/microsoft/fluentui-emoji/main/assets'
			return `${baseUrl}/${emojiFileName.value}/${props.emojiStyle}/${emojiFileName.value}_${props.emojiStyle}.png`
		})

		const computedSize = computed(() => {
			return typeof props.size === 'number' ? props.size : parseInt(props.size, 10)
		})

		const emojiClasses = computed(() => {
			return ['emoji', `emoji--${props.name}`, `emoji--${props.emojiStyle}`, props.className]
				.filter(Boolean)
				.join(' ')
		})

		return {
			emojiUrl,
			computedSize,
			emojiClasses,
		}
	},
}
</script>

<style scoped>
.emoji {
	display: inline-block;
	object-fit: contain;
	vertical-align: middle;
}

.emoji--3d {
	/* 3D style has natural shadows */
}

.emoji--color {
	/* Color style is flat with colors */
}

.emoji--flat {
	/* Flat style is monochrome-friendly */
}

.emoji--high-contrast {
	/* High contrast for accessibility */
}
</style>
