import { hasIcon } from './icons.js'

/**
 * Default icon registry key per room type (CS-PLAN-027 room-type catalog).
 * Kept in sync with lib/Templates/RoomTypes.json. Used by the setup wizard
 * and the template picker to pick an icon from a room type.
 */
export const ROOM_TYPE_TO_ICON = {
	kitchen: 'Utensils',
	bathroom: 'Bath',
	bedroom: 'Bed',
	living: 'Sofa',
	kids: 'Baby',
	office: 'Briefcase',
	hallway: 'DoorOpen',
	household: 'WashingMachine',
	basement: 'Package',
	outdoor: 'Trees',
	generic: 'Home',
}

/**
 * Resolve the icon for a room type, falling back to 'Home'.
 *
 * @param {string} roomType Room-type id
 * @return {string} A valid icon registry key
 */
export function iconForRoomType(roomType) {
	const icon = ROOM_TYPE_TO_ICON[roomType]
	if (icon && hasIcon(icon)) {
		return icon
	}
	return 'Home'
}

// ROOM_ICON_OPTIONS + getRoomIconOption were PRUNED in CS-PLAN-055: the
// room picker is text-driven via resolveRoomIcon; the option list had no
// consumers outside its own spec (see setup catalog RoomTypes for the
// type-to-icon source).

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
		if (rule.keywords.some((keyword) => normalizedName.includes(keyword))) {
			return rule.icon
		}
	}

	return 'Home'
}
