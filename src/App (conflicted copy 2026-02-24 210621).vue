<template>
	<NcContent app-name="chorestrike">
		<NcAppNavigation>
			<NcAppNavigationItem
				:to="{ name: 'dashboard' }"
				icon="icon-home"
				data-testid="nav-dashboard"
				:name="t('chorestrike', 'Dashboard')" />
			<NcAppNavigationItem
				:to="{ name: 'tasks' }"
				icon="icon-checkmark"
				data-testid="nav-tasks"
				:name="t('chorestrike', 'Tasks')" />
			<NcAppNavigationItem
				:to="{ name: 'leaderboard' }"
				icon="icon-star"
				data-testid="nav-leaderboard"
				:name="t('chorestrike', 'Leaderboard')" />
			<NcAppNavigationItem
				:to="{ name: 'settings' }"
				icon="icon-settings"
				data-testid="nav-settings"
				:name="t('chorestrike', 'Settings')" />
		</NcAppNavigation>

		<NcAppContent>
			<Suspense>
				<template #default>
					<router-view v-slot="{ Component, route }">
						<Transition name="fade" mode="out-in">
							<component :is="Component" :key="route.path" />
						</Transition>
					</router-view>
				</template>
				<template #fallback>
					<div class="loading-overlay">
						<span class="loading-spinner" />
						<p>{{ t('chorestrike', 'Loading...') }}</p>
					</div>
				</template>
			</Suspense>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { defineAsyncComponent, Suspense, Transition } from 'vue'
import { translate as t } from '@nextcloud/l10n'

// Lazy load Nextcloud Vue components for better initial load performance
const NcContent = defineAsyncComponent(() =>
	import('@nextcloud/vue/dist/Components/NcContent.js'))
const NcAppNavigation = defineAsyncComponent(() =>
	import('@nextcloud/vue/dist/Components/NcAppNavigation.js'))
const NcAppNavigationItem = defineAsyncComponent(() =>
	import('@nextcloud/vue/dist/Components/NcAppNavigationItem.js'))
const NcAppContent = defineAsyncComponent(() =>
	import('@nextcloud/vue/dist/Components/NcAppContent.js'))

export default {
	name: 'App',

	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		Suspense,
		Transition,
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
/* App-wide styles */

.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
	opacity: 0;
}

.loading-overlay {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	min-height: 300px;
	gap: 1rem;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border);
	border-top-color: var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
