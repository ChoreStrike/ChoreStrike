<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="appearance-settings" data-testid="appearance-settings">
		<fieldset class="scheme-picker">
			<legend class="scheme-hint">
				{{ t('chorestrike', 'Pick the accent colour of the app. It applies on all your devices; backgrounds and text follow your Nextcloud theme.') }}
			</legend>
			<div class="scheme-grid">
				<label v-for="scheme in schemes"
					:key="scheme.key"
					class="scheme-option"
					:class="{ selected: scheme.key === preferencesStore.colorScheme }"
					:data-testid="`color-scheme-${scheme.key}`">
					<input type="radio"
						class="cs-visually-hidden"
						name="color-scheme"
						:value="scheme.key"
						:checked="scheme.key === preferencesStore.colorScheme"
						@change="choose(scheme.key)">
					<span class="scheme-swatch" :data-cs-swatch="scheme.key" aria-hidden="true">
						<Icon v-if="scheme.key === preferencesStore.colorScheme" name="Check" :size="20" />
					</span>
					<span class="scheme-name">{{ scheme.label }}</span>
				</label>
			</div>
		</fieldset>
		<p v-if="highContrast" class="scheme-note" data-testid="color-scheme-high-contrast">
			{{ t('chorestrike', 'Your Nextcloud theme uses high contrast, so the app keeps the standard colours.') }}
		</p>
		<p v-if="error"
			class="scheme-error"
			role="alert"
			data-testid="color-scheme-error">
			{{ t('chorestrike', 'The colour scheme could not be saved. Please try again.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import { usePreferencesStore } from '../../store/preferences.js'
import { isHighContrast } from '../../utils/colorSchemes.js'

/**
 * Personal settings → Appearance (CS-PLAN-090 WP-K, FEAT-UX-057): one of
 * six colour schemes, kept across devices. A choice recolours the app at
 * once; a failed save restores the previous scheme and says so here. Each
 * swatch shows its scheme's accent in the current theme (`data-cs-swatch`
 * in `schemes.css`).
 */
export default {
	name: 'AppearanceSettings',

	components: { Icon },

	setup() {
		return { preferencesStore: usePreferencesStore() }
	},

	data() {
		return {
			error: false,
			highContrast: typeof window.matchMedia === 'function'
				? isHighContrast(document.body, (query) => window.matchMedia(query))
				: isHighContrast(document.body, undefined),
		}
	},

	computed: {
		schemes() {
			// Literal keys for the translation catalog; the order of the server's list.
			return [
				{ key: 'standard', label: t('chorestrike', 'Standard') },
				{ key: 'ocean', label: t('chorestrike', 'Ocean') },
				{ key: 'forest', label: t('chorestrike', 'Forest') },
				{ key: 'sunset', label: t('chorestrike', 'Sunset') },
				{ key: 'berry', label: t('chorestrike', 'Berry') },
				{ key: 'slate', label: t('chorestrike', 'Slate') },
			]
		},
	},

	methods: {
		t,

		/**
		 * Save a scheme; the store already logged a failure.
		 * @param {string} scheme Scheme key.
		 */
		async choose(scheme) {
			this.error = false
			try {
				await this.preferencesStore.saveColorScheme(scheme)
			} catch {
				this.error = true
			}
		},
	},
}
</script>

<style scoped>
.appearance-settings {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1.5rem;
}

.scheme-picker {
	margin: 0;
	padding: 0;
	border: none;
}

.scheme-hint {
	margin-bottom: 1rem;
	padding: 0;
	color: var(--color-main-text);
}

.scheme-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(6.5rem, 1fr));
	gap: 0.75rem;
}

.scheme-option {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	min-height: 44px;
	padding: 0.75rem 0.5rem;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--cs-radius-md);
	background: var(--color-main-background);
	cursor: pointer;
}

.scheme-option.selected {
	border-color: var(--color-main-text);
}

.scheme-option:focus-within {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.scheme-swatch {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 2.5rem;
	height: 2.5rem;
	border-radius: 50%;
	background: var(--cs-scheme-element);
	color: var(--cs-scheme-element-text);
}

.scheme-swatch[data-cs-swatch="standard"] {
	background: var(--cs-instance-element);
	color: var(--cs-instance-element-text);
}

.scheme-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.scheme-note {
	margin: 1rem 0 0;
	color: var(--color-main-text);
}

.scheme-error {
	margin: 1rem 0 0;
	color: var(--color-error-text);
	font-weight: 600;
}
</style>
