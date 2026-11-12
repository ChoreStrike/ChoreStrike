<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="settings" data-testid="settings-view">
		<header class="settings-header">
			<h1 data-testid="settings-title">
				{{ t('chorestrike', 'Settings') }}
			</h1>
		</header>

		<!-- Two levels (FEAT-UX-054): personal or household on top, their
		     categories below. Only the owner has household settings. -->
		<SegmentedSwitch
			v-if="userStore.isHouseOwner"
			:modelValue="tab"
			:options="tabOptions"
			:label="t('chorestrike', 'Settings area')"
			testidPrefix="settings-tab"
			@update:modelValue="setTab" />

		<div class="settings-content">
			<template v-if="tab === 'personal'">
				<SettingsCategory id="title"
					:title="t('chorestrike', 'Your Title')"
					:open="isOpen('title')"
					testid="title-section"
					@toggle="toggle">
					<TitleSettings />
				</SettingsCategory>
				<!-- Delivery and feedback preferences of the viewer alone
				     (CS-PLAN-070 WP6 §3.3). -->
				<SettingsCategory id="notifications"
					:title="t('chorestrike', 'Notifications & feedback')"
					:open="isOpen('notifications')"
					testid="notifications-section"
					@toggle="toggle">
					<NotificationPreferences />
				</SettingsCategory>
				<SettingsCategory id="planning"
					:title="t('chorestrike', 'Planning')"
					:open="isOpen('planning')"
					testid="planning-section"
					@toggle="toggle">
					<PlanningSettings />
				</SettingsCategory>
				<!-- Always reachable, with either reward gate closed too. -->
				<router-link :to="{ name: 'reward-requests' }" class="settings-link" data-testid="settings-open-requests">
					<span>{{ t('chorestrike', 'My Requests') }}</span>
					<Icon name="ChevronRight" :size="18" />
				</router-link>
			</template>

			<template v-else>
				<SettingsCategory id="house"
					:title="t('chorestrike', 'House')"
					:open="isOpen('house')"
					testid="house-section"
					@toggle="toggle">
					<HouseSettings />
				</SettingsCategory>
				<SettingsCategory id="members"
					:title="t('chorestrike', 'Members')"
					:open="isOpen('members')"
					testid="members-section"
					@toggle="toggle">
					<div class="setting-card">
						<MemberList />
					</div>
				</SettingsCategory>
				<!-- The single surface that edits the house structure. -->
				<SettingsCategory id="structure"
					:title="t('chorestrike', 'Floors & Rooms')"
					:open="isOpen('structure')"
					testid="floors-section"
					@toggle="toggle">
					<StructureSettings />
				</SettingsCategory>
				<!-- Catalog administration works while browsing is hidden or
				     the gate is off (CS-PLAN-070 WP8 §3.1). -->
				<SettingsCategory id="rewards"
					:title="t('chorestrike', 'Rewards')"
					:open="isOpen('rewards')"
					testid="catalog-section"
					@toggle="toggle">
					<div class="setting-card">
						<RewardCatalogEditor />
					</div>
				</SettingsCategory>
				<SettingsCategory id="arcade"
					:title="t('chorestrike', 'Arcade')"
					:open="isOpen('arcade')"
					testid="arcade-gate-section"
					@toggle="toggle">
					<div class="setting-card">
						<ArcadeGateSetting />
					</div>
				</SettingsCategory>
				<SettingsCategory id="danger"
					danger
					:title="t('chorestrike', 'Danger Zone')"
					:open="isOpen('danger')"
					testid="danger-zone"
					@toggle="toggle">
					<DangerZoneSettings />
				</SettingsCategory>
			</template>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Common/Icon.vue'
import SegmentedSwitch from '../components/Common/SegmentedSwitch.vue'
import MemberList from '../components/Members/MemberList.vue'
import ArcadeGateSetting from '../components/Minigames/ArcadeGateSetting.vue'
import RewardCatalogEditor from '../components/Rewards/RewardCatalogEditor.vue'
import DangerZoneSettings from '../components/Settings/DangerZoneSettings.vue'
import HouseSettings from '../components/Settings/HouseSettings.vue'
import NotificationPreferences from '../components/Settings/NotificationPreferences.vue'
import PlanningSettings from '../components/Settings/PlanningSettings.vue'
import SettingsCategory from '../components/Settings/SettingsCategory.vue'
import StructureSettings from '../components/Settings/StructureSettings.vue'
import TitleSettings from '../components/Settings/TitleSettings.vue'
import { usePreferencesStore } from '../store/preferences.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'

// Categories per area; a `?category=` link opens one of them.
const CATEGORIES = {
	personal: ['title', 'notifications', 'planning'],
	household: ['house', 'members', 'structure', 'rewards', 'arcade', 'danger'],
}

/**
 * The area of a category (`personal` for an unknown one).
 * @param {unknown} category Raw `?category=` value.
 * @return {string}
 */
function tabOf(category) {
	return CATEGORIES.household.includes(category) ? 'household' : 'personal'
}

/**
 * Settings (FEAT-UX-054, CS-PLAN-090 WP-D): a switch *Personal | Household*
 * and collapsible categories that start closed, one component each. A
 * category reads its data when it opens, and the address names the one
 * opened last. Members and children have no household area, also by a link.
 */
export default {
	name: 'SettingsView',

	components: {
		Icon,
		SegmentedSwitch,
		SettingsCategory,
		TitleSettings,
		NotificationPreferences,
		PlanningSettings,
		HouseSettings,
		MemberList,
		StructureSettings,
		RewardCatalogEditor,
		ArcadeGateSetting,
		DangerZoneSettings,
	},

	setup() {
		return { userStore: useUserStore(), preferencesStore: usePreferencesStore(), route: useRoute(), router: useRouter() }
	},

	data() {
		return {
			requestedTab: 'personal',
			openCategories: [],
		}
	},

	computed: {
		tab() {
			return this.userStore.isHouseOwner ? this.requestedTab : 'personal'
		},

		tabOptions() {
			return [
				{ value: 'personal', label: t('chorestrike', 'Personal') },
				{ value: 'household', label: t('chorestrike', 'Household') },
			]
		},
	},

	watch: {
		'route.query.category': {
			immediate: true,
			handler(category) {
				this.openFromLink(category)
			},
		},
	},

	mounted() {
		// Keeps the store warm for the arcade and feedback consumers; the
		// store logs a failed read.
		this.preferencesStore.fetchPreferences().catch(() => {})
	},

	methods: {
		t,

		isOpen(id) {
			return this.openCategories.includes(id)
		},

		/**
		 * Open or close a category. The address names the category opened
		 * last, so a reload or a shared link comes back to it.
		 * @param {string} id Category key.
		 */
		toggle(id) {
			const opening = !this.isOpen(id)
			this.openCategories = opening
				? [...this.openCategories, id]
				: this.openCategories.filter((open) => open !== id)
			const query = { ...(this.route?.query ?? {}) }
			if (opening) {
				query.category = id
			} else if (query.category === id) {
				delete query.category
			} else {
				return
			}
			// The category is already shown; a failed mirror only loses the link.
			this.router?.replace({ query }).catch((error) => logger.warn('Could not mirror the settings category in the URL', { view: 'Settings', action: 'toggle', error: error?.message }))
		},

		setTab(tab) {
			this.requestedTab = tab
		},

		/**
		 * Show the area of a linked category and open it; an unknown value
		 * changes nothing.
		 * @param {unknown} category Raw `?category=` value.
		 */
		openFromLink(category) {
			if (![...CATEGORIES.personal, ...CATEGORIES.household].includes(category)) {
				return
			}
			this.requestedTab = tabOf(category)
			if (!this.isOpen(category)) {
				this.openCategories = [...this.openCategories, category]
			}
		},
	},
}
</script>

<style scoped>
.settings {
	padding: 1rem var(--cs-view-gutter) 2rem;
	max-width: 1000px;
	margin: 0 auto;
}

.settings-header {
	margin-bottom: 1.5rem;
	padding-inline-start: var(--cs-view-heading-inset);
}

.settings-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0;
}

.settings-content {
	border-block-start: 1px solid var(--color-border);
}

.setting-card {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1.5rem;
}

.settings-link {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
	min-height: 52px;
	padding: 0.25rem 0.5rem;
	border-block-end: 1px solid var(--color-border);
	color: var(--color-main-text);
	font-weight: 600;
	text-decoration: none;
}

.settings-link:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: -2px;
}
</style>
