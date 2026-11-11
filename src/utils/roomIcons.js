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
