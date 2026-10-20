<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="show"
		ref="overlay"
		class="wizard-overlay"
		role="dialog"
		aria-modal="true"
		:aria-label="t('chorestrike', 'Welcome to ChoreStrike!')"
		data-testid="setup-wizard"
		@keydown="onOverlayKeydown">
		<div ref="container" class="wizard-container" tabindex="-1">
			<header class="wizard-header">
				<h2>{{ t('chorestrike', 'Welcome to ChoreStrike!') }}</h2>
				<p class="wizard-tagline">
					{{ t('chorestrike', "Let's set up your home in about 5 minutes.") }}
				</p>
				<button class="wizard-skip" data-testid="wizard-skip" @click="skip">
					{{ t('chorestrike', 'Start empty') }}
				</button>
			</header>

			<ol class="wizard-steps">
				<li :class="{ active: step === 1 }" :aria-current="step === 1 ? 'step' : null">
					{{ t('chorestrike', 'Quick Start') }}
				</li>
				<li :class="{ active: step === 2 }" :aria-current="step === 2 ? 'step' : null">
					{{ t('chorestrike', 'Room List') }}
				</li>
				<li :class="{ active: step === 3 }" :aria-current="step === 3 ? 'step' : null">
					{{ t('chorestrike', 'Preview') }}
				</li>
				<li :class="{ active: step === 4 }" :aria-current="step === 4 ? 'step' : null">
					{{ t('chorestrike', 'Invite Family') }}
				</li>
			</ol>

			<!-- Step 1: Quick Start -->
			<section v-if="step === 1" class="wizard-step" data-testid="wizard-step-quickstart">
				<label class="field">
					<span>{{ t('chorestrike', 'House name') }}</span>
					<input
						v-model.trim="houseName"
						type="text"
						data-testid="wizard-house-name"
						:placeholder="t('chorestrike', 'e.g., Family Home')">
				</label>

				<label class="field">
					<span>{{ t('chorestrike', 'Home type') }}</span>
					<select v-model="quick.homeType" data-testid="wizard-home-type">
						<option value="house">{{ t('chorestrike', 'House') }}</option>
						<option value="apartment">{{ t('chorestrike', 'Apartment') }}</option>
					</select>
				</label>

				<label class="field">
					<span>{{ t('chorestrike', 'Number of floors') }} (1–{{ maxAboveGroundFloors }})</span>
					<input v-model.number="quick.floorCount"
						type="number"
						min="1"
						:max="maxAboveGroundFloors"
						data-testid="wizard-floor-count">
				</label>
				<p v-if="quick.basement && quick.homeType !== 'apartment'"
					class="invite-hint"
					data-testid="wizard-basement-hint">
					{{ t('chorestrike', 'With a basement, the above-ground floors are limited to five. The basement counts toward the six-floor total.') }}
				</p>

				<label v-if="quick.homeType !== 'apartment'" class="field checkbox">
					<input v-model="quick.basement" type="checkbox" data-testid="wizard-basement">
					<span>{{ t('chorestrike', 'Has basement') }}</span>
				</label>

				<label class="field">
					<span>{{ t('chorestrike', 'Rooms per floor') }} (1–6)</span>
					<input v-model.number="quick.roomsPerFloor"
						type="number"
						min="1"
						max="6"
						data-testid="wizard-rooms-per-floor">
				</label>

				<div class="field">
					<span>{{ t('chorestrike', 'Cleaning level') }}</span>
					<div class="level-cards">
						<button
							v-for="lvl in setupStore.levels"
							:key="lvl.id"
							type="button"
							class="level-card"
							:class="{ selected: quick.intensityLevel === lvl.id }"
							:data-testid="'wizard-level-' + lvl.id"
							@click="quick.intensityLevel = lvl.id">
							<strong>{{ lvl.label }}</strong>
							<small>{{ lvl.description }}</small>
						</button>
					</div>
				</div>
			</section>

			<!-- Step 2: Room List -->
			<section v-else-if="step === 2" class="wizard-step" data-testid="wizard-step-rooms">
				<div v-for="(floor, fi) in floors" :key="fi" class="wizard-floor">
					<h3>{{ floor.name }}</h3>
					<div v-for="(room, ri) in floor.rooms" :key="ri" class="wizard-room-row">
						<input
							v-model="room.name"
							type="text"
							class="room-name-input"
							:data-testid="`wizard-room-name-${fi}-${ri}`">
						<select v-model="room.roomType" :data-testid="`wizard-room-type-${fi}-${ri}`" @change="onRoomTypeChange(room)">
							<option v-for="rt in setupStore.roomTypes" :key="rt.id" :value="rt.id">
								{{ rt.defaultName }}
							</option>
						</select>
						<!-- UX-011: pointless while the house has a single member -->
						<select
							v-if="people.length > 1"
							v-model="room.assigneeUserId"
							:data-testid="`wizard-room-person-${fi}-${ri}`">
							<option value="">
								{{ t('chorestrike', 'For everyone') }}
							</option>
							<option v-for="u in people" :key="u.nextcloudUserId" :value="u.nextcloudUserId">
								{{ u.displayName }}
							</option>
						</select>
						<button type="button"
							class="room-remove"
							:aria-label="t('chorestrike', 'Remove room')"
							:data-testid="`wizard-room-remove-${fi}-${ri}`"
							@click="removeRoom(fi, ri)">
							×
						</button>
					</div>
					<button type="button"
						class="room-add"
						:data-testid="`wizard-room-add-${fi}`"
						@click="addRoom(fi)">
						{{ t('chorestrike', 'Add room') }}
					</button>
				</div>

				<label class="field checkbox">
					<input v-model="options.createHouseholdRoom" type="checkbox" data-testid="wizard-household">
					<span>{{ t('chorestrike', 'Household & Co.') }}</span>
				</label>
				<label class="field checkbox">
					<input v-model="options.createHallways" type="checkbox" data-testid="wizard-hallways">
					<span>{{ t('chorestrike', 'Hallway & Stairs') }}</span>
				</label>
			</section>

			<!-- Step 3: Preview (or the recoverable raced-409 state, CS-PLAN-062 §3.5) -->
			<section v-else-if="step === 3" class="wizard-step" data-testid="wizard-step-preview">
				<div v-if="alreadySetUp" class="already-set-up" data-testid="wizard-already-set-up">
					<p>{{ t('chorestrike', 'This house has already been set up.') }}</p>
					<button type="button"
						class="primary-button"
						data-testid="wizard-continue"
						@click="finish">
						{{ t('chorestrike', 'Continue to the app') }}
					</button>
				</div>
				<p v-else-if="setupStore.applying">
					{{ t('chorestrike', 'Setting up your home…') }}
				</p>
				<template v-else>
					<div class="preview-row">
						<strong>{{ floors.length }}</strong> {{ t('chorestrike', 'floors') }}
					</div>
					<div class="preview-row">
						<strong>{{ totalRooms }}</strong> {{ t('chorestrike', 'rooms') }}
					</div>
					<div class="preview-row">
						<strong>{{ selectedLevelLabel }}</strong>
					</div>
					<div v-for="(floor, fi) in floors" :key="fi" class="preview-floor">
						<h4>{{ floor.name }}</h4>
						<div v-for="(room, ri) in floor.rooms" :key="ri" class="preview-room">
							<span>{{ room.name }}</span>
							<span class="task-chip" :data-testid="`wizard-room-task-count-${fi}-${ri}`">
								{{ t('chorestrike', '≈ {n} tasks', { n: taskCountForRoom(room) }) }}
							</span>
						</div>
						<div v-if="options.createHallways" class="preview-room">
							<span>{{ t('chorestrike', 'Hallway & Stairs') }}</span>
							<span class="task-chip" :data-testid="`wizard-room-task-count-${fi}-hallway`">
								{{ t('chorestrike', '≈ {n} tasks', { n: taskCountForRoomType('hallway') }) }}
							</span>
						</div>
						<div v-if="options.createHouseholdRoom && fi === householdFloorIndex" class="preview-room">
							<span>{{ t('chorestrike', 'Household & Co.') }}</span>
							<span class="task-chip" data-testid="wizard-room-task-count-household">
								{{ t('chorestrike', '≈ {n} tasks', { n: taskCountForRoomType('household') }) }}
							</span>
						</div>
					</div>
					<div class="preview-row">
						<strong data-testid="wizard-preview-task-count">
							{{ t('chorestrike', '≈ {n} tasks will be created', { n: totalTaskCount }) }}
						</strong>
					</div>
				</template>
			</section>

			<!-- Step 4: Invite Family (optional, after apply) -->
			<section v-else-if="step === 4" class="wizard-step" data-testid="wizard-step-invite">
				<!-- CS-PLAN-062 §3.5: report the structural outcome from the apply
				     response only — what exists now, what was skipped. -->
				<div class="apply-result" data-testid="wizard-apply-result">
					<p class="apply-result-title">
						{{ t('chorestrike', 'Your home is set up!') }}
					</p>
					<p data-testid="wizard-result-house-name">
						{{ t('chorestrike', 'House name: {name}', { name: applyResult?.houseName ?? '' }) }}
					</p>
					<p data-testid="wizard-result-counts">
						{{ t('chorestrike', '{floors} floors, {rooms} rooms and {tasks} tasks were created', {
							floors: applyResult?.floors ?? 0,
							rooms: applyResult?.rooms ?? 0,
							tasks: applyResult?.tasks ?? 0,
						}) }}
					</p>
					<p v-if="skippedTemplateLabels.length > 0"
						class="apply-result-skipped"
						data-testid="wizard-result-skipped">
						{{ t('chorestrike', 'Skipped at this level: {templates}', { templates: skippedTemplateLabels.join(', ') }) }}
					</p>
				</div>
				<p class="invite-hint">
					{{ t('chorestrike', 'Each person needs an existing Nextcloud account with this email address.') }}
				</p>
				<!-- Essential onboarding slice (WP8 §3.6): accounts and roles
			     on a shared device. -->
				<HelpEntry variant="context" topic="setup" />

				<div v-for="(invite, index) in invites" :key="index" class="invite-row">
					<input
						v-model.trim="invite.email"
						type="email"
						class="invite-email-input"
						:placeholder="t('chorestrike', 'Email address')"
						:aria-label="t('chorestrike', 'Email address')"
						:disabled="invite.status === 'joined'"
						:data-testid="`wizard-invite-email-${index}`">
					<button v-if="invites.length > 1 && invite.status !== 'joined'"
						type="button"
						class="invite-remove"
						:aria-label="t('chorestrike', 'Remove email')"
						:data-testid="`wizard-invite-remove-${index}`"
						@click="removeInviteRow(index)">
						×
					</button>
					<!-- CS-PLAN-062 §3.4: per-recipient outcome replaces the
					     count-only toast feedback. -->
					<span v-if="invite.status"
						class="invite-status"
						:class="invite.status"
						:data-testid="`wizard-invite-status-${index}`">
						<template v-if="invite.status === 'joined'">
							{{ t('chorestrike', '{name} joined the house', { name: invite.statusName }) }}
						</template>
						<template v-else>
							{{ invite.statusReason || t('chorestrike', 'Invite failed') }}
						</template>
					</span>
				</div>
				<button type="button"
					class="invite-add"
					data-testid="wizard-invite-add"
					@click="addInviteRow">
					{{ t('chorestrike', 'Add another email') }}
				</button>
			</section>

			<p v-if="setupStore.error" class="wizard-error" data-testid="wizard-error">
				{{ setupStore.error }}
			</p>

			<footer class="wizard-footer">
				<button v-if="step > 1 && step < 4 && !alreadySetUp"
					class="secondary-button"
					data-testid="wizard-back"
					@click="step--">
					{{ t('chorestrike', 'Back') }}
				</button>
				<button v-if="step < 3"
					class="primary-button"
					data-testid="wizard-next"
					@click="next">
					{{ t('chorestrike', 'Next') }}
				</button>
				<button v-else-if="step === 3 && !alreadySetUp"
					class="primary-button"
					data-testid="wizard-apply"
					:disabled="setupStore.applying"
					@click="apply">
					{{ t('chorestrike', "Let's go!") }}
				</button>
				<template v-else-if="step === 4">
					<button
						class="secondary-button"
						data-testid="wizard-finish"
						@click="finish">
						{{ hasPendingInvites ? t('chorestrike', 'Skip and finish') : t('chorestrike', 'Finish') }}
					</button>
					<button
						class="primary-button"
						data-testid="wizard-send-invites"
						:disabled="sendingInvites || !hasValidInvites"
						@click="sendInvites">
						{{ t('chorestrike', 'Send invites') }}
					</button>
				</template>
			</footer>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useSetupStore } from '../../store/setup.js'
import { useUserStore } from '../../store/user.js'
import { useHouseStore } from '../../store/house.js'
import { iconForRoomType } from '../../utils/roomIcons.js'
import HelpEntry from '../Help/HelpEntry.vue'
import { logger } from '../../utils/logger.js'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * localStorage flag namespace for the persistent "start empty" dismissal
 * (CS-PLAN-042, UX-011) — per house so a reset/second house re-opens the
 * wizard. Also read by Dashboard to suppress the auto-open.
 *
 * @param {?number} houseId The current house ID
 * @return {string} The storage key
 */
export const wizardDismissedKey = (houseId) => `chorestrike:wizard-dismissed:${houseId ?? 'unknown'}`

/**
 * Whether "Start empty" was dismissed for this house in any previous
 * session.
 * @param {?number} houseId The current house ID
 * @return {boolean}
 */
export const isWizardDismissedForHouse = (houseId) => {
	try {
		return window.localStorage.getItem(wizardDismissedKey(houseId)) === '1'
	} catch (error) {
		return false
	}
}

export default {
	name: 'SetupWizard',

	components: {
		HelpEntry,
	},

	props: {
		show: { type: Boolean, default: true },
	},

	emits: ['close', 'applied', 'skipped'],

	setup() {
		const setupStore = useSetupStore()
		const userStore = useUserStore()
		const houseStore = useHouseStore()
		return { setupStore, userStore, houseStore }
	},

	data() {
		return {
			step: 1,
			houseName: '',
			quick: {
				homeType: 'house',
				floorCount: 2,
				basement: false,
				roomsPerFloor: 3,
				intensityLevel: 'putzteufel',
			},
			options: {
				createHouseholdRoom: true,
				createHallways: true,
			},
			floors: [],
			// Each row carries its per-recipient invite outcome
			// (CS-PLAN-062 §3.4): status null | 'joined' | 'failed'.
			invites: [{ email: '', status: null, statusName: '', statusReason: '' }],
			sendingInvites: false,
			// Recoverable raced-409 state (CS-PLAN-062 §3.5): another tab
			// applied first — render "already set up — continue" instead of a
			// generic error.
			alreadySetUp: false,
			applyResult: null,
		}
	},

	computed: {
		people() {
			return this.userStore.houseUsers
		},
		hasValidInvites() {
			return this.invites.some((invite) => EMAIL_PATTERN.test(invite.email))
		},
		/**
		 * Whether at least one valid email row has not joined yet — the
		 * "Send invites" batch still has work (CS-PLAN-062 §3.4).
		 */
		hasPendingInvites() {
			return this.invites.some((invite) => EMAIL_PATTERN.test(invite.email) && invite.status !== 'joined')
		},
		totalRooms() {
			return this.floors.reduce((sum, f) => sum + f.rooms.length, 0)
		},
		selectedLevelLabel() {
			const lvl = this.setupStore.levels.find((l) => l.id === this.quick.intensityLevel)
			return lvl ? lvl.label : this.quick.intensityLevel
		},
		/**
		 * Catalog labels for the templates the apply skipped at the chosen
		 * level (CS-PLAN-062 §3.5) — falls back to the raw id when the
		 * catalog no longer knows it.
		 */
		skippedTemplateLabels() {
			const ids = this.applyResult?.skipped ?? []
			return ids.map((id) => this.setupStore.templates.find((t) => t.id === id)?.name || id)
		},
		/**
		 * Q-081-05 (CS-PLAN-081 H4): a basement counts toward the six-floor
		 * total, so the above-ground input caps at five while a basement is
		 * selected (hint shown next to the input). Apartments have no
		 * basement and keep the six-floor bound.
		 */
		maxAboveGroundFloors() {
			return this.quick.basement && this.quick.homeType !== 'apartment' ? 5 : 6
		},
		/**
		 * Floor index that receives the auto-created household room — mirrors
		 * HouseSetupService::injectAutoRooms(): the ground floor, unless the
		 * last floor holds a basement-typed room.
		 */
		householdFloorIndex() {
			if (this.floors.length === 0) {
				return 0
			}
			const lastFloor = this.floors[this.floors.length - 1]
			const lastFloorHasBasement = lastFloor.rooms.some((r) => r.roomType === 'basement')
			return lastFloorHasBasement ? this.floors.length - 1 : 0
		},
		/**
		 * Approximate number of room tasks applySetup will create (CR-027-04):
		 * per room the templates matching its roomType at the chosen level,
		 * plus the auto rooms (hallway per floor + household room) per the
		 * options toggles — mirroring HouseSetupService's materialization.
		 */
		totalTaskCount() {
			let total = 0
			this.floors.forEach((floor) => {
				floor.rooms.forEach((room) => {
					total += this.taskCountForRoom(room)
				})
				if (this.options.createHallways) {
					total += this.taskCountForRoomType('hallway')
				}
			})
			if (this.options.createHouseholdRoom && this.floors.length > 0) {
				total += this.taskCountForRoomType('household')
			}
			return total
		},
	},

	watch: {
		/**
		 * CR-027-03: make the home-type input effective without changing the
		 * apply payload — an apartment pins one floor and rules out a
		 * basement; switching back to a house restores the defaults.
		 *
		 * @param {string} type The newly selected home type
		 */
		'quick.homeType'(type) {
			if (type === 'apartment') {
				this.quick.floorCount = 1
				this.quick.basement = false
			} else {
				this.quick.floorCount = 2
			}
		},
		/**
		 * Q-081-05 (CS-PLAN-081 H4): selecting a basement clamps an
		 * above-ground selection that would exceed the six-floor total —
		 * visibly (the input bound and hint change), never silently.
		 *
		 * @param {boolean} selected Whether the basement checkbox is on
		 */
		'quick.basement'(selected) {
			if (selected && this.quick.homeType !== 'apartment' && this.quick.floorCount > 5) {
				this.quick.floorCount = 5
			}
		},
	},

	async mounted() {
		// Modal dialog (CS-PLAN-062 §3.5): move focus into the dialog so
		// keyboard users start inside it and the focus trap has an anchor.
		this.$nextTick(() => {
			this.$refs.container?.focus()
		})
		try {
			await Promise.all([
				this.setupStore.fetchCatalog(),
				this.userStore.fetchHouseUsers(),
			])
		} catch (error) {
			logger.error('Wizard catalog/users load failed', error, { component: 'SetupWizard', action: 'mounted' })
		}
	},

	methods: {
		t,
		/**
		 * Modal keyboard behavior (CS-PLAN-062 §3.5, decision D8): Esc closes
		 * the wizard; Tab is trapped inside the dialog. The backdrop no longer
		 * discards input on a stray click.
		 *
		 * @param {KeyboardEvent} event The DOM keydown event
		 */
		onOverlayKeydown(event) {
			if (event.key === 'Escape') {
				this.$emit('close')
				return
			}
			if (event.key !== 'Tab') {
				return
			}
			const container = this.$refs.container
			if (!container) {
				return
			}
			const focusables = container.querySelectorAll('button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',)
			if (focusables.length === 0) {
				return
			}
			const first = focusables[0]
			const last = focusables[focusables.length - 1]
			const active = document.activeElement
			if (event.shiftKey && (active === first || !container.contains(active))) {
				last.focus()
				event.preventDefault()
			} else if (!event.shiftKey && (active === last || !container.contains(active))) {
				first.focus()
				event.preventDefault()
			}
		},
		next() {
			if (this.step === 1) {
				this.generateRooms()
			}
			this.step++
		},
		generateRooms() {
			const roomTypes = this.setupStore.roomTypes
			this.floors = []
			const floorTotal = Math.min(this.maxAboveGroundFloors, Math.max(1, this.quick.floorCount))
			for (let f = 0; f < floorTotal; f++) {
				this.floors.push({ name: this.floorName(f, floorTotal), rooms: [] })
			}
			const wantsBasement = this.quick.basement && this.quick.homeType !== 'apartment'
			if (wantsBasement && this.floors.length < 6) {
				this.floors.push({ name: t('chorestrike', 'Basement'), rooms: [] })
			}
			const roomsPerFloor = Math.min(6, Math.max(1, this.quick.roomsPerFloor))
			this.floors.forEach((floor) => {
				for (let r = 0; r < roomsPerFloor; r++) {
					const rt = roomTypes[r % Math.max(1, roomTypes.length)] || roomTypes[0]
					floor.rooms.push({
						name: rt ? rt.defaultName : t('chorestrike', 'Room'),
						roomType: rt ? rt.id : 'generic',
						assigneeUserId: '',
					})
				}
			})
		},
		floorName(index, total) {
			if (total === 1) {
				return t('chorestrike', 'Ground Floor')
			}
			if (index === 0) {
				return t('chorestrike', 'Ground Floor')
			}
			return `${index + 1}. ${t('chorestrike', 'Floor')}`
		},
		onRoomTypeChange(room) {
			const rt = this.setupStore.roomTypes.find((r) => r.id === room.roomType)
			if (rt) {
				room.name = rt.defaultName
			}
		},
		/**
		 * Approximate task count for one wizard room: catalog templates that
		 * target its roomType and are not level-exclusive at the chosen level
		 * (a null level entry is skipped by applySetup).
		 *
		 * @param {object} room A wizard room ({ name, roomType, assigneeUserId })
		 */
		taskCountForRoom(room) {
			return this.taskCountForRoomType(room.roomType)
		},
		taskCountForRoomType(roomType) {
			return this.setupStore.templates.filter((t) => Array.isArray(t.roomTypes)
				&& t.roomTypes.includes(roomType)
				&& t.levels
				&& t.levels[this.quick.intensityLevel] != null,).length
		},
		addRoom(floorIndex) {
			this.floors[floorIndex].rooms.push({
				name: t('chorestrike', 'Room'),
				roomType: 'generic',
				assigneeUserId: '',
			})
		},
		removeRoom(floorIndex, roomIndex) {
			this.floors[floorIndex].rooms.splice(roomIndex, 1)
		},
		async apply() {
			try {
				// The house name is part of the setup command (CS-PLAN-062
				// §3.2): validated and persisted inside the backend setup
				// transaction — no separate rename request anymore.
				const payload = {
					houseName: this.houseName,
					intensityLevel: this.quick.intensityLevel,
					options: { ...this.options },
					floors: this.floors.map((floor) => ({
						name: floor.name,
						rooms: floor.rooms.map((room) => ({
							name: room.name,
							roomType: room.roomType,
							icon: iconForRoomType(room.roomType),
							assigneeUserId: room.assigneeUserId || null,
						})),
					})),
				}
				this.alreadySetUp = false
				this.applyResult = await this.setupStore.applySetup(payload)

				// Optional invite step before the wizard closes (UX-003)
				this.step = 4
			} catch (error) {
				if (error?.status === 409 && error?.code === 'HOUSE_ALREADY_SET_UP') {
					// Recoverable race (CS-PLAN-062 §3.5): a second tab applied
					// first. Keep everything intact and render the explicit
					// already-set-up state instead of a generic error — the
					// store error is cleared so the recovery panel shows
					// alone (Standard B review F2).
					this.setupStore.clearError()
					this.alreadySetUp = true
					return
				}
				// Validation failures keep the user on the preview step with
				// all input preserved; the store error is rendered inline.
				logger.error('Wizard apply failed', error, { component: 'SetupWizard', action: 'apply' })
			}
		},
		addInviteRow() {
			this.invites.push({ email: '', status: null, statusName: '', statusReason: '' })
		},
		removeInviteRow(index) {
			this.invites.splice(index, 1)
		},
		/**
		 * Send one invite per pending row and record the per-recipient outcome
		 * on the row itself (CS-PLAN-062 §3.4): 'joined' names the joined
		 * user, 'failed' carries the localized reason. The step stays open so
		 * the statuses stay visible — failures keep their rows editable for a
		 * retry, a full success lets the user finish explicitly.
		 */
		async sendInvites() {
			const pending = this.invites
				.map((invite, index) => ({ invite, index }))
				.filter(({ invite }) => EMAIL_PATTERN.test(invite.email) && invite.status !== 'joined')
			if (pending.length === 0) {
				return
			}

			this.sendingInvites = true
			const outcomes = await Promise.all(pending.map(({ invite, index }) => this.inviteOne(invite, index)),)
			const failedCount = outcomes.filter((joined) => !joined).length

			// Log hygiene (CS-PLAN-061 §3.6): only the count goes to the log —
			// the reasons can carry invite emails and are shown per row instead.
			if (failedCount > 0) {
				logger.error('Wizard invites failed', { count: failedCount }, { component: 'SetupWizard', action: 'sendInvites' })
			}
			this.sendingInvites = false
		},
		/**
		 * Invite one recipient; never throws — the outcome is recorded on the
		 * row and reported as the boolean return.
		 *
		 * @param {object} invite The invite row
		 * @param {number} index Row index (for tests/debugging)
		 * @return {Promise<boolean>} True when the recipient joined
		 */
		async inviteOne(invite, index) {
			try {
				const houseUser = await this.userStore.inviteUser(invite.email, 'member')
				invite.status = 'joined'
				invite.statusName = houseUser?.displayName || invite.email
				return true
			} catch (error) {
				invite.status = 'failed'
				invite.statusReason = error?.message || ''
				logger.debug('Invite failed for a recipient', { component: 'SetupWizard', action: 'inviteOne', index })
				return false
			}
		},
		finish() {
			this.$emit('applied', this.applyResult)
		},
		/**
		 * "Start empty" (UX-011): the dismissal persists per house so the
		 * wizard does not re-open on every reload while the house is empty.
		 * The manual entry point on the Dashboard stays available.
		 */
		skip() {
			try {
				window.localStorage.setItem(wizardDismissedKey(this.houseStore.house?.id ?? null), '1')
			} catch (error) {
				logger.warn('Could not persist wizard dismissal', { component: 'SetupWizard', action: 'skip' })
			}
			this.$emit('skipped')
		},
	},
}
</script>

<style scoped>
.wizard-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1100;
	padding: 1rem;
}

.wizard-container {
	background: var(--color-main-background);
	border-radius: 12px;
	box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
	width: min(640px, 96vw);
	max-height: 92vh;
	overflow: auto;
	padding: 1.5rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.wizard-header { position: relative; }

.wizard-tagline { color: var(--color-text-maxcontrast); margin-top: 0.25rem; }

.wizard-skip {
	position: absolute;
	top: 0;
	inset-inline-end: 0;
	background: transparent;
	border: none;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	text-decoration: underline;
	/* CS-PLAN-062 §3.5: ≥ 44 px touch target */
	min-height: 44px;
	padding: 0.5rem 0.75rem;
}

.wizard-steps {
	display: flex;
	gap: 0.5rem;
	list-style: none;
	padding: 0;
	margin: 0;
	font-size: 0.85rem;
}

.wizard-steps li { opacity: 0.5; }

.wizard-steps li.active { opacity: 1; font-weight: bold; }

.wizard-step { display: flex; flex-direction: column; gap: 0.75rem; }

.field { display: flex; flex-direction: column; gap: 0.25rem; }

.field.checkbox { flex-direction: row; align-items: center; gap: 0.5rem; }

.level-cards { display: flex; gap: 0.5rem; }

.level-card {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	text-align: start;
	padding: 0.75rem;
	border: 2px solid var(--color-border);
	border-radius: 8px;
	background: transparent;
	cursor: pointer;
}

.level-card.selected { border-color: var(--color-primary); background: var(--color-primary-light); }

.wizard-floor { border-top: 1px solid var(--color-border); padding-top: 0.5rem; }

.wizard-room-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; flex-wrap: wrap; }

.wizard-room-row input, .wizard-room-row select { flex: 1; min-width: 8rem; }

.room-remove {
	background: transparent;
	border: none;
	cursor: pointer;
	font-size: 1.25rem;
	/* CS-PLAN-062 §3.5: ≥ 44 px touch target + accessible name */
	min-width: 44px;
	min-height: 44px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.wizard-error { color: var(--color-error-text); margin: 0; }

.already-set-up {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	align-items: flex-start;
}

.apply-result {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 0.75rem 1rem;
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.apply-result p { margin: 0; }

.apply-result-title { font-weight: bold; }

.apply-result-skipped { color: var(--color-text-maxcontrast); }

.preview-floor { border-top: 1px solid var(--color-border); padding-top: 0.5rem; display: flex; flex-direction: column; gap: 0.25rem; }

.preview-floor h4 { margin: 0; }

.preview-room { display: flex; justify-content: space-between; gap: 0.5rem; align-items: center; }

.task-chip {
	background: var(--color-primary-light);
	border-radius: 999px;
	padding: 0.1rem 0.6rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.wizard-footer { display: flex; justify-content: flex-end; gap: 0.5rem; }

/* §3.4 touch targets: primary/secondary actions stay ≥44px tall at every
   viewport, including the mobile wizard journey. */
.primary-button { background: var(--color-primary); color: white; border: none; border-radius: 8px; padding: 0.5rem 1rem; min-height: 44px; cursor: pointer; }

.primary-button:disabled { opacity: 0.6; cursor: not-allowed; }

.secondary-button { background: transparent; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.5rem 1rem; min-height: 44px; cursor: pointer; }

.invite-hint { color: var(--color-text-maxcontrast); font-size: 0.85rem; margin: 0; }

.invite-row { display: flex; gap: 0.5rem; align-items: center; }

.invite-email-input { flex: 1; min-height: 44px; padding: 0.5rem 0.75rem; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-main-background); color: var(--color-main-text); }

.invite-remove { background: transparent; border: none; cursor: pointer; font-size: 1.25rem; min-width: 44px; min-height: 44px; }

.invite-add {
	background: transparent;
	border: none;
	color: var(--color-primary-element);
	cursor: pointer;
	padding: 0.5rem 0;
	align-self: flex-start;
	/* CS-PLAN-062 §3.5: ≥ 44 px touch target */
	min-height: 44px;
}

.invite-status {
	flex-basis: 100%;
	font-size: 0.85rem;
}

.invite-status.joined { color: var(--color-success-text); }

.invite-status.failed { color: var(--color-error-text); }
</style>
