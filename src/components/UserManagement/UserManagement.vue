<template>
	<div class="user-management" data-testid="user-management">
		<div class="management-header">
			<h2>{{ t('chorestrike', 'House Members') }}</h2>
			<p>{{ t('chorestrike', 'Manage members and their roles') }}</p>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading members...') }}</p>
		</div>

		<!-- Members List -->
		<div v-else-if="users.length > 0" class="members-container">
			<div class="members-list">
				<div
					v-for="user in users"
					:key="user.id"
					class="member-item">
					<div class="member-info">
						<div class="member-name">{{ user.displayName }}</div>
						<div class="member-role">{{ getRoleLabel(user.role) }}</div>
						<div class="member-joined">{{ t('chorestrike', 'Joined') }}: {{ formatDate(user.joinedAt) }}</div>
					</div>
					<div class="member-actions">
						<select
							v-if="isOwner && user.id !== currentUserId"
							:value="user.role"
							class="role-select"
							@change="updateUserRole(user, $event)">
							<option value="owner">{{ t('chorestrike', 'Owner') }}</option>
							<option value="member">{{ t('chorestrike', 'Member') }}</option>
							<option value="child">{{ t('chorestrike', 'Child') }}</option>
						</select>
						<span v-else class="role-badge">{{ getRoleLabel(user.role) }}</span>
						<button
							v-if="isOwner && user.id !== currentUserId"
							class="delete-button"
							data-testid="remove-user-button"
							@click="confirmRemoveUser(user)">
							{{ t('chorestrike', 'Remove') }}
						</button>
					</div>
				</div>
			</div>

			<!-- Add User Section -->
			<div v-if="isOwner" class="add-user-section">
				<h3>{{ t('chorestrike', 'Add Member') }}</h3>
				<form @submit.prevent="addUser">
					<div class="form-group">
						<label>{{ t('chorestrike', 'User') }}</label>
						<input
							v-model="newUser.userId"
							type="text"
							class="form-input"
							:placeholder="t('chorestrike', 'Enter username')"
							data-testid="user-id-input"
							required />
					</div>
					<div class="form-group">
						<label>{{ t('chorestrike', 'Display Name') }}</label>
						<input
							v-model="newUser.displayName"
							type="text"
							class="form-input"
							:placeholder="t('chorestrike', 'Enter display name')"
							data-testid="display-name-input"
							required />
					</div>
					<div class="form-group">
						<label>{{ t('chorestrike', 'Role') }}</label>
						<select v-model="newUser.role" class="form-input" data-testid="role-select">
							<option value="member">{{ t('chorestrike', 'Member') }}</option>
							<option value="child">{{ t('chorestrike', 'Child') }}</option>
						</select>
					</div>
					<button
						type="submit"
						class="primary-button"
						:disabled="saving"
						data-testid="add-user-button">
						{{ saving ? t('chorestrike', 'Adding...') : t('chorestrike', 'Add Member') }}
					</button>
				</form>
			</div>
		</div>

		<!-- Empty State -->
		<div v-else class="empty-state">
			<div class="empty-icon">🏠</div>
			<h3>{{ t('chorestrike', 'No Members Yet') }}</h3>
			<p>{{ t('chorestrike', 'You are the only member of this house') }}</p>
		</div>

		<!-- Confirmation Dialog -->
		<div v-if="showConfirmDialog" class="confirmation-dialog">
			<div class="dialog-overlay" @click="showConfirmDialog = false" />
			<div class="dialog-content">
				<h3>{{ t('chorestrike', 'Remove Member') }}</h3>
				<p>{{ t('chorestrike', 'Are you sure you want to remove {name}?', { name: userToRemove?.displayName || '' }) }}</p>
				<div class="dialog-actions">
					<button class="secondary-button" @click="showConfirmDialog = false">
						{{ t('chorestrike', 'Cancel') }}
					</button>
					<button class="danger-button" @click="removeUser">
						{{ t('chorestrike', 'Remove') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

interface User {
	id: number
	houseId: number
	nextcloudUserId: string
	displayName: string
	role: string
	joinedAt: string
}

interface NewUser {
	userId: string
	displayName: string
	role: string
}

const loading = ref<boolean>(false)
const saving = ref<boolean>(false)
const users = ref<User[]>([])
const showConfirmDialog = ref<boolean>(false)
const userToRemove = ref<User | null>(null)
const isOwner = ref<boolean>(false)
const currentUserId = ref<number>(0)

const newUser = ref<NewUser>({
	userId: '',
	displayName: '',
	role: 'member'
})

const loadUsers = async (): Promise<void> => {
	loading.value = true
	try {
		const response = await axios.get('/api/v1/user-stats/house-users')
		if (response.data.success) {
			users.value = response.data.data
			// Check if current user is owner
			const currentUser = users.value.find(u => u.nextcloudUserId === getCurrentUserId())
			isOwner.value = currentUser?.role === 'owner' || false
		}
	} catch (error) {
		console.error('Failed to load users:', error)
	} finally {
		loading.value = false
	}
}

const addUser = async (): Promise<void> => {
	if (!newUser.value.userId || !newUser.value.displayName) {
		return
	}

	saving.value = true
	try {
		const response = await axios.post('/api/v1/user-stats/add-user', {
			userId: newUser.value.userId,
			displayName: newUser.value.displayName,
			role: newUser.value.role
		})

		if (response.data.success) {
			users.value.push(response.data.data)
			newUser.value = { userId: '', displayName: '', role: 'member' }
		}
	} catch (error) {
		console.error('Failed to add user:', error)
	} finally {
		saving.value = false
	}
}

const updateUserRole = async (user: User, event: Event): Promise<void> => {
	const target = event.target as HTMLSelectElement
	const newRole = target.value

	try {
		const response = await axios.put(`/api/v1/user-stats/update-role/${user.nextcloudUserId}`, {
			role: newRole
		})

		if (response.data.success) {
			user.role = newRole
		}
	} catch (error) {
		console.error('Failed to update user role:', error)
	}
}

const confirmRemoveUser = (user: User): void => {
	userToRemove.value = user
	showConfirmDialog.value = true
}

const removeUser = async (): Promise<void> => {
	if (!userToRemove.value) return

	try {
		const response = await axios.delete(`/api/v1/user-stats/remove-user/${userToRemove.value.nextcloudUserId}`)

		if (response.data.success) {
			users.value = users.value.filter(u => u.id !== userToRemove.value?.id)
			showConfirmDialog.value = false
			userToRemove.value = null
		}
	} catch (error) {
		console.error('Failed to remove user:', error)
	}
}

const getRoleLabel = (role: string): string => {
	const labels: Record<string, string> = {
		owner: t('chorestrike', 'Owner'),
		member: t('chorestrike', 'Member'),
		child: t('chorestrike', 'Child')
	}
	return labels[role] || role
}

const formatDate = (dateString: string): string => {
	try {
		const date = new Date(dateString)
		return date.toLocaleDateString()
	} catch {
		return dateString
	}
}

const getCurrentUserId = (): string => {
	// This would come from user store or authentication context
	return 'current-user'
}

onMounted(() => {
	loadUsers()
})
</script>

<style scoped>
.user-management {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.management-header {
	margin-bottom: 2rem;
}

.management-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.management-header p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.loading-state {
	text-align: center;
	padding: 3rem 0;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.members-container {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.members-list {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.member-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.member-info {
	flex: 1;
}

.member-name {
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.member-role {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0.25rem;
}

.member-joined {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.member-actions {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.role-select,
.role-badge {
	padding: 0.5rem;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.role-badge {
	border: none;
	background: transparent;
	padding: 0.5rem 0;
}

.delete-button {
	padding: 0.5rem 1rem;
	background: var(--color-error);
	color: white;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 0.9rem;
}

.delete-button:hover {
	background: var(--color-error-dark);
}

.add-user-section {
	margin-top: 2rem;
	padding: 1.5rem;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.add-user-section h3 {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.form-group {
	margin-bottom: 1rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.form-input {
	width: 100%;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1rem;
}

.form-input:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
}

.primary-button {
	padding: 0.75rem 1.5rem;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-weight: 600;
}

.primary-button:hover:not(:disabled) {
	background: var(--color-primary-dark);
}

.primary-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.empty-state {
	text-align: center;
	padding: 3rem 0;
}

.empty-icon {
	font-size: 3rem;
	margin-bottom: 1rem;
}

.empty-state h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.empty-state p {
	color: var(--color-text-maxcontrast);
}

.confirmation-dialog {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 1000;
}

.dialog-overlay {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
}

.dialog-content {
	position: relative;
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 2rem;
	max-width: 400px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.dialog-content h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.dialog-content p {
	color: var(--color-text-maxcontrast);
	margin-bottom: 1.5rem;
}

.dialog-actions {
	display: flex;
	gap: 1rem;
	justify-content: flex-end;
}

.secondary-button {
	padding: 0.75rem 1.5rem;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	cursor: pointer;
	font-weight: 600;
}

.secondary-button:hover {
	background: var(--color-border);
}

.danger-button {
	padding: 0.75rem 1.5rem;
	background: var(--color-error);
	color: white;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-weight: 600;
}

.danger-button:hover {
	background: var(--color-error-dark);
}
</style>
