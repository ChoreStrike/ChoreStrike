<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="task-category-settings cs-settings-form" data-testid="task-categories">
		<div class="setting-card">
			<p class="setting-hint">
				{{ t('chorestrike', 'Categories sort the tasks, and the task list can filter by them. "Other" collects every task without a fitting category.') }}
			</p>
			<ul class="category-list">
				<li v-for="(item, index) in draft"
					:key="item.uid"
					class="category-row"
					:data-testid="`task-category-row-${index}`">
					<span v-if="item.key === otherKey" class="fixed-name">{{ item.name }}</span>
					<input v-else
						v-model="item.name"
						type="text"
						class="text-input"
						:maxlength="nameMax"
						:aria-label="t('chorestrike', 'Name of the category')"
						:data-testid="`task-category-name-${index}`">
					<button type="button"
						class="icon-button"
						:disabled="index === 0 || item.key === otherKey"
						:aria-label="t('chorestrike', 'Move {name} up', { name: item.name })"
						@click="move(index, -1)">
						<Icon name="ChevronUp" :size="18" />
					</button>
					<button type="button"
						class="icon-button"
						:disabled="index >= draft.length - 2"
						:aria-label="t('chorestrike', 'Move {name} down', { name: item.name })"
						@click="move(index, 1)">
						<Icon name="ChevronDown" :size="18" />
					</button>
					<button v-if="item.key !== otherKey"
						type="button"
						class="icon-button danger"
						:aria-label="t('chorestrike', 'Delete {name}', { name: item.name })"
						:data-testid="`task-category-delete-${index}`"
						@click="draft.splice(index, 1)">
						<Icon name="Trash" :size="18" />
					</button>
				</li>
			</ul>
			<div class="input-group">
				<input v-model="newName"
					type="text"
					class="text-input"
					:maxlength="nameMax"
					:placeholder="t('chorestrike', 'New category')"
					data-testid="task-category-new"
					@keydown.enter.prevent="add">
				<button type="button"
					class="secondary-button"
					data-testid="task-category-add"
					:disabled="!canAdd"
					@click="add">
					{{ t('chorestrike', 'Add') }}
				</button>
			</div>
			<p class="setting-hint">
				{{ t('chorestrike', 'A deleted category no longer appears; its tasks count as "Other" until someone gives them a new category.') }}
			</p>
			<button type="button"
				class="save-button"
				data-testid="task-category-save"
				:disabled="!dirty || !namesValid || saving"
				@click="save">
				<Icon name="Save" :size="16" />
				{{ t('chorestrike', 'Save') }}
			</button>
			<p v-if="error"
				class="error-note"
				data-testid="task-category-error"
				role="alert">
				{{ error }}
			</p>
		</div>
	</div>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import { useHouseStore } from '../../store/house.js'
import { logger } from '../../utils/logger.js'
import { categoryName, isBuiltInCategory, OTHER_CATEGORY } from '../../utils/taskCategories.js'

const NAME_MAX = 40
const MAX_CATEGORIES = 20

/**
 * Editable rows of a saved list.
 * @param {Array<{key: string, label: ?string}>} items Saved list.
 * @return {Array<{uid: string, key: string, name: string}>}
 */
function rowsOf(items) {
	return items.map((item) => ({ uid: item.key, key: item.key, name: categoryName(item) }))
}

/**
 * The payload of the rows: a built-in name left as translated stays null,
 * so it keeps following the language.
 * @param {Array<{key: ?string, name: string}>} rows Editor rows.
 * @return {Array<{key: ?string, label: ?string}>}
 */
function payloadOf(rows) {
	return rows.map((row) => {
		const name = row.name.trim()
		const builtIn = row.key !== null && isBuiltInCategory(row.key)
		const unchanged = builtIn && name === categoryName({ key: row.key, label: null })
		return { key: row.key, label: row.key === OTHER_CATEGORY || unchanged ? null : name }
	})
}

/**
 * The household's task categories (CS-PLAN-090 WP-I, D-090-65): owners add,
 * rename, reorder and delete them; *Other* stays last with its name. The
 * whole list saves against the revision this page read.
 */
export default {
	name: 'TaskCategorySettings',

	components: { Icon },

	setup() {
		return { houseStore: useHouseStore() }
	},

	data() {
		return {
			draft: [],
			newName: '',
			saving: false,
			error: null,
			sequence: 0,
			otherKey: OTHER_CATEGORY,
			nameMax: NAME_MAX,
		}
	},

	computed: {
		saved() {
			return this.houseStore.taskCategories
		},

		dirty() {
			return JSON.stringify(payloadOf(this.draft)) !== JSON.stringify(payloadOf(rowsOf(this.saved.items)))
		},

		namesValid() {
			return this.draft.every((row) => row.key === OTHER_CATEGORY || row.name.trim().length > 0)
		},

		canAdd() {
			return this.newName.trim().length > 0 && this.draft.length < MAX_CATEGORIES
		},
	},

	watch: {
		'saved.revision': {
			immediate: true,
			handler() {
				this.draft = rowsOf(this.saved.items)
			},
		},
	},

	methods: {
		t,

		add() {
			if (!this.canAdd) {
				return
			}
			this.draft.splice(this.draft.length - 1, 0, { uid: `new-${++this.sequence}`, key: null, name: this.newName.trim() })
			this.newName = ''
		},

		move(index, step) {
			const [row] = this.draft.splice(index, 1)
			this.draft.splice(index + step, 0, row)
		},

		async save() {
			this.saving = true
			this.error = null
			try {
				await this.houseStore.saveTaskCategories(payloadOf(this.draft))
				showSuccess(t('chorestrike', 'The categories were saved.'))
			} catch (error) {
				this.error = error?.status === 409
					? t('chorestrike', 'Someone else saved the categories first. The current list is shown; please make your changes again.')
					: (error?.message || t('chorestrike', 'The categories could not be saved. Please try again.'))
				if (error?.status === 409) {
					this.draft = rowsOf(this.saved.items)
				}
				logger.error('Failed to save the task categories', error, { component: 'TaskCategorySettings', action: 'save' })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.category-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 1rem 0;
	padding: 0;
	list-style: none;
}

.category-row {
	display: flex;
	align-items: center;
	gap: 0.25rem;
}

.fixed-name {
	flex: 1 1 12rem;
	padding: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.icon-button {
	min-width: 44px;
	min-height: 44px;
}

.icon-button:disabled {
	opacity: 0.35;
	cursor: not-allowed;
}

.save-button {
	margin-top: 1rem;
}

.error-note {
	margin: 0.75rem 0 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-error);
	font-size: 0.9rem;
}
</style>
