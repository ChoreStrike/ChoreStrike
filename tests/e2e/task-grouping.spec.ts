import { expect, test } from '@playwright/test'
import { createRoomTask, expectRoomOpen, listRooms, openRoom, roomGroup } from './fixtures/rooms.ts'

/**
 * E2E test: the task list grouped by due date, by room or by person
 * (CS-PLAN-090 WP-C). The room page went into the list by room; this spec
 * took over its scenarios (room facts, *New* with the template picker) and
 * adds the switch and the date dialog, which lost its page with the room
 * view. The family seed guarantees floors, rooms and tasks.
 */

test.describe('Task grouping', () => {
	test.beforeEach(() => {
		test.setTimeout(60000)
	})

	test('The switch groups by room and by person and mirrors the grouping in the address', async ({ page }) => {
		await page.goto('/apps/chorestrike/#/tasks')
		await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
		await expect(page.getByTestId('tasks-group-due')).toHaveAttribute('aria-pressed', 'true')
		await expect(page.getByTestId('room-task-list')).toBeVisible({ timeout: 15000 })

		await page.getByTestId('tasks-group-room').click()
		await expect(page).toHaveURL(/group=room/)
		// A grouped list shows everyone's tasks (D-090-41).
		await expect(page.getByTestId('tasks-filter-all')).toHaveAttribute('aria-pressed', 'true')
		const rooms = await listRooms(page)
		for (const room of rooms) {
			await expect(roomGroup(page, room).getByTestId('task-group-name')).toHaveText(room.name)
		}
		// Rows render when a room opens.
		await expect(page.getByTestId('task-item')).toHaveCount(0)
		await expect(page.getByTestId('task-pager')).toHaveCount(0)

		await roomGroup(page, rooms[0]).getByTestId('task-group-toggle').click()
		await expect(page).toHaveURL(new RegExp(`room=${rooms[0].id}`))
		await expectRoomOpen(page, rooms[0])

		await page.getByTestId('tasks-group-person').click()
		await expect(page).toHaveURL(/group=person/)
		await expect(page).not.toHaveURL(/room=/)
		await expect(page.getByTestId('task-group-name').first()).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('room-add-task-button')).toHaveCount(0)

		await page.getByTestId('tasks-group-due').click()
		await expect(page).not.toHaveURL(/group=/)
		await expect(page.getByTestId('task-group-list')).toHaveCount(0)
	})

	// Successor of "Room detail displays room information" and "Room
	// cleanliness value is displayed correctly" of the room page.
	test('A room heading shows its open tasks and its cleanliness', async ({ page }) => {
		const room = await openRoom(page)
		const group = roomGroup(page, room)

		await expect(group.getByTestId('task-group-open')).toHaveText(/^\d+ open$/)
		const cleanliness = await group.getByTestId('cleanliness-value').textContent()
		expect(cleanliness).toMatch(/^\d+%$/)
		const value = parseInt(cleanliness ?? '', 10)
		expect(value).toBeGreaterThanOrEqual(0)
		expect(value).toBeLessThanOrEqual(100)

		// The open count is the number of the room's open rows.
		const open = parseInt(await group.getByTestId('task-group-open').textContent() ?? '', 10)
		await expect(group.locator('[data-testid="task-item"]:not(.completed)')).toHaveCount(open)
	})

	// Successor of "User can open add task modal" of the room page.
	test('New in a room heading opens the template picker for that room', async ({ page }) => {
		const room = await openRoom(page)

		await roomGroup(page, room).getByTestId('room-add-task-button').click()
		await expect(page.getByTestId('add-task-picker')).toBeVisible({ timeout: 15000 })

		// The custom form is one step behind, locked to the room.
		await page.getByTestId('picker-tab-custom').click()
		await expect(page.getByTestId('task-name-input')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('room-picker-locked')).toBeVisible()
	})

	// D-090-12: the dialog for the due date and "Last done" opens from the
	// expanded row, and the room heading shows "Last cleaned".
	test('Date in an expanded row records the last done day, shown as Last cleaned', async ({ page }) => {
		const rooms = await listRooms(page)
		// The last room: the pool's other specs work in the first ones.
		const room = rooms[rooms.length - 1]
		const name = await createRoomTask(page, room.id, 'Grouping Date Probe')

		await page.goto(`/apps/chorestrike/#/tasks?group=room&room=${room.id}`)
		await expectRoomOpen(page, room)
		const row = roomGroup(page, room).getByTestId('task-item').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 15000 })
		await row.getByRole('button', { name: 'Show details', exact: true }).click()
		await row.getByTestId('task-date-button').click()

		const dialog = page.getByTestId('room-task-edit-modal')
		await expect(dialog).toBeVisible({ timeout: 15000 })
		await expect(dialog.getByTestId('room-task-due-input')).toBeVisible()
		const today = new Date().toISOString().slice(0, 10)
		await dialog.getByTestId('room-task-last-done-input').fill(today)
		const recorded = page.waitForResponse((response) => response.request().method() === 'PUT'
			&& new URL(response.url()).pathname.endsWith('/last-done'))
		await dialog.getByTestId('room-task-edit-save').click()
		expect((await recorded).status()).toBe(200)

		await expect(dialog).toHaveCount(0, { timeout: 15000 })
		await expect(roomGroup(page, room).getByTestId('room-last-cleaned')).toContainText('Last cleaned', { timeout: 15000 })
	})
})
