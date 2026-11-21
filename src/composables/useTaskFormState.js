/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { computed, reactive, ref } from 'vue'
import { useChecklistStore } from '../store/checklist.js'
import { useHouseStore } from '../store/house.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { categoryOptions, OTHER_CATEGORY, resolveCategory } from '../utils/taskCategories.js'
import { detailsOf, formFields, rhythmOf, rhythmProblem } from '../utils/taskFormModel.js'

/**
 * The state of the one form (CS-PLAN-091 WP-B6): what it shows of a task,
 * or of a new one with the list's prefill, and the fields a save sends.
 * @param {?object} item `{ roomTask, task }` of the task to edit, or null for a new one.
 * @param {object} prefill `{ roomId, floorId, assigneeIds }` for a new task.
 * @return {object}
 */
export function useTaskFormState(item, prefill) {
	const houseStore = useHouseStore()
	const checklistStore = useChecklistStore()
	const roomTasksStore = useRoomTasksStore()
	const task = item?.task ?? null
	const roomTask = item?.roomTask ?? null

	// The household's list (D-090-65); a stored key it no longer holds
	// shows and saves as Other once the list is loaded (RF-090-34).
	const categoriesLoaded = computed(() => Boolean(houseStore.house?.taskCategories))
	const categoryOf = (key) => (categoriesLoaded.value ? resolveCategory(key, houseStore.taskCategories.items) : (key || OTHER_CATEGORY))

	const details = reactive(detailsOf(task, categoryOf))
	// What the form opened with: a save sends only what differs from it.
	const storedDetails = task ? { ...details } : null
	// A stored key the not yet loaded list lacks stays selectable.
	const categories = computed(() => {
		const choices = categoryOptions(houseStore.taskCategories.items)
		return choices.some((choice) => choice.value === details.category) ? choices : [...choices, { value: details.category, label: details.category }]
	})
	const rhythm = ref(rhythmOf(task))
	const storedRhythm = task ? rhythmOf(task) : null
	const roomId = ref(roomTask?.roomId ?? prefill.roomId ?? null)
	const assigneeUserIds = ref([...(roomTask?.assignees ?? prefill.assigneeIds ?? [])])
	const subTasks = ref(checklistStore.getTaskItems(task?.id).map((entry) => ({ id: entry.id, label: entry.label, key: `item-${entry.id}` })))
	// Sub-tasks that could not be read are unknown, not empty: a save then
	// leaves them as they are instead of deleting them.
	let subTasksKnown = !task || Boolean(checklistStore.items[task.id])
	const subTasksFailed = ref(false)
	// D-091-19: the first date moves the window until the first completion.
	const firstDateEditable = !roomTask?.lastCompleted
	const firstDate = ref(roomTask?.anchorOn ?? '')
	const cycle = roomTask?.cycle ?? null
	const sleeping = ref(cycle?.state === 'asleep')
	const wakeOn = ref(cycle?.wakeOn ?? '')

	/** How many rooms share the definition, as far as the loaded list knows. */
	const sharedRooms = computed(() => (task ? roomTasksStore.roomTasks.filter((entry) => entry.taskId === task.id).length : 0))

	const valid = computed(() => details.name.trim() !== '' && roomId.value !== null && rhythmProblem(rhythm.value, storedRhythm) === null)

	/**
	 * Load the sub-tasks of the task being edited, unless they are cached.
	 * Sub-tasks added while they load stay after the loaded ones; a failed
	 * read shows its retry instead of the list (the store logs it).
	 * @return {Promise<void>}
	 */
	async function loadSubTasks() {
		if (!task || checklistStore.items[task.id]) {
			return
		}
		subTasksFailed.value = false
		try {
			const items = await checklistStore.fetchTaskItems(task.id)
			const added = subTasks.value.filter((entry) => entry.id === undefined)
			subTasks.value = [...items.map((entry) => ({ id: entry.id, label: entry.label, key: `item-${entry.id}` })), ...added]
			subTasksKnown = true
		} catch {
			subTasksFailed.value = true
		}
	}

	/**
	 * A template fills the form (FEAT-UX-043); room and people stay.
	 * @param {{details: object, rhythm: object}} fill What the template brings.
	 */
	function fill(fill) {
		Object.assign(details, fill.details, { category: categoryOf(fill.details.category) })
		rhythm.value = fill.rhythm
	}

	/** @return {object} The fields of the save; `checklistItems` is null while the sub-tasks are unknown. */
	function fields() {
		const out = formFields({
			details,
			storedDetails,
			rhythm: rhythm.value,
			storedRhythm,
			storedMode: task?.recurrenceMode ?? null,
			roomId: roomId.value,
			assigneeUserIds: assigneeUserIds.value,
			subTasks: subTasks.value.filter((entry) => entry.label.trim() !== '').map((entry) => ({ ...entry, label: entry.label.trim() })),
			firstDate: firstDate.value,
			storedFirstDate: roomTask?.anchorOn ?? '',
			firstDateEditable,
			sleeping: sleeping.value,
			wakeOn: wakeOn.value,
		})
		return subTasksKnown ? out : { ...out, checklistItems: null }
	}

	return {
		task,
		roomTask,
		cycle,
		details,
		rhythm,
		storedRhythm,
		roomId,
		assigneeUserIds,
		subTasks,
		subTasksFailed,
		firstDate,
		firstDateEditable,
		sleeping,
		wakeOn,
		categories,
		sharedRooms,
		valid,
		loadSubTasks,
		fill,
		fields,
	}
}
