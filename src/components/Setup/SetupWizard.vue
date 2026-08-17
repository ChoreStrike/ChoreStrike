<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div v-if="show"
		class="wizard-overlay"
		data-testid="setup-wizard"
		@click.self="$emit('close')">
		<div class="wizard-container" @click.stop>
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
				<li :class="{ active: step === 1 }">
					{{ t('chorestrike', 'Quick Start') }}
				</li>
				<li :class="{ active: step === 2 }">
					{{ t('chorestrike', 'Room List') }}
				</li>
				<li :class="{ active: step === 3 }">
					{{ t('chorestrike', 'Preview') }}
				</li>
			</ol>

			<!-- Step 1: Quick Start -->
			<section v-if="step === 1" class="wizard-step" data-testid="wizard-step-quickstart">
				<label class="field">
					<span>{{ t('chorestrike', 'Home type') }}</span>
					<select v-model="quick.homeType" data-testid="wizard-home-type">
						<option value="house">{{ t('chorestrike', 'House') }}</option>
						<option value="apartment">{{ t('chorestrike', 'Apartment') }}</option>
					</select>
				</label>

				<label class="field">
					<span>{{ t('chorestrike', 'Number of floors') }} (1–6)</span>
					<input v-model.number="quick.floorCount"
						type="number"
						min="1"
						max="6"
						data-testid="wizard-floor-count">
				</label>

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
						<select v-model="room.assigneeUserId" :data-testid="`wizard-room-person-${fi}-${ri}`">
							<option value="">
								{{ t('chorestrike', 'For everyone') }}
							</option>
							<option v-for="u in people" :key="u.nextcloudUserId" :value="u.nextcloudUserId">
								{{ u.displayName }}
							</option>
						</select>
						<button type="button"
							class="room-remove"
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

			<!-- Step 3: Preview -->
			<section v-else class="wizard-step" data-testid="wizard-step-preview">
				<p v-if="setupStore.applying">
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

			<p v-if="setupStore.error" class="wizard-error" data-testid="wizard-error">
				{{ setupStore.error }}
			</p>

			<footer class="wizard-footer">
				<button v-if="step > 1"
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
				<button v-else
					class="primary-button"
					data-testid="wizard-apply"
					:disabled="setupStore.applying"
					@click="apply">
					{{ t('chorestrike', "Let's go!") }}
				</button>
			</footer>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useSetupStore } from '../../store/setup.js'
import { useUserStore } from '../../store/user.js'
import { iconForRoomType } from '../../utils/roomIcons.js'
import { logger } from '../../utils/logger.js'

export default {
	name: 'SetupWizard',

	props: {
		show: { type: Boolean, default: true },
	},

	emits: ['close', 'applied', 'skipped'],

	setup() {
		const setupStore = useSetupStore()
		const userStore = useUserStore()
		return { setupStore, userStore }
	},

	data() {
		return {
			step: 1,
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
		}
	},

	computed: {
		people() {
			return this.userStore.houseUsers
		},
		totalRooms() {
			return this.floors.reduce((sum, f) => sum + f.rooms.length, 0)
		},
		selectedLevelLabel() {
			const lvl = this.setupStore.levels.find(l => l.id === this.quick.intensityLevel)
			return lvl ? lvl.label : this.quick.intensityLevel
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
			const lastFloorHasBasement = lastFloor.rooms.some(r => r.roomType === 'basement')
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
			this.floors.forEach(floor => {
				floor.rooms.forEach(room => {
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
	},

	async mounted() {
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
		next() {
			if (this.step === 1) {
				this.generateRooms()
			}
			this.step++
		},
		generateRooms() {
			const roomTypes = this.setupStore.roomTypes
			this.floors = []
			const floorTotal = Math.min(6, Math.max(1, this.quick.floorCount))
			for (let f = 0; f < floorTotal; f++) {
				this.floors.push({ name: this.floorName(f, floorTotal), rooms: [] })
			}
			if (this.quick.basement && this.floors.length < 6) {
				this.floors.push({ name: t('chorestrike', 'Basement'), rooms: [] })
			}
			const roomsPerFloor = Math.min(6, Math.max(1, this.quick.roomsPerFloor))
			this.floors.forEach(floor => {
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
			if (total === 1) return t('chorestrike', 'Ground Floor')
			if (index === 0) return t('chorestrike', 'Ground Floor')
			return `${index + 1}. ${t('chorestrike', 'Floor')}`
		},
		onRoomTypeChange(room) {
			const rt = this.setupStore.roomTypes.find(r => r.id === room.roomType)
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
			return this.setupStore.templates.filter(t =>
				Array.isArray(t.roomTypes)
				&& t.roomTypes.includes(roomType)
				&& t.levels
				&& t.levels[this.quick.intensityLevel] != null,
			).length
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
				const payload = {
					intensityLevel: this.quick.intensityLevel,
					options: { ...this.options },
					floors: this.floors.map(floor => ({
						name: floor.name,
						rooms: floor.rooms.map(room => ({
							name: room.name,
							roomType: room.roomType,
							icon: iconForRoomType(room.roomType),
							assigneeUserId: room.assigneeUserId || null,
						})),
					})),
				}
				const result = await this.setupStore.applySetup(payload)
				this.$emit('applied', result)
			} catch (error) {
				logger.error('Wizard apply failed', error, { component: 'SetupWizard', action: 'apply' })
			}
		},
		skip() {
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

.room-remove { background: transparent; border: none; cursor: pointer; font-size: 1.25rem; }

.wizard-error { color: var(--color-error); margin: 0; }

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

.primary-button { background: var(--color-primary); color: white; border: none; border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; }

.primary-button:disabled { opacity: 0.6; cursor: not-allowed; }

.secondary-button { background: transparent; border: 1px solid var(--color-border); border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; }
</style>
