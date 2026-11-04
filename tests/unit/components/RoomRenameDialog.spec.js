import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { showError, showSuccess } from '@nextcloud/dialogs'
import RoomRenameDialog from '../../../src/components/Common/RoomRenameDialog.vue'

const mockRoomsStore = { updateRoomName: jest.fn() }

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((result, [key, value]) => result.split(`{${key}}`).join(String(value)), text)),
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('../../../src/utils/logger.js', () => ({
	logger: { error: jest.fn() },
}))
jest.mock('../../../src/store/rooms.js', () => ({
	useRoomsStore: () => mockRoomsStore,
}))
jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	template: '<div class="modal-stub"><slot /></div>',
}))

const mountDialog = () => mount(RoomRenameDialog, { props: { room: { id: 7, name: 'Kitchen' } } })
const input = (wrapper) => wrapper.get('[data-testid="room-rename-input"]')
const save = (wrapper) => wrapper.get('[data-testid="room-rename-save"]')

describe('RoomRenameDialog', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		mockRoomsStore.updateRoomName.mockResolvedValue({ id: 7, name: 'Pantry' })
	})

	it('starts with the current name and nothing to save', () => {
		const wrapper = mountDialog()

		expect(input(wrapper).element.value).toBe('Kitchen')
		expect(save(wrapper).attributes('disabled')).toBeDefined()
	})

	it('saves the trimmed name and closes', async () => {
		const wrapper = mountDialog()
		await input(wrapper).setValue('  Pantry  ')
		await save(wrapper).trigger('click')
		await flushPromises()

		expect(mockRoomsStore.updateRoomName).toHaveBeenCalledWith(7, 'Pantry')
		expect(showSuccess).toHaveBeenCalledWith('Room renamed')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('names the numbered result when the name was taken', async () => {
		mockRoomsStore.updateRoomName.mockResolvedValue({ id: 7, name: 'Pantry 2' })
		const wrapper = mountDialog()
		await input(wrapper).setValue('Pantry')
		await save(wrapper).trigger('click')
		await flushPromises()

		expect(showSuccess).toHaveBeenCalledWith('Name already taken — room renamed to "Pantry 2"')
	})

	it('keeps the draft open when the rename fails', async () => {
		mockRoomsStore.updateRoomName.mockRejectedValue(new Error('refused'))
		const wrapper = mountDialog()
		await input(wrapper).setValue('Pantry')
		await save(wrapper).trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Failed to rename room')
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(input(wrapper).element.value).toBe('Pantry')
	})

	it('saves on Enter and closes on Cancel', async () => {
		const wrapper = mountDialog()
		await input(wrapper).setValue('Pantry')
		await input(wrapper).trigger('keyup.enter')
		await flushPromises()

		expect(mockRoomsStore.updateRoomName).toHaveBeenCalledTimes(1)

		await wrapper.get('.secondary-button').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
