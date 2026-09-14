import { addDaysToKey, dateKeyInZone } from '../../../src/utils/dateKeys.js'

export function houseDateOffset(days, timeZone, now = new Date()) {
	return addDaysToKey(dateKeyInZone(now, timeZone), days)
}

// The real settings UI keeps long journeys at least five hours from midnight.
export function journeyClock(now = new Date()) {
	const timeZone = now.getUTCHours() >= 6 && now.getUTCHours() < 18 ? 'UTC' : 'Pacific/Honolulu'
	return { timeZone, today: houseDateOffset(0, timeZone, now) }
}
