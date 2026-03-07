import { hasIcon } from './icons.js'

/**
 * Predefined room icon options for room configuration and defaults.
 *
 * The list intentionally contains more choices than currently needed so the UI
 * can stay flexible without requiring additional asset work for every new room.
 */
export const ROOM_ICON_OPTIONS = [
	{ value: 'Bed', label: 'Bedroom' },
	{ value: 'Bath', label: 'Bathroom' },
	{ value: 'Utensils', label: 'Kitchen' },
	{ value: 'Sofa', label: 'Living Room' },
	{ value: 'Shirt', label: 'Laundry' },
	{ value: 'Briefcase', label: 'Office' },
	{ value: 'BookOpen', label: 'Study' },
	{ value: 'Gamepad2', label: 'Playroom' },
	{ value: 'Dumbbell', label: 'Gym' },
	{ value: 'Tv', label: 'Media Room' },
	{ value: 'Package', label: 'Storage' },
	{ value: 'Car', label: 'Garage' },
	{ value: 'Trees', label: 'Garden' },
	{ value: 'WashingMachine', label: 'Utility Room' },
	{ value: 'DoorOpen', label: 'Hallway' },
	{ value: 'Baby', label: 'Kids Room' },
	{ value: 'Users', label: 'Family Area' },
	{ value: 'Sparkles', label: 'Cleaning Zone' },
	{ value: 'Home', label: 'Home' },
	{ value: 'House', label: 'House' },
]

const ROOM_NAME_ICON_RULES = [
	{ icon: 'Utensils', keywords: ['kitchen', 'küche', 'cook', 'dining', 'essen'] },
	{ icon: 'Bath', keywords: ['bath', 'bad', 'wc', 'toilet', 'shower'] },
	{ icon: 'Bed', keywords: ['bed', 'sleep', 'schlaf', 'guest'] },
	{ icon: 'Baby', keywords: ['kid', 'child', 'kinder', 'baby', 'nursery'] },
	{ icon: 'Sofa', keywords: ['living', 'wohn', 'sofa', 'lounge'] },
	{ icon: 'Tv', keywords: ['tv', 'media', 'cinema'] },
	{ icon: 'Gamepad2', keywords: ['game', 'play', 'spiel'] },
	{ icon: 'Briefcase', keywords: ['office', 'büro', 'desk', 'work'] },
	{ icon: 'BookOpen', keywords: ['study', 'library', 'book', 'lesen'] },
	{ icon: 'Shirt', keywords: ['laundry', 'wash', 'wäsche'] },
	{ icon: 'WashingMachine', keywords: ['utility', 'storage wash'] },
	{ icon: 'Car', keywords: ['garage', 'car', 'auto'] },
	{ icon: 'Trees', keywords: ['garden', 'yard', 'outdoor', 'terrace', 'balkon'] },
	{ icon: 'DoorOpen', keywords: ['hall', 'entry', 'entrance', 'flur'] },
	{ icon: 'Package', keywords: ['storage', 'basement', 'keller', 'attic'] },
]

/**
 * Resolve a valid room icon name from an explicit value or room name fallback.
 *
 * @param {?string} iconName The persisted icon name.
 * @param {?string} roomName The room display name used for defaults.
 * @return {string} A valid icon registry key.
 */
export function resolveRoomIcon(iconName, roomName = '') {
	if (iconName && hasIcon(iconName)) {
		return iconName
	}

	const normalizedName = String(roomName || '').toLowerCase()
	for (const rule of ROOM_NAME_ICON_RULES) {
		if (rule.keywords.some(keyword => normalizedName.includes(keyword))) {
			return rule.icon
		}
	}

	return 'Home'
}

/**
 * Find metadata for a room icon option.
 *
 * @param {string} iconName Icon registry key.
 * @return {{ value: string, label: string }|undefined} Matching icon option.
 */
export function getRoomIconOption(iconName) {
	return ROOM_ICON_OPTIONS.find(option => option.value === iconName)
}
