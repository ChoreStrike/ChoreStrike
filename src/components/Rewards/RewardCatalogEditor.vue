<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="catalog-editor" data-testid="catalog-editor">
		<HelpEntry variant="context" topic="owner-pricing" />

		<!-- The household gate: works while browsing is hidden or the gate
		     itself is off — owner administration is never gated by the
		     personal preference. -->
		<div class="form-group" data-testid="catalog-gate">
			<label for="catalog-enabled">{{ t('chorestrike', 'Reward catalog') }}</label>
			<p class="setting-hint">
				{{ t('chorestrike', 'The household catalog is off by default. Enabling it opens browsing for members who kept the catalog visible in their own settings — it never changes anyone’s personal choice.') }}
			</p>
			<div class="input-group">
				<input
					id="catalog-enabled"
					v-model="gateDraft"
					type="checkbox"
					class="gate-toggle"
					data-testid="catalog-gate-toggle"
					:disabled="houseStore.loading">
				<button
					class="save-button"
					data-testid="catalog-gate-save"
					:disabled="!canSaveGate || houseStore.loading"
					@click="saveGate">
					{{ t('chorestrike', 'Save') }}
				</button>
			</div>
			<p v-if="gateSaved"
				class="saved-note"
				data-testid="catalog-gate-saved"
				role="status">
				{{ t('chorestrike', 'The catalog setting was saved.') }}
			</p>
			<!-- Only this gate's own save drives the note: the house store's
			     shared error also reports name, timezone, ranking and arcade
			     saves on the same Settings page (CS-PLAN-086 R086B-01). -->
			<p v-else-if="gateFailed"
				class="error-note"
				data-testid="catalog-gate-error"
				role="alert">
				{{ t('chorestrike', 'The catalog setting could not be saved. Please try again.') }}
			</p>
		</div>

		<p v-if="rewardsStore.conflict"
			class="conflict-note"
			data-testid="catalog-editor-conflict"
			role="alert">
			{{ t('chorestrike', 'The catalog changed in the meantime — the current items are shown. Your unsaved draft was kept.') }}
		</p>

		<!-- Create form: exact decimal price entry via the strict shared
		     parser; drafts stay strings until the explicit save. -->
		<form class="form-group" data-testid="catalog-item-create" @submit.prevent="submitCreate">
			<label for="new-item-title">{{ t('chorestrike', 'New reward') }}</label>
			<input
				id="new-item-title"
				v-model.trim="createDraft.title"
				type="text"
				class="text-input"
				maxlength="120"
				data-testid="new-item-title"
				:placeholder="t('chorestrike', 'Title (required)')">
			<textarea
				v-model="createDraft.description"
				class="text-input description-input"
				maxlength="2000"
				rows="3"
				data-testid="new-item-description"
				:placeholder="t('chorestrike', 'Description (optional)')" />
			<div class="price-row">
				<label for="new-item-price" class="price-label">{{ t('chorestrike', 'Price in Coins') }}</label>
				<input
					id="new-item-price"
					v-model="createDraft.price"
					type="text"
					inputmode="decimal"
					class="text-input price-input"
					data-testid="new-item-price"
					:placeholder="t('chorestrike', 'e.g. 12.50')">
				<button
					class="save-button"
					type="submit"
					data-testid="new-item-submit"
					:disabled="rewardsStore.saving">
					{{ t('chorestrike', 'Add') }}
				</button>
			</div>
			<p v-if="formError"
				class="error-note"
				data-testid="catalog-form-error"
				role="alert">
				{{ formError }}
			</p>
		</form>

		<div v-if="page.loading && page.items.length === 0"
			class="status-line"
			data-testid="catalog-admin-loading"
			role="status">
			{{ t('chorestrike', 'Loading the catalog…') }}
		</div>
		<p v-else-if="page.items.length === 0" class="status-line" data-testid="catalog-admin-empty">
			{{ t('chorestrike', 'No rewards yet — add the first one above.') }}
		</p>
		<ul v-else class="admin-list" data-testid="catalog-admin-list">
			<li
				v-for="item in page.items"
				:key="item.id"
				class="admin-row"
				:class="{ archived: item.archived }"
				:data-testid="`admin-item-${item.id}`">
				<span class="admin-item-main">
					<strong class="admin-item-title">{{ item.title }}</strong>
					<span class="admin-item-price">{{ formatCoinMinor(item.priceMinor) }}</span>
					<span v-if="item.archived" class="archived-chip">{{ t('chorestrike', 'Archived') }}</span>
				</span>
				<span class="admin-item-actions">
					<button
						type="button"
						class="mini-button"
						:data-testid="`admin-edit-${item.id}`"
						@click="beginEdit(item)">
						{{ t('chorestrike', 'Edit') }}
					</button>
					<button
						v-if="!item.archived"
						type="button"
						class="mini-button danger"
						:data-testid="`admin-archive-${item.id}`"
						@click="itemToArchive = item">
						{{ t('chorestrike', 'Archive') }}
					</button>
				</span>
			</li>
		</ul>
		<button
			v-if="page.items.length > 0 && !page.exhausted"
			type="button"
			class="load-more"
			data-testid="catalog-admin-load-more"
			:disabled="page.loading"
			@click="loadMore">
			{{ page.loading ? t('chorestrike', 'Loading…') : t('chorestrike', 'Load more') }}
		</button>

		<!-- Edit form: the same strict price parsing; a 409 keeps this
		     draft and shows the refreshed item. -->
		<Modal
			v-if="editingItem"
			:aria-label="t('chorestrike', 'Edit reward')"
			data-testid="catalog-item-edit"
			@close="editingItem = null">
			<div class="modal-content">
				<h3>{{ t('chorestrike', 'Edit reward') }}</h3>
				<label class="reason-field" for="edit-item-title">{{ t('chorestrike', 'Title') }}</label>
				<input
					id="edit-item-title"
					v-model.trim="editDraft.title"
					type="text"
					class="text-input"
					maxlength="120"
					data-testid="edit-item-title">
				<label class="reason-field" for="edit-item-description">{{ t('chorestrike', 'Description') }}</label>
				<textarea
					id="edit-item-description"
					v-model="editDraft.description"
					class="text-input description-input"
					maxlength="2000"
					rows="3"
					data-testid="edit-item-description" />
				<label class="reason-field" for="edit-item-price">{{ t('chorestrike', 'Price in Coins') }}</label>
				<input
					id="edit-item-price"
					v-model="editDraft.price"
					type="text"
					inputmode="decimal"
					class="text-input price-input"
					data-testid="edit-item-price">
				<p v-if="formError"
					class="error-note"
					data-testid="catalog-edit-error"
					role="alert">
					{{ formError }}
				</p>
				<div class="modal-actions">
					<button type="button"
						class="secondary-button"
						data-testid="edit-item-cancel"
						@click="editingItem = null">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						type="button"
						class="primary-button"
						data-testid="edit-item-save"
						:disabled="rewardsStore.saving"
						@click="submitEdit">
						{{ rewardsStore.saving ? t('chorestrike', 'Saving…') : t('chorestrike', 'Save') }}
					</button>
				</div>
			</div>
		</Modal>

		<ConfirmDialog
			v-if="itemToArchive"
			:title="archiveTitle"
			:description="t('chorestrike', 'Archived rewards disappear from browsing. Existing requests keep their frozen terms and stay processable.')"
			:confirm-label="t('chorestrike', 'Archive')"
			:loading="rewardsStore.saving"
			data-testid="archive-dialog"
			@confirm="confirmArchive"
			@cancel="itemToArchive = null" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mapStores } from 'pinia'
import { useRewardsStore } from '../../store/rewards.js'
import { useHouseStore } from '../../store/house.js'
import { formatCoinMinor, parseCoinMinor } from '../../utils/minorUnits.js'
import { logger } from '../../utils/logger.js'
import Modal from '../Common/Modal.vue'
import ConfirmDialog from '../Common/ConfirmDialog.vue'
import HelpEntry from '../Help/HelpEntry.vue'

const TITLE_MAX = 120
const DESCRIPTION_MAX = 2000

/**
 * Owner catalog administration (CS-PLAN-070 WP8 §3.1): the household
 * gate toggle plus item create/edit/archive with EXACT decimal price
 * entry. Works while browsing is hidden or the gate is off. Prices are
 * strict-parsed to integer minor units (never float arithmetic), drafts
 * survive 409 conflicts, and authored text is plain escaped content
 * (never HTML/Markdown, never an l10n key).
 */
export default {
	name: 'RewardCatalogEditor',

	components: {
		Modal,
		ConfirmDialog,
		HelpEntry,
	},

	data() {
		return {
			gateDraft: false,
			gateSaved: false,
			gateFailed: false,
			createDraft: { title: '', description: '', price: '' },
			editDraft: { title: '', description: '', price: '' },
			editingItem: null,
			itemToArchive: null,
			formError: null,
		}
	},

	computed: {
		...mapStores(useRewardsStore, useHouseStore),
		page() {
			return this.rewardsStore.catalog.admin
		},
		canSaveGate() {
			return this.gateDraft !== this.houseStore.catalogEnabled
		},
		// The quoted-title pattern must live in script — nested double
		// quotes are not parseable inside template attribute expressions.
		archiveTitle() {
			if (!this.itemToArchive) {
				return ''
			}
			return t('chorestrike', 'Archive "{title}"?', { title: this.itemToArchive.title })
		},
	},

	watch: {
		'houseStore.catalogEnabled': {
			immediate: true,
			handler(value) {
				this.gateDraft = value === true
			},
		},
	},

	mounted() {
		this.rewardsStore.fetchCatalogPage('admin', { reset: true }).catch((error) => {
			showError(t('chorestrike', 'The catalog could not be loaded.'))
			logger.error('Failed to load the admin catalog', error, { component: 'RewardCatalogEditor', action: 'mounted' })
		})
	},

	methods: {
		t,
		formatCoinMinor,

		async saveGate() {
			this.gateFailed = false
			try {
				await this.houseStore.updateCatalogEnabled(this.gateDraft)
				this.gateSaved = true
				setTimeout(() => {
					this.gateSaved = false
				}, 4000)
			} catch (error) {
				this.gateFailed = true
				logger.error('Failed to save the catalog gate', error, { component: 'RewardCatalogEditor', action: 'saveGate' })
			}
		},

		loadMore() {
			this.rewardsStore.fetchCatalogPage('admin').catch((error) => {
				showError(t('chorestrike', 'The catalog could not be loaded.'))
				logger.error('Failed to load the next admin catalog page', error, { component: 'RewardCatalogEditor', action: 'loadMore' })
			})
		},

		/**
		 * Strict price entry: the draft string is parsed to exact
		 * integer minor units — parseFloat/multiplication/toFixed never
		 * touch money.
		 * @param {string} price Draft text.
		 * @return {number} Exact minor units.
		 */
		parsePrice(price) {
			return parseCoinMinor(price)
		},

		/**
		 * Shared authored-content validation (API bounds: title 1-120,
		 * description ≤2000 characters).
		 * @param {object} draft {title, description}.
		 * @return {string|null} Error message or null when valid.
		 */
		validateDraft(draft) {
			if (!draft.title || draft.title.trim() === '') {
				return t('chorestrike', 'A title is required.')
			}
			if (draft.title.length > TITLE_MAX) {
				return t('chorestrike', 'The title must be at most 120 characters long.')
			}
			if ((draft.description ?? '').length > DESCRIPTION_MAX) {
				return t('chorestrike', 'The description must be at most 2000 characters long.')
			}
			return null
		},

		async submitCreate() {
			this.formError = this.validateDraft(this.createDraft)
			let priceMinor = null
			if (!this.formError) {
				try {
					priceMinor = this.parsePrice(this.createDraft.price)
				} catch (error) {
					this.formError = t('chorestrike', 'Enter a valid price, e.g. 12 or 12.50 — at most two decimals.')
				}
			}
			if (this.formError) {
				return
			}
			try {
				await this.rewardsStore.createCatalogItem({
					title: this.createDraft.title.trim(),
					description: this.createDraft.description.trim() || null,
					priceMinor,
				})
				showSuccess(t('chorestrike', 'The reward was added.'))
				this.createDraft = { title: '', description: '', price: '' }
			} catch (error) {
				if (error?.status !== 409) {
					this.formError = error?.message ?? t('chorestrike', 'The reward could not be saved.')
				}
				logger.error('Failed to create the catalog item', error, { component: 'RewardCatalogEditor', action: 'submitCreate' })
			}
		},

		beginEdit(item) {
			this.editingItem = item
			this.formError = null
			this.editDraft = {
				title: item.title,
				description: item.description ?? '',
				price: formatCoinMinor(item.priceMinor),
			}
		},

		async submitEdit() {
			const item = this.editingItem
			if (!item) {
				return
			}
			this.formError = this.validateDraft(this.editDraft)
			let priceMinor = null
			if (!this.formError) {
				try {
					priceMinor = this.parsePrice(this.editDraft.price)
				} catch (error) {
					this.formError = t('chorestrike', 'Enter a valid price, e.g. 12 or 12.50 — at most two decimals.')
				}
			}
			if (this.formError) {
				return
			}
			try {
				await this.rewardsStore.updateCatalogItem(item, {
					title: this.editDraft.title.trim(),
					description: this.editDraft.description.trim() || null,
					priceMinor,
				})
				showSuccess(t('chorestrike', 'The reward was updated.'))
				this.editingItem = null
			} catch (error) {
				// A 409 keeps this draft (conflict note above) — only a
				// non-conflict failure surfaces inline here.
				if (error?.status !== 409) {
					this.formError = error?.message ?? t('chorestrike', 'The reward could not be saved.')
				}
				logger.error('Failed to update the catalog item', error, { component: 'RewardCatalogEditor', action: 'submitEdit' })
			}
		},

		async confirmArchive() {
			const item = this.itemToArchive
			if (!item) {
				return
			}
			try {
				await this.rewardsStore.updateCatalogItem(item, { archived: true })
				showSuccess(t('chorestrike', 'The reward was archived.'))
				this.itemToArchive = null
			} catch (error) {
				showError(t('chorestrike', 'The reward could not be archived.'))
				this.itemToArchive = null
				logger.error('Failed to archive the catalog item', error, { component: 'RewardCatalogEditor', action: 'confirmArchive' })
			}
		},
	},
}
</script>

<style scoped>
.catalog-editor {
	display: flex;
	flex-direction: column;
	gap: 1.25rem;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.form-group label,
.reason-field {
	font-size: 0.9rem;
	font-weight: 500;
}

.setting-hint {
	margin: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.input-group {
	display: flex;
	gap: 0.75rem;
	align-items: center;
}

.gate-toggle {
	width: 1.1rem;
	height: 1.1rem;
	accent-color: var(--color-primary-element);
	cursor: pointer;
}

.save-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.6rem 1rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.save-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.saved-note {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-success-text);
}

.error-note {
	margin: 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-error);
	font-size: 0.9rem;
}

.conflict-note {
	margin: 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-warning);
	font-size: 0.9rem;
}

.status-line {
	padding: 1rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.text-input {
	padding: 0.6rem 0.7rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.description-input {
	resize: vertical;
	font-family: inherit;
}

.price-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex-wrap: wrap;
}

.price-label {
	font-size: 0.9rem;
}

.price-input {
	max-width: 10rem;
}

.admin-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.admin-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	padding: 0.75rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.admin-row.archived {
	opacity: 0.7;
}

.admin-item-main {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex-wrap: wrap;
	min-width: 0;
}

.admin-item-price {
	color: var(--color-text-maxcontrast);
}

.archived-chip {
	padding: 0.1rem 0.5rem;
	border-radius: 999px;
	font-size: 0.75rem;
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.admin-item-actions {
	display: flex;
	gap: 0.5rem;
	flex-shrink: 0;
}

.mini-button {
	min-height: 44px;
	padding: 0.4rem 0.9rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.85rem;
}

.mini-button.danger:hover {
	border-color: var(--color-element-error);
	color: var(--color-error-text);
}

.load-more {
	display: block;
	margin: 0 auto;
	min-height: 44px;
	padding: 0.6rem 1.4rem;
	border: 1px solid var(--color-border);
	border-radius: var(--cs-radius-sm, 6px);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9rem;
}

.modal-content {
	padding: 1rem;
}

.modal-content h3 {
	margin: 0 0 1rem 0;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1.25rem;
}

.primary-button,
.secondary-button {
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border-radius: var(--cs-radius-sm, 6px);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 600;
}

.primary-button {
	border: none;
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
}
</style>
