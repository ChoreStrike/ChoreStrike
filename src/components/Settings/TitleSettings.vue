<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="title-settings cs-settings-form">
		<div class="setting-card title-card">
			<div class="title-display">
				<TitleBadge
					v-if="currentTitle"
					:title="currentTitle"
					:unlocked="titlesStore.unlockedTitles" />
				<span v-else class="no-title" data-testid="no-title">
					{{ t('chorestrike', 'No title yet — keep your rhythm going to earn one!') }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import TitleBadge from '../Profile/TitleBadge.vue'
import { useTitlesStore } from '../../store/titles.js'
import { useUserStore } from '../../store/user.js'
import { logger } from '../../utils/logger.js'

/**
 * The viewer's title (personal settings). Reads the titles when it opens.
 */
export default {
	name: 'TitleSettings',

	components: { TitleBadge },

	setup() {
		return { titlesStore: useTitlesStore(), userStore: useUserStore() }
	},

	computed: {
		currentTitle() {
			return this.titlesStore.currentTitle
		},
	},

	async mounted() {
		try {
			await this.userStore.initializeCurrentUser()
			if (this.userStore.userId) {
				await this.titlesStore.fetchTitles(this.userStore.userId)
			}
		} catch (error) {
			logger.error('Failed to load title', error, { component: 'TitleSettings', action: 'mounted' })
		}
	},

	methods: { t },
}
</script>

<style scoped>
.title-card {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.title-display {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.no-title {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}
</style>
