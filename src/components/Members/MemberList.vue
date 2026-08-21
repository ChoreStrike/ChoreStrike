<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="member-list" data-testid="member-list">
		<div class="member-list-header">
			<h3>{{ t('chorestrike', 'Members') }}</h3>
			<button
				class="primary-button"
				data-testid="show-invite-button"
				@click="inviteVisible = !inviteVisible">
				<Icon name="UserPlus" :size="16" class="icon" />
				{{ t('chorestrike', 'Invite') }}
			</button>
		</div>

		<!-- Invite form -->
		<form v-if="inviteVisible"
			class="invite-form"
			data-testid="invite-form"
			@submit.prevent="submitInvite">
			<div class="invite-fields">
				<input
					v-model.trim="inviteEmail"
					type="email"
					class="invite-input"
					:placeholder="t('chorestrike', 'Email address')"
					data-testid="invite-email-input"
					required>
				<select v-model="inviteRole" class="invite-role" data-testid="invite-role-select">
					<option value="member">
						{{ t('chorestrike', 'Member') }}
					</option>
					<option value="child">
						{{ t('chorestrike', 'Child') }}
					</option>
					<option value="owner">
						{{ t('chorestrike', 'Owner') }}
					</option>
				</select>
				<button
					type="submit"
					class="primary-button"
					data-testid="invite-submit-button"
					:disabled="inviting || !isValidEmail">
					{{ t('chorestrike', 'Send invite') }}
				</button>
			</div>
			<p class="invite-hint">
				{{ t('chorestrike', 'The person needs an existing Nextcloud account with this email address.') }}
			</p>
		</form>

		<!-- Member rows -->
		<div v-if="loading" class="members-empty">
			{{ t('chorestrike', 'Loading members…') }}
		</div>
		<div v-else class="member-rows">
			<div
				v-for="member in members"
				:key="member.id"
				class="member-row"
				:data-testid="`member-row-${member.nextcloudUserId}`">
				<div class="member-info">
					<span class="member-name">{{ member.displayName }}</span>
					<span class="member-badges">
						<span class="role-badge" :class="`role-${member.role}`" :data-testid="`member-role-badge-${member.nextcloudUserId}`">
							{{ roleLabel(member.role) }}
						</span>
						<span v-if="isSelf(member)" class="self-hint">
							{{ t('chorestrike', '(you)') }}
						</span>
					</span>
				</div>

				<div class="member-actions">
					<select
						class="role-select"
						:value="member.role"
						:data-testid="`member-role-select-${member.nextcloudUserId}`"
						:disabled="isSelf(member)"
						:title="isSelf(member) ? t('chorestrike', 'You cannot change your own role') : t('chorestrike', 'Change role')"
						@change="changeRole(member, $event)">
						<option value="owner">
							{{ t('chorestrike', 'Owner') }}
						</option>
						<option value="member">
							{{ t('chorestrike', 'Member') }}
						</option>
						<option value="child">
							{{ t('chorestrike', 'Child') }}
						</option>
					</select>

					<button
						class="icon-button danger"
						:data-testid="`member-remove-${member.nextcloudUserId}`"
						:title="t('chorestrike', 'Remove from house')"
						:disabled="isSelf(member)"
						@click="openRemoveConfirm(member)">
						<Icon name="Trash" :size="16" />
					</button>
				</div>
			</div>
		</div>

		<!-- Remove confirmation -->
		<Modal v-if="removingMember" :show="true" @close="removingMember = null">
			<div class="confirm-modal" data-testid="member-remove-confirm">
				<div class="confirm-icon">
					<Icon name="AlertTriangle" :size="40" />
				</div>
				<h4>{{ t('chorestrike', 'Remove {name} from the house?', { name: removingMember.displayName }) }}</h4>
				<p>{{ t('chorestrike', 'They will lose access to all house tasks and progress.') }}</p>
				<div class="confirm-actions">
					<button class="secondary-button" data-testid="member-remove-cancel" @click="removingMember = null">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button
						class="danger-button"
						data-testid="member-remove-confirm-button"
						:disabled="removing"
						@click="confirmRemove">
						{{ t('chorestrike', 'Remove') }}
					</button>
				</div>
			</div>
		</Modal>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useUserStore } from '../../store/user.js'
import { logger } from '../../utils/logger.js'
import Icon from '../Common/Icon.vue'
import Modal from '../Common/Modal.vue'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export default {
	name: 'MemberList',

	components: {
		Icon,
		Modal,
	},

	setup() {
		const userStore = useUserStore()
		return { userStore }
	},

	data() {
		return {
			loading: false,
			inviteVisible: false,
			inviteEmail: '',
			inviteRole: 'member',
			inviting: false,
			removingMember: null,
			removing: false,
		}
	},

	computed: {
		members() {
			return this.userStore.houseUsers
		},

		isValidEmail() {
			return EMAIL_PATTERN.test(this.inviteEmail)
		},
	},

	async mounted() {
		this.loading = true
		try {
			await this.userStore.initializeCurrentUser()
			await this.userStore.fetchHouseUsers()
		} catch (error) {
			logger.error('Failed to load members', error, { component: 'MemberList', action: 'mounted' })
			showError(t('chorestrike', 'Could not load members'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		isSelf(member) {
			return this.userStore.currentUser?.nextcloudUserId === member.nextcloudUserId
		},

		roleLabel(role) {
			return {
				owner: t('chorestrike', 'Owner'),
				member: t('chorestrike', 'Member'),
				child: t('chorestrike', 'Child'),
			}[role] || role
		},

		async submitInvite() {
			if (!this.isValidEmail) return
			this.inviting = true
			try {
				const data = await this.userStore.inviteUser(this.inviteEmail, this.inviteRole)
				// The invite endpoint returns the (possibly new) house user —
				// refetch so the list reflects server truth either way.
				await this.userStore.fetchHouseUsers()
				const invitedName = data?.displayName || this.inviteEmail
				showSuccess(t('chorestrike', '{name} joined the house', { name: invitedName }))
				this.inviteEmail = ''
				this.inviteVisible = false
			} catch (error) {
				showError(error?.message || t('chorestrike', 'Invite failed'))
				logger.error('Failed to invite user', error, { component: 'MemberList', action: 'submitInvite' })
			} finally {
				this.inviting = false
			}
		},

		async changeRole(member, event) {
			const role = event.target.value
			try {
				await this.userStore.updateUserRole(member.id, role)
				showSuccess(t('chorestrike', 'Role updated for {name}', { name: member.displayName }))
			} catch (error) {
				showError(error?.message || t('chorestrike', 'Failed to change role'))
				logger.error('Failed to update role', error, { component: 'MemberList', action: 'changeRole', role })
				// Reset the select to the member's actual role
				event.target.value = member.role
			}
		},

		openRemoveConfirm(member) {
			this.removingMember = member
		},

		async confirmRemove() {
			if (!this.removingMember) return
			this.removing = true
			try {
				await this.userStore.removeUser(this.removingMember.id)
				showSuccess(t('chorestrike', '{name} was removed from the house', { name: this.removingMember.displayName }))
				this.removingMember = null
			} catch (error) {
				showError(error?.message || t('chorestrike', 'Failed to remove member'))
				logger.error('Failed to remove member', error, { component: 'MemberList', action: 'confirmRemove' })
			} finally {
				this.removing = false
			}
		},
	},
}
</script>

<style scoped>
.member-list {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.member-list-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.member-list-header h3 {
	margin: 0;
	font-size: 1.1rem;
}

.invite-form {
	padding: 1rem;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.invite-fields {
	display: flex;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.invite-input {
	flex: 1;
	min-width: 200px;
	min-height: 44px;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.invite-role {
	min-height: 44px;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.invite-hint {
	margin: 0.5rem 0 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.members-empty {
	color: var(--color-text-maxcontrast);
}

.member-rows {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.member-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	padding: 0.75rem 1rem;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	flex-wrap: wrap;
}

.member-info {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex-wrap: wrap;
}

.member-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.member-badges {
	display: flex;
	align-items: center;
	gap: 0.4rem;
}

.role-badge {
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
}

.role-badge.role-owner {
	background: var(--color-warning-hover);
	color: var(--color-warning);
}

.role-badge.role-member {
	background: var(--color-primary-light);
	color: var(--color-primary);
}

.role-badge.role-child {
	background: var(--color-success-hover);
	color: var(--color-success);
}

.self-hint {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.member-actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.role-select {
	min-height: 44px;
	padding: 0.4rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.role-select:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.icon-button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	background: transparent;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: all 0.2s;
}

.icon-button.danger:hover:not(:disabled) {
	background: var(--color-error-hover);
	color: var(--color-error);
}

.icon-button:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.confirm-modal {
	padding: 1.5rem;
	text-align: center;
}

.confirm-icon {
	color: var(--color-error);
	margin-bottom: 0.5rem;
}

.confirm-modal h4 {
	margin: 0 0 0.5rem 0;
	font-size: 1.1rem;
}

.confirm-modal p {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
}

.confirm-actions {
	display: flex;
	justify-content: center;
	gap: 0.75rem;
}

.primary-button {
	display: flex;
	align-items: center;
	gap: 0.4rem;
	min-height: 44px;
	padding: 0.5rem 1rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
}

.danger-button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	background: var(--color-error);
	color: #fff;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.danger-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

@media (max-width: 600px) {
	.member-row {
		flex-direction: column;
		align-items: stretch;
	}

	.member-actions {
		justify-content: flex-end;
	}
}
</style>
