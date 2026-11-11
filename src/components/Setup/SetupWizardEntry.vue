<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="canSetUp || open" class="setup-entry">
		<NcButton
			v-if="canSetUp"
			variant="primary"
			data-testid="open-setup-wizard"
			@click="open = true">
			{{ t('chorestrike', 'Set up your home') }}
		</NcButton>
		<!-- Rendered at <body>: inside the app content's stacking context
		     Nextcloud's navigation toggle stayed above the wizard overlay
		     (CS-PLAN-087 RF-087-09). -->
		<Teleport to="body">
			<SetupWizard
				v-if="open"
				@applied="handleApplied"
				@skipped="open = false"
				@close="open = false" />
		</Teleport>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { computed, ref, watch } from 'vue'
import SetupWizard, { isWizardDismissedForHouse } from './SetupWizard.vue'
import { useHouseStore } from '../../store/house.js'
import { useRoomsStore } from '../../store/rooms.js'
import { useUserStore } from '../../store/user.js'
import { logger } from '../../utils/logger.js'

/**
 * The way into the setup wizard (CS-PLAN-090 WP-B, D-090-12): a button for
 * the owner of a house without floors, on Today and under *Floors & Rooms*
 * in Settings. A member who is not the owner cannot run the wizard, and a
 * house that has floors is set up.
 *
 * With `autoOpen` the wizard opens by itself once, unless "Start empty"
 * was chosen for this house before (UX-011); the button stays either way.
 */
export default {
	name: 'SetupWizardEntry',

	components: { NcButton, SetupWizard },

	props: {
		/** The caller has read the floors, so "no floors" is a fact. */
		ready: { type: Boolean, default: false },
		/** Open the wizard without a click for a house that was never set up. */
		autoOpen: { type: Boolean, default: false },
	},

	emits: ['applied'],

	setup(props, { emit }) {
		const houseStore = useHouseStore()
		const roomsStore = useRoomsStore()
		const userStore = useUserStore()

		const open = ref(false)
		const canSetUp = computed(() => props.ready && userStore.isHouseOwner && roomsStore.floors.length === 0)

		watch(canSetUp, async (possible) => {
			if (!possible || !props.autoOpen) {
				return
			}
			// The dismissal is stored per house, so the house has to be known.
			if (!houseStore.house) {
				await houseStore.fetchHouse().catch(() => {})
			}
			if (canSetUp.value && !isWizardDismissedForHouse(houseStore.house?.id ?? null)) {
				open.value = true
			}
		}, { immediate: true })

		/**
		 * The wizard created floors, rooms and tasks and set the house name
		 * in one transaction (CS-PLAN-062 §3.2): read both again, then let
		 * the caller refresh what it shows.
		 */
		async function handleApplied() {
			open.value = false
			const results = await Promise.allSettled([roomsStore.fetchFloorsWithRooms(), houseStore.fetchHouse()])
			results.filter((result) => result.status === 'rejected').forEach((result) => {
				logger.error('Reload after the setup wizard failed', result.reason, { component: 'SetupWizardEntry', action: 'handleApplied' })
			})
			emit('applied')
		}

		return { t, open, canSetUp, handleApplied }
	},
}
</script>

<style scoped>
.setup-entry {
	display: flex;
	justify-content: center;
	margin: 0.75rem 0;
}
</style>
